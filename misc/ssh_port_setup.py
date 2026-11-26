#!/usr/bin/env python3
"""Privately prompt for an SSH port, install decoys, and require a new-login confirmation."""
import fcntl
import getpass
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import shutil
import socket
import stat
import subprocess
import sys
import time

import ssh_google_window as window
import ssh_honeypot_route as route

CONF = Path('/etc/tarasecfw.conf')
SSHD_RUNTIME = Path('/run/sshd')
STATE = Path('/var/lib/tarasec/ssh-port-migration')
LIB = Path('/usr/local/lib/tarasec')
SSHD = Path('/etc/ssh/sshd_config.d/90-tarasec.conf')
SOCKET = Path('/etc/systemd/system/ssh.socket.d/90-tarasec-port.conf')
HP = Path('/etc/systemd/system/tarasec-ssh-honeypot.service.d/90-dual-stack.conf')
UNIT = Path('/etc/systemd/system/tarasec-ssh-decoys.service')
SYSTEMD = Path('/etc/systemd/system')
SSH_SERVICE_DROPINS = (SYSTEMD / 'ssh.service.d/90-tarasec-decoys.conf',
                       SYSTEMD / 'sshd.service.d/90-tarasec-decoys.conf')
ROLLBACK = 'tarasec-ssh-port-rollback'


def command(*args, check=True, input=None):
    return route.run(*args, check=check, input=input)


def atomic(path, text, mode=0o600):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + '.tarasec-new')
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        with os.fdopen(fd, 'w') as handle:
            handle.write(text)
            handle.flush()
            os.fsync(handle.fileno())
        if path.exists():
            info = path.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
                raise ValueError('An existing configuration file has unsafe ownership or permissions')
            shutil.copystat(path, temporary)
            os.chown(temporary, info.st_uid, info.st_gid)
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def config_text(text, values):
    for key, value in values.items():
        if not re.fullmatch(r'[A-Z_]+', key) or not re.fullmatch(r'[0-9A-Za-z, :._/-]+', str(value)):
            raise ValueError('Invalid configuration value')
        pattern = r'^[ \t]*' + key + r'[ \t]*=[^\n]*(?:\n|$)'
        if len(re.findall(pattern, text, re.M)) > 1:
            raise ValueError('Duplicate SSH configuration assignments; resolve them before migration')
        text, count = re.subn(pattern, lambda _: f'{key}="{value}"\n', text, flags=re.M)
        if not count:
            text = text.rstrip('\n') + f'\n{key}="{value}"\n'
    return text


def root_file(path):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError('Configuration must be a root-owned regular file without group/other write permission')
    return path.read_text()


def socket_mode():
    return (command('systemctl', 'is-active', '--quiet', 'ssh.socket', check=False).returncode == 0
            or command('systemctl', 'is-enabled', '--quiet', 'ssh.socket', check=False).returncode == 0)


def ssh_service_name():
    for name in ('ssh.service', 'sshd.service'):
        if command('systemctl', 'show', name, '-p', 'LoadState', '--value', check=False).stdout.strip() == 'loaded':
            return name
    raise RuntimeError('No supported systemd SSH service was found')


def socket_config(port, listeners):
    entries = set()
    for line in listeners.splitlines():
        fields = line.split()
        if len(fields) < 4:
            continue
        host = fields[3].rsplit(':', 1)[0]
        if not re.fullmatch(r'[A-Za-z0-9.:%_\[\]*-]+', host):
            raise ValueError('Unsupported SSH socket bind address; retain the existing configuration')
        entries.add(str(port) if host == '*' else host + ':' + str(port))
    if not entries:
        raise ValueError('Cannot determine existing SSH socket bind addresses')
    return '[Socket]\nListenStream=\n' + ''.join('ListenStream=' + entry + '\n' for entry in sorted(entries))


def sshd_port_text(text, port):
    # This file can contain owner authentication policy as well as our Port.
    # Change only Port directives; keep keys/passwords/Match policy intact.
    kept = re.sub(r'(?im)^[ \t]*Port[ \t]+[^\n]*(?:\n|$)', '', text)
    return '# Management port set by TaraSec\nPort ' + str(port) + '\n' + kept


def ensure_sshd_runtime():
    SSHD_RUNTIME.mkdir(mode=0o755, parents=True, exist_ok=True)
    info = SSHD_RUNTIME.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError('Unsafe SSH privilege separation directory')


