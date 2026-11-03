#!/usr/bin/env python3
"""Privately prompt for an SSH port, install decoys, and require a new-login confirmation."""
import fcntl
import getpass
import json
import os
from pathlib import Path
import re
import shutil
import socket
import stat
import subprocess
import sys
import time

import ssh_google_window as window
import ssh_honeypot_route as route

CONF = Path('/etc/tarasecfw.conf')
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


def reload_sshd():
    command('sshd', '-t')
    command('systemctl', 'daemon-reload')
    if socket_mode():
        if command('systemctl', 'show', 'ssh.service', '-p', 'KillMode', '--value').stdout.strip() != 'process':
            raise RuntimeError('Socket-mode migration requires ssh.service KillMode=process to preserve established sessions')
        command('systemctl', 'restart', 'ssh.socket')
        command('systemctl', 'restart', 'ssh.service')
    elif command('systemctl', 'list-unit-files', 'ssh.service', check=False).returncode == 0:
        command('systemctl', 'reload', 'ssh.service')
    else:
        command('systemctl', 'reload', 'sshd.service')


def listener(port):
    return bool(command('ss', '-H', '-ltn', f'sport = :{port}').stdout.strip())


def guarded_snapshot(text, port):
    """Restore files must never briefly reopen a previously active MFA window."""
    lines = text.splitlines()
    table = None
    result = []
    for line in lines:
        if line.startswith('*'):
            table = line
        if line == 'COMMIT' and table == '*filter':
            result.append(f'-I INPUT 1 -p tcp --dport {port} -m conntrack --ctstate NEW '
                          '-m comment --comment tarasec-port-rollback-guard -j REJECT --reject-with tcp-reset')
        result.append(line)
    if not any('tarasec-port-rollback-guard' in line for line in result):
        raise RuntimeError('Cannot snapshot the filter table safely')
    return '\n'.join(result) + '\n'


def snapshot():
    if (STATE / 'pending.json').exists():
        raise RuntimeError('A port migration is awaiting confirmation. Confirm from the new login or run --rollback first')
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = STATE.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
        raise ValueError('Unsafe migration state directory')
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
        atomic(saved / (tool + '.rules'), guarded_snapshot(command(tool + '-save').stdout, window.configuration()[0]))
    for name in route.SETS.values():
        result = command('ipset', 'save', name, check=False)
        atomic(saved / name, result.stdout if result.returncode == 0 else '')
    atomic(saved / 'manifest.json', json.dumps(entries))
    atomic(STATE / 'pending.json', json.dumps({'backup': str(saved), 'started': time.time()}))
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
    command('systemctl', 'disable', '--now', 'tarasec-ssh-decoys.service', check=False)
    # Restore NAT/filter first, then listener/configuration and previous window state.
    for tool in ('iptables', 'ip6tables'):
        command(tool + '-restore', input=root_file(saved / (tool + '.rules')))
    for name in route.SETS.values():
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
    reload_sshd()
    command('systemctl', 'restart', 'tarasec-ssh-honeypot.service', check=False)
    # Rollback restores the previous port, never a stale open window. The snapshot
    # guard remains until closed rules have been reapplied in both families.
    port, sources = window.configuration()
    window.write_state({'id': '0' * 32, 'until': 0, 'port': port, 'closed_at': int(time.time())})
    window.reconcile()
    for tool in ('iptables', 'ip6tables'):
        guard = ['-p', 'tcp', '--dport', str(port), '-m', 'conntrack', '--ctstate', 'NEW',
                 '-m', 'comment', '--comment', 'tarasec-port-rollback-guard', '-j', 'REJECT', '--reject-with', 'tcp-reset']
        while command(tool, '-w', '5', '-C', 'INPUT', *guard, check=False).returncode == 0:
            command(tool, '-w', '5', '-D', 'INPUT', *guard)
    if UNIT.exists():
        command('systemctl', 'enable', 'tarasec-ssh-decoys.service')
    pending.unlink()
    command('systemctl', 'disable', '--now', ROLLBACK + '.timer', check=False)
    print('Previous SSH port and firewall restored. Next: reconnect using the previous settings.')


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