def reload_sshd():
    ensure_sshd_runtime()
    command('sshd', '-t')
    command('systemctl', 'daemon-reload')
    service = ssh_service_name()
    if command('systemctl', 'show', service, '-p', 'KillMode', '--value').stdout.strip() != 'process':
        raise RuntimeError('SSH service KillMode=process is required to preserve established sessions')
    if socket_mode():
        command('systemctl', 'restart', 'ssh.socket')
        command('systemctl', 'restart', service)
    else:
        command('systemctl', 'reload', service)


def listener(port):
    return bool(command('ss', '-H', '-ltn', f'sport = :{port}').stdout.strip())


def guarded_snapshot(text, ports):
    """Restore files must never briefly reopen a previously active MFA window."""
    lines = text.splitlines()
    ports = [ports] if isinstance(ports, int) else sorted(set(ports))
    table = None
    result = []
    for line in lines:
        if line.startswith('*'):
            table = line
        if line == 'COMMIT' and table == '*filter':
            for port in ports:
                result.append(f'-I INPUT 1 -p tcp --dport {port} -m conntrack --ctstate NEW '
                              '-m comment --comment tarasec-port-rollback-guard -j REJECT --reject-with tcp-reset')
        result.append(line)
    if not any('tarasec-port-rollback-guard' in line for line in result):
        raise RuntimeError('Cannot snapshot the filter table safely')
    return '\n'.join(result) + '\n'


def snapshot(new_port):
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = STATE.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
        raise ValueError('Unsafe migration state directory')
    pending = STATE / 'pending.json'
    if pending.exists():
        previous = json.loads(root_file(pending))
        if previous.get('phase') == 'complete_console':
            os.replace(pending, STATE / 'last-console-migration.json')
        else:
            raise RuntimeError('An unfinished port migration exists. Use --recover-current with console recovery, or --rollback before starting another')
    saved = STATE / ('backup-' + str(time.time_ns()))
    saved.mkdir(mode=0o700)
    entries = []
    for i, path in enumerate([CONF, SSHD, SOCKET, HP, UNIT, Path(window.STATE), *SSH_SERVICE_DROPINS]):
        present = path.exists()
        if present:
            root_file(path)
            shutil.copy2(path, saved / str(i))
        entries.append({'path': str(path), 'present': present, 'backup': str(saved / str(i))})
    for tool in ('iptables', 'ip6tables'):
        atomic(saved / (tool + '.rules'), guarded_snapshot(command(tool + '-save').stdout, [window.configuration()[0], new_port]))
    for name in route.SETS.values():
        result = command('ipset', 'save', name, check=False)
        atomic(saved / name, result.stdout if result.returncode == 0 else '')
    atomic(saved / 'manifest.json', json.dumps(entries))
    honeypot_active = command('systemctl', 'is-active', '--quiet', 'tarasec-ssh-honeypot.service',
                              check=False).returncode == 0
    atomic(STATE / 'pending.json', json.dumps({'backup': str(saved), 'started': time.time(),
           'new_port': new_port, 'phase': 'staging', 'honeypot_active': honeypot_active}))
    return saved


def arm():
    atomic(SYSTEMD / (ROLLBACK + '.service'),
           '[Unit]\nDescription=Restore previous SSH port and firewall after unconfirmed migration\n'
           '[Service]\nType=oneshot\nExecStart=/usr/bin/python3 ' + str(LIB / 'ssh_port_setup.py') + ' --rollback\n', 0o644)
    atomic(SYSTEMD / (ROLLBACK + '.timer'),
           '[Unit]\nDescription=Rollback unconfirmed SSH port change\n[Timer]\nOnActiveSec=10min\n'
           'AccuracySec=1s\n[Install]\nWantedBy=timers.target\n', 0o644)
    command('systemctl', 'daemon-reload')
    command('systemctl', 'enable', '--now', ROLLBACK + '.timer')