def migrate():
    text = root_file(CONF)
    old, sources = window.configuration()
    if not sys.stdin.isatty():
        raise RuntimeError('Run interactively in a terminal; the management port is prompted privately')
    entered = getpass.getpass('New management SSH port (input hidden): ')
    if not re.fullmatch(r'[0-9]{1,5}', entered) or not 1 <= int(entered) <= 65535:
        raise ValueError('Choose a port between 1 and 65535')
    port = int(entered)
    cfg = window.settings(str(CONF))
    backend = int(cfg.get('SSH_HONEYPOT_PORT', '22'))
    if port == backend:
        raise ValueError('Choose a management port different from the honeypot backend')
    if port != old and listener(port):
        raise ValueError('The chosen port is already occupied by a local TCP service')
    current_bindings = command('ss', '-H', '-ltn', f'sport = :{old}').stdout
    spec = input('Honeypot ports/ranges [configured range; default 1000-5000]: ').strip() or cfg.get('SSH_HONEYPOT_PORTS', '1000-5000')
    route.ranges(spec)  # The real port is intentionally allowed inside the range.
    for program in ('ipset', 'iptables', 'ip6tables', 'iptables-restore', 'ip6tables-restore', 'ss', 'sshd', 'systemctl', 'journalctl'):
        if not shutil.which(program):
            raise RuntimeError('Missing dependency: ' + program + '. Install ipset, iptables, iproute2 and openssh-server, then retry')
    if socket_mode() and command('systemctl', 'show', 'ssh.service', '-p', 'KillMode', '--value').stdout.strip() != 'process':
        raise RuntimeError('Socket-mode migration requires ssh.service KillMode=process to preserve established sessions')
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
            dst.chmod(0o755)
    saved = snapshot()
    try:
        arm()  # Independent persistent timer is armed before any access change.
        # Freeze current windows at both ports before changing listeners or NAT.
        window.apply_rules(old, sources, 0)
        window.apply_rules(port, sources, 0)
        window.write_state({'id': '0' * 32, 'until': 0, 'port': port, 'closed_at': int(time.time())})
        updated = config_text(text, {'SSH_PORT': port, 'SSH_HONEYPOT': 'on',
                                    'SSH_HONEYPOT_PORTS': spec, 'SSH_CLOSED_HONEYPOT': 'yes'})
        atomic(CONF, updated)
        atomic(SSHD, '# Managed by TaraSec port setup\nPort ' + str(port) + '\n', 0o644)
        if socket_mode():
            atomic(SOCKET, socket_config(port, current_bindings), 0o644)
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
        # Refresh-only cannot perform the older temporary-ACCEPT migration.
        command('bash', str(setup), '--refresh-only')
        command('systemctl', 'is-active', '--quiet', 'tarasec-ssh-honeypot.service')
        if not listener(backend):
            raise RuntimeError('The honeypot backend is not listening')
        atomic(UNIT, '[Unit]\nDescription=TaraSec closed-port SSH decoys\n'
               'After=netfilter-persistent.service tarasec-ssh-honeypot.service\n'
               'Before=ssh.service sshd.service\nWants=tarasec-ssh-honeypot.service\n'
               '[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/usr/bin/python3 '
               + str(LIB / 'ssh_port_setup.py') + ' --boot\n[Install]\nWantedBy=multi-user.target\n', 0o644)
        window.reconcile()
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
        print('SSH port and honeypot routing staged; rollback is armed for ten minutes.')
        print('Next: open an MFA window in the app and make a NEW login using your chosen port.')
        print('From that new login, confirm with:')
        print('sudo --preserve-env=SSH_CONNECTION python3 /usr/local/lib/tarasec/ssh_port_setup.py --confirm')
        print('Without confirmation, the previous port and firewall are restored automatically.')
    except BaseException:
        rollback()
        raise


def main():
    if os.geteuid() != 0:
        raise SystemExit('Run with sudo on the server whose SSH port you are changing')
    if sys.argv[1:] not in ([], ['--confirm'], ['--rollback'], ['--boot']):
        raise SystemExit('Usage: sudo python3 misc/ssh_port_setup.py [--confirm|--rollback]')
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
        else:
            migrate()


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as error:
        raise SystemExit(str(error))