def rollback():
    pending = STATE / 'pending.json'
    if not pending.exists():
        return
    state = json.loads(root_file(pending))
    saved = Path(state['backup'])
    if saved.parent != STATE or not saved.name.startswith('backup-'):
        raise ValueError('Invalid migration backup')
    state['phase'] = 'rollback'
    atomic(pending, json.dumps(state))
    ensure_sshd_runtime()
    command('systemctl', 'disable', '--now', 'tarasec-ssh-decoys.service', check=False)
    # Restore NAT/filter first, then listener/configuration and previous window state.
    for tool in ('iptables', 'ip6tables'):
        command(tool + '-restore', input=root_file(saved / (tool + '.rules')))
    for name in route.SETS.values():
        command('ipset', 'destroy', name + '_next', check=False)
        command('ipset', 'flush', name, check=False)
        command('ipset', 'destroy', name, check=False)
        text = root_file(saved / name)
        if text:
            command('ipset', 'restore', '-exist', input=text)
    for entry in json.loads(root_file(saved / 'manifest.json')):
        path = Path(entry['path'])
        if entry['present']:
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(entry['backup'], path)
        else:
            path.unlink(missing_ok=True)
    if state.get('honeypot_active') is False:
        command('systemctl', 'stop', 'tarasec-ssh-honeypot.service', check=False)
    reload_sshd()
    if state.get('honeypot_active') is not False:
        command('systemctl', 'restart', 'tarasec-ssh-honeypot.service', check=False)
    # Rollback restores the previous port, never a stale open window. The snapshot
    # guard remains until closed rules have been reapplied in both families.
    port, sources = window.configuration()
    window.write_state({'id': '0' * 32, 'until': 0, 'port': port, 'closed_at': int(time.time())})
    window.reconcile()
    for tool in ('iptables', 'ip6tables'):
        for guarded_port in {port, int(state['new_port'])}:
            guard = ['-p', 'tcp', '--dport', str(guarded_port), '-m', 'conntrack', '--ctstate', 'NEW',
                     '-m', 'comment', '--comment', 'tarasec-port-rollback-guard', '-j', 'REJECT', '--reject-with', 'tcp-reset']
            while command(tool, '-w', '5', '-C', 'INPUT', *guard, check=False).returncode == 0:
                command(tool, '-w', '5', '-D', 'INPUT', *guard)
    if UNIT.exists():
        command('systemctl', 'enable', 'tarasec-ssh-decoys.service')
    pending.unlink()
    command('systemctl', 'disable', '--now', ROLLBACK + '.timer', check=False)
    print('Previous SSH port and firewall restored. Next: reconnect using the previous settings.')


def clear_rollback_guard(port):
    for tool in ('iptables', 'ip6tables'):
        guard = ['-p', 'tcp', '--dport', str(port), '-m', 'conntrack', '--ctstate', 'NEW',
                 '-m', 'comment', '--comment', 'tarasec-port-rollback-guard', '-j', 'REJECT', '--reject-with', 'tcp-reset']
        while command(tool, '-w', '5', '-C', 'INPUT', *guard, check=False).returncode == 0:
            command(tool, '-w', '5', '-D', 'INPUT', *guard)


def recover_current():
    """Keep current configuration and resolve only the current port's stale guard."""
    ensure_sshd_runtime()
    command('sshd', '-t')
    port, _ = window.configuration()
    ports = {int(line.split()[1]) for line in command('sshd', '-T').stdout.splitlines() if line.startswith('port ')}
    if ports != {port} or not listener(port):
        raise RuntimeError('Current SSH configuration/listener does not match local policy; repair it from the console first')
    # Stop any old recovery job before marking its state resolved. Never restore
    # snapshots here, and keep unused-port guards in case an old socket survives.
    command('systemctl', 'disable', '--now', ROLLBACK + '.timer', check=False)
    command('systemctl', 'stop', ROLLBACK + '.service', check=False)
    window.write_state({'id': '0' * 32, 'until': 0, 'port': port, 'closed_at': int(time.time())})
    window.reconcile()
    clear_rollback_guard(port)
    pending_path = STATE / 'pending.json'
    if pending_path.exists():
        pending = json.loads(root_file(pending_path))
        pending.update({'phase': 'complete_console', 'automatic_rollback': False})
        atomic(pending_path, json.dumps(pending))
    print('Current SSH settings retained; stale guard cleared and automatic rollback stopped.')
    print('Next: open a fresh MFA window and test a new connection.')


def confirm():
    fields = os.environ.get('SSH_CONNECTION', '').split()
    port = window.configuration()[0]
    if len(fields) != 4 or fields[3] != str(port):
        raise RuntimeError('Confirm from a NEW real SSH login on the new port; preserve SSH_CONNECTION through sudo')
    pending = STATE / 'pending.json'
    if not pending.exists():
        raise RuntimeError('There is no pending port migration')
    command('systemctl', 'disable', '--now', ROLLBACK + '.timer')
    pending.unlink()
    print('New SSH login confirmed; rollback cancelled. Keep using MFA before new SSH connections.')


def ensure_migration_dependencies():
    for program in ('iptables', 'ip6tables', 'iptables-restore', 'ip6tables-restore',
                    'ss', 'sshd', 'systemctl', 'journalctl'):
        if not shutil.which(program):
            raise RuntimeError('Missing migration dependency: ' + program)
    packages = []
    if not shutil.which('ipset'):
        packages.append('ipset')
    if command('/usr/bin/python3', '-c', 'import paramiko', check=False).returncode:
        packages.append('python3-paramiko')
    if packages:
        apt_get = shutil.which('apt-get')
        if not apt_get:
            raise RuntimeError('Install the missing migration packages: ' + ', '.join(packages))
        print('Installing SSH migration dependencies: ' + ', '.join(packages), flush=True)
        subprocess.run([apt_get, 'update'], check=True)
        subprocess.run([apt_get, 'install', '-y', *packages], check=True)
    if not shutil.which('ipset') or command('/usr/bin/python3', '-c', 'import paramiko', check=False).returncode:
        raise RuntimeError('SSH migration requires ipset and Python Paramiko')


def print_ssh_opening_steps(cfg, port):
    policy = window.settings(window.POLICY)
    api = policy.get('AGENT_APPROVAL_URL') or 'https://tarasec.org/ops/agent/api.php'
    url = api.rsplit('/', 1)[0] + '/'
    nickname = cfg.get('AGENT_PUBLIC_NICKNAME') or 'the machine you enrolled'
    print('\nApprove a temporary SSH opening')
    print(f'  1. Open {url} and select {nickname}.')
    print('  2. Choose a duration and click Open SSH temporarily.')
    print('  3. Enter a fresh six-digit Authenticator code and click Approve on the request.')
    print(f'  4. When {nickname} starts counting down, test from a second terminal on your workstation.')
    print('     Keep this server console open until the new login is confirmed.')

    addresses = command('ip', '-4', '-o', 'addr', 'show', 'dev', 'wt0', check=False)
    fields = addresses.stdout.split()
    address = fields[3].split('/')[0] if addresses.returncode == 0 and len(fields) >= 4 else 'SERVER_ADDRESS'
    user = os.environ.get('SUDO_USER') or 'LINUX_USER'
    if user == 'root':
        user = 'LINUX_USER'
    target = shlex.quote(f'{user}@{address}')
    ssh = 'ssh -F /dev/null -o ControlMaster=no -o ControlPath=none -o ConnectTimeout=5'
    if address == 'SERVER_ADDRESS':
        print('  Replace SERVER_ADDRESS with the address you use to reach this server.')
    if user == 'LINUX_USER':
        print('  Replace LINUX_USER with your Linux account on this server.')
    print('\n  If this server accepts your Linux password:')
    print(f'    {ssh} -o PubkeyAuthentication=no -p {port} {target}')
    print('  If you use a key, or see "Too many authentication failures", select one key:')
    print(f'    {ssh} -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 -p {port} {target}')
    print('  Replace the key path if needed. Enter the Linux password too if the server requires both.')
    print('  If the connection is refused, check the countdown, address and port. Leave rollback armed if login fails.\n')


def migrate(console_recovery=False, from_install=False):
    text = root_file(CONF)
    old, sources = window.configuration()
    if not sys.stdin.isatty():
        raise RuntimeError('Run interactively in a terminal; the management port is prompted privately')
    staged = json.loads(root_file(Path('/var/lib/tarasec/ssh-install-choices.json'))) if from_install else None
    if staged:
        print('Staged Google account: ' + staged['email'])
        if input('Did you approve an SSH window with MFA and test a new login on the current port? [y/N]: ').strip().lower() not in ('y', 'yes'):
            raise RuntimeError('Complete Google-controlled SSH and MFA setup before applying these choices')
    ensure_migration_dependencies()
    ensure_sshd_runtime()
    cfg = window.settings(str(CONF))
    backend = int(cfg.get('SSH_HONEYPOT_PORT', '22'))
    service_ports = set(route.excluded_ports(cfg, old, backend))
    reserved = service_ports | {22}
    candidates = sorted(set(range(1000, 5001)) - reserved)
    entered = str(staged['port']) if staged else getpass.getpass('New management SSH port (hidden, Enter for a random free port): ')
    if not entered:
        if not candidates:
            raise RuntimeError('No available management SSH ports in 1000–5000')
        entered = str(secrets.choice(candidates))
    if not re.fullmatch(r'[0-9]{1,5}', entered) or not 1 <= int(entered) <= 65535:
        raise ValueError('Choose a port between 1 and 65535')
    port = int(entered)
    if port in reserved or (port != old and listener(port)):
        raise ValueError('Choose a management port outside the reserved and occupied TCP ports')
    current_bindings = command('ss', '-H', '-ltn', f'sport = :{old}').stdout
    default_spec = cfg.get('SSH_HONEYPOT_PORTS', '').strip()
    if not default_spec or route.ranges(default_spec) == [(backend, backend)]:
        default_spec = '22,1000-5000'
    spec = staged['range'] if staged else input('Honeypot ports/ranges [configured decoys; otherwise 1000-5000]: ').strip() or default_spec
    if not any(lo <= 22 <= hi for lo, hi in route.ranges(spec)):
        spec = '22,' + spec
        route.ranges(spec)
    if backend != 22 and old != 22 and 22 in service_ports:
        raise ValueError('TCP/22 is already reserved by another service')
    if not console_recovery and not from_install:
        console_recovery = input('Independent console available; use it instead of automatic rollback? [y/N]: ').strip().lower() in ('y', 'yes')
    if command('systemctl', 'show', ssh_service_name(), '-p', 'KillMode', '--value').stdout.strip() != 'process':
        raise RuntimeError('SSH service KillMode=process is required to preserve established sessions')
    repo = Path(__file__).resolve().parents[1]
    setup = repo / 'misc/setup_ssh_honeypot.sh'
    if not setup.exists():
        raise RuntimeError('Run initial setup from the updated taransvar checkout')
    # Install the maintained worker bundle before taking the migration lock.
    for name in ('ssh_port_setup.py', 'ssh_honeypot_route.py', 'ssh_google_window.py', 'local_manager_policy.py'):
        LIB.mkdir(parents=True, exist_ok=True)
        src = repo / 'misc' / name
        dst = LIB / name
        if src.resolve() != dst.resolve():
            shutil.copyfile(src, dst)
            os.chown(dst, 0, 0)
            dst.chmod(0o755)
    saved = snapshot(port)
    try:
        if console_recovery:
            command('systemctl', 'disable', '--now', ROLLBACK + '.timer', check=False)
            command('systemctl', 'stop', ROLLBACK + '.service', check=False)
        else:
            arm()  # Independent persistent timer is armed before any access change.
        # Freeze current windows at both ports before changing listeners or NAT.
        window.apply_rules(old, sources, 0)
        window.apply_rules(port, sources, 0)
        window.write_state({'id': '0' * 32, 'until': 0, 'port': port, 'closed_at': int(time.time())})
        updated = config_text(text, {'SSH_PORT': port, 'SSH_HONEYPOT': 'on',
                                    'SSH_HONEYPOT_PORTS': spec, 'SSH_CLOSED_HONEYPOT': 'yes'})
        if staged:
            updated = config_text(updated, {'SSH_ALLOWED_SOURCES': ','.join(staged['sources'])})
        atomic(CONF, updated)
        atomic(SSHD, sshd_port_text(root_file(SSHD) if SSHD.exists() else '', port), 0o644)
        if socket_mode():
            previous = root_file(SOCKET) if SOCKET.exists() else ''
            retained = re.sub(r'(?m)^[ \t]*ListenStream[ \t]*=[^\n]*(?:\n|$)', '', previous)
            atomic(SOCKET, socket_config(port, current_bindings) + retained, 0o644)
        sshd_fields = command('sshd', '-T').stdout.splitlines()
        effective = {int(line.split()[1]) for line in sshd_fields if line.startswith('port ')}
        if effective != {port}:
            raise RuntimeError('Other SSH Port directives conflict with the new listener. Resolve them before migrating')
        if any(line.startswith('listenaddress ') and line.rsplit(':', 1)[-1] != str(port) for line in sshd_fields):
            raise RuntimeError('A ListenAddress directive has a fixed conflicting port; resolve it before migrating')
        reload_sshd()
        for _ in range(50):
            if listener(port):
                break
            time.sleep(0.1)
        else:
            raise RuntimeError('New SSH listener did not appear')
        atomic(HP, '[Service]\nEnvironment=TARASEC_SSH_HONEYPOT_IPV6=yes\n', 0o644)
        # Provision the backend after verifying that OpenSSH moved off its port.
        # This path skips the older temporary-ACCEPT migration.
        command('bash', str(setup), '--start-after-migration')
        command('systemctl', 'is-active', '--quiet', 'tarasec-ssh-honeypot.service')
        if not listener(backend):
            raise RuntimeError('The honeypot backend is not listening')
        atomic(UNIT, '[Unit]\nDescription=TaraSec closed-port SSH decoys\n'
               'After=netfilter-persistent.service tarasec-ssh-honeypot.service\n'
               'Before=ssh.service sshd.service\nWants=tarasec-ssh-honeypot.service\n'
               '[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/bin/python3 '
               + str(LIB / 'ssh_port_setup.py') + ' --boot\n[Install]\nWantedBy=multi-user.target\n', 0o644)
        window.reconcile()
        clear_rollback_guard(port)
        for path in SSH_SERVICE_DROPINS:
            atomic(path, '[Unit]\nRequires=tarasec-ssh-decoys.service\nAfter=tarasec-ssh-decoys.service\n', 0o644)
        # Remove only stale hooks to the owned gate for the old management port.
        if old != port:
            for tool in ('iptables', 'ip6tables'):
                rule = ['-p', 'tcp', '--dport', str(old), '-m', 'conntrack', '--ctstate', 'NEW', '-j', window.CHAIN]
                while command(tool, '-w', '5', '-C', 'INPUT', *rule, check=False).returncode == 0:
                    command(tool, '-w', '5', '-D', 'INPUT', *rule)
        command('systemctl', 'daemon-reload')
        command('systemctl', 'enable', 'tarasec-ssh-decoys.service')
        pending_path = STATE / 'pending.json'
        pending = json.loads(root_file(pending_path))
        pending['phase'] = 'complete_console' if console_recovery else 'awaiting_confirmation'
        pending['automatic_rollback'] = not console_recovery
        atomic(pending_path, json.dumps(pending))
        if console_recovery:
            print('SSH port and honeypot routing configured. Console recovery selected; automatic rollback is disabled.')
            print_ssh_opening_steps(cfg, port)
            print('No timer confirmation is required.')
            return
        print(f'SSH port TCP/{port} is now listening. Rollback is armed for ten minutes.')
        print_ssh_opening_steps(cfg, port)
        print('From that new login, confirm with:')
        print('sudo --preserve-env=SSH_CONNECTION python3 /usr/local/lib/tarasec/ssh_port_setup.py --confirm')
        print('Without confirmation, the previous port and firewall are restored automatically.')
    except BaseException as error:
        if isinstance(error, route.DecoyCommandError):
            print(f'SSH migration command failed: {error.tool} (exit {error.status})', file=sys.stderr)
            if error.detail:
                print(error.detail, file=sys.stderr)
        if console_recovery:
            pending_path = STATE / 'pending.json'
            pending = json.loads(root_file(pending_path))
            pending.update({'phase': 'console_recovery_required', 'automatic_rollback': False})
            atomic(pending_path, json.dumps(pending))
            print('Setup incomplete. Settings retained for console repair; no automatic rollback was performed.')
            print('After repairing SSH, use --recover-current; use --rollback only if you want the previous configuration restored.')
        else:
            rollback()
        raise


def main():
    if os.geteuid() != 0:
        raise SystemExit('Run with sudo on the server whose SSH port you are changing')
    if sys.argv[1:] not in ([], ['--confirm'], ['--rollback'], ['--boot'], ['--console-recovery'], ['--recover-current'], ['--from-install']):
        raise SystemExit('Usage: sudo python3 misc/ssh_port_setup.py [--console-recovery|--recover-current|--confirm|--rollback|--from-install]')
    Path(window.LOCK).parent.mkdir(parents=True, exist_ok=True)
    with open(window.LOCK, 'a') as lock:
        os.chmod(window.LOCK, 0o600)
        fcntl.flock(lock, fcntl.LOCK_EX)
        if sys.argv[1:] == ['--confirm']:
            confirm()
        elif sys.argv[1:] == ['--rollback']:
            rollback()
        elif sys.argv[1:] == ['--boot']:
            window.reconcile()
        elif sys.argv[1:] == ['--recover-current']:
            recover_current()
        else:
            migrate(console_recovery=sys.argv[1:] == ['--console-recovery'],
                    from_install=sys.argv[1:] == ['--from-install'])


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as error:
        raise SystemExit(str(error))

