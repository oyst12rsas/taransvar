#!/usr/bin/env python3
"""Owner-confirmed, resumable upgrades from the current TaraSec checkout."""
import argparse
import datetime
import fcntl
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import shlex
import subprocess
import sys
import tempfile

REPO = Path(__file__).resolve().parent.parent
STATE = Path('/var/lib/tarasec/upgrade/state.json')
MODE = Path('/etc/tarasec-deployment-mode')
COMMAND = Path('/usr/local/sbin/tarasec')
ROLES = ('database', 'gateway', 'node')
FEATURES = (
    ('database', 'Back up and migrate the local database schema', {'database', 'gateway'}),
    ('runtime', 'Update installed Perl runtime (preserves local configuration)', set(ROLES)),
    ('taralink', 'Build and update the installed systemd-managed Taralink service', set(ROLES)),
    ('gatekeeper', 'Back up and update Gatekeeper web files', {'database', 'gateway'}),
    ('google', 'Install/update Google manager sign-in and approval worker', set(ROLES)),
    ('ssh-window', 'Enable/refresh Google-controlled temporary SSH access (requires verified console and MFA)', set(ROLES)),
    ('ssh-key', 'Add an administrator SSH public key (password policy unchanged)', set(ROLES)),
    ('honeypot', 'Move administrator SSH and deploy/update the SSH honeypot', set(ROLES)),
)
class PendingVerification(ValueError):
    pass


DEMO_UNITS = ('tarasec-demo4-hotspot.service', 'tarasec-demo5-gateway.service',
              'tarasec-demo5-rejection-target.service', 'tarasec-demo5-gateway.timer')


def run(argv, **kwargs):
    return subprocess.run([str(a) for a in argv], check=True, **kwargs)


def atomic(path, text, mode=0o600):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(dir=path.parent)
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, 'w') as out:
            out.write(text)
            out.flush()
            os.fsync(out.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def choice(prompt):
    while True:
        answer = input(prompt + ' [yes/no/never]: ').strip().lower()
        if answer in ('yes', 'y'): return 'yes'
        if answer in ('no', 'n', ''): return 'no'
        if answer == 'never': return 'never'
        print('Enter yes, no or never. Enter postpones the change.')


def detect_roles():
    roles = set()
    if Path('/var/lib/mysql/taransvar').is_dir(): roles.add('database')
    if Path('/etc/tarasecfw.conf').exists(): roles.add('gateway')
    if not roles: roles.add('node')
    return sorted(roles)


def read_mode():
    if not MODE.exists(): return 'unconfigured'
    mode = MODE.read_text().strip()
    if mode not in ('demo', 'production'):
        raise ValueError('Invalid deployment-mode file; repair it before upgrading')
    return mode


def load_state():
    if not STATE.exists(): return {'features': {}, 'history': []}
    state = json.loads(STATE.read_text())
    if not isinstance(state.get('features'), dict) or not isinstance(state.get('history'), list):
        raise ValueError('Invalid upgrade state; restore its backup before continuing')
    return state


def save(state):
    atomic(STATE, json.dumps(state, indent=2) + '\n')


def backup(path, directory):
    if path.exists():
        target = directory / str(path).lstrip('/')
        target.parent.mkdir(parents=True, exist_ok=True)
        if target.exists(): return  # Retain the first pre-change snapshot on retries.
        if path.is_dir(): shutil.copytree(path, target, symlinks=True)
        else: shutil.copy2(path, target)


def settings(path):
    text = path.read_text() if path.exists() else ''
    return dict(re.findall(r'^\s*([A-Z_]+)\s*=\s*[\'\"]?([^\n\'\"#]*?)[\'\"]?\s*(?:#.*)?$', text, re.M))


def set_setting(path, key, value):
    text = path.read_text() if path.exists() else ''
    text = re.sub(r'^\s*' + re.escape(key) + r'\s*=.*\n?', '', text, flags=re.M)
    atomic(path, text.rstrip() + '\n' + key + '=' + value + '\n')


def production_preflight(roles=()):
    # Do not strand live containment or silently clear security history.
    cfg = settings(Path('/etc/tarasecfw.conf'))
    if cfg.get('SSH_HONEYPOT_DEMO_PORT', '0').strip() not in ('', '0'):
        raise ValueError('Disable the configured honeypot demo port and verify its restart before switching to production')
    for unit in DEMO_UNITS:
        active = subprocess.run(['systemctl', 'is-active', '--quiet', unit]).returncode == 0
        enabled = subprocess.run(['systemctl', 'is-enabled', '--quiet', unit],
                                 stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
        if active or enabled:
            raise ValueError('Release active demo restrictions and disable ' + unit + ' before switching to production')
    # Keep this independent of PHP credentials. Check the local DB only when present.
    if 'database' in roles or 'gateway' in roles or Path('/var/lib/mysql/taransvar').is_dir():
        tables = run(['mariadb', '--batch', '--skip-column-names', 'taransvar', '-e',
                      'SHOW TABLES'], capture_output=True, text=True).stdout.splitlines()
        checks = {'demoSshSession': "state NOT IN ('cleared','expired','cancelled')",
                  'demo5Session': "state NOT IN ('released','expired')",
                  'demoAssistanceSession': "state <> 'closed'",
                  'demo4Session': 'expiresAt > NOW()',
                  'demo': "activeDemo = b'1'",
                  'internalInfections': "active = b'1' AND why LIKE 'DEMO:%'"}
        for table, condition in checks.items():
            if table not in tables: continue
            count = run(['mariadb', '--batch', '--skip-column-names', 'taransvar', '-e',
                         f'SELECT COUNT(*) FROM {table} WHERE {condition}'],
                        capture_output=True, text=True).stdout.strip()
            if count != '0':
                raise ValueError('Finish/reset active sessions in ' + table + ' before switching to production')


def copy_tree(source, target, excluded=()):
    # No --delete and no overwrite of credential-bearing local dbfunc.php.
    target.mkdir(parents=True, exist_ok=True)
    for item in source.iterdir():
        if item.name in excluded: continue
        dst = target / item.name
        if dst.is_symlink(): raise ValueError('Refusing destination symlink: ' + str(dst))
        if item.is_symlink(): raise ValueError('Refusing source symlink: ' + str(item))
        if item.is_dir(): copy_tree(item, dst, excluded)
        else: shutil.copy2(item, dst)


def install_key(directory):
    user = input('Local administrator username: ').strip()
    account = pwd.getpwnam(user)
    keyfile = Path(input('Path to administrator PUBLIC key: ').strip())
    key = keyfile.read_text().strip()
    if len(key.splitlines()) != 1 or not re.match(r'^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+=*(?: .*)?$', key):
        raise ValueError('Supply one OpenSSH public key; never a private key')
    run(['ssh-keygen', '-l', '-f', keyfile], stdout=subprocess.DEVNULL)
    ssh = Path(account.pw_dir) / '.ssh'
    target = ssh / 'authorized_keys'
    if ssh.is_symlink() or target.is_symlink(): raise ValueError('Symlinked SSH key paths require manual review')
    effective = run(['/usr/sbin/sshd', '-T', '-C', f'user={user},host=localhost,addr=127.0.0.1'],
                    capture_output=True, text=True).stdout
    line = next((s for s in effective.splitlines() if s.startswith('authorizedkeysfile ')), '')
    if '.ssh/authorized_keys' not in line.split()[1:]:
        raise ValueError('Custom AuthorizedKeysFile policy requires manual review')
    backup(target, directory)
    ssh.mkdir(mode=0o700, exist_ok=True)
    old = target.read_text() if target.exists() else ''
    if not any(s.split()[:2] == key.split()[:2] for s in old.splitlines()):
        atomic(target, old.rstrip() + '\n' + key + '\n')
    os.chown(ssh, account.pw_uid, account.pw_gid)
    os.chown(target, account.pw_uid, account.pw_gid)
    os.chmod(ssh, 0o700)
    print('Public key installed. Verify a NEW login before changing password authentication.')


def apply_feature(name, directory):
    directory.mkdir(parents=True, mode=0o700, exist_ok=True)
    if name == 'database':
        dump = shutil.which('mariadb-dump') or shutil.which('mysqldump')
        if not dump: raise ValueError('Install mariadb-client before database migration')
        with (directory / 'taransvar.sql').open('wb') as out:
            run([dump, '--single-transaction', '--routines', '--events', '--triggers', 'taransvar'], stdout=out)
        run(['perl', REPO / 'misc/upgrade_db.pl'])
    elif name == 'runtime':
        target = Path('/root/taransvar/perl')
        backup(target, directory)
        copy_tree(REPO / 'misc', target, ('dbfunc.php',))
    elif name == 'taralink':
        target = Path('/usr/local/sbin/taralink')
        if not target.exists() or subprocess.run(['systemctl', 'cat', '--quiet', 'taralink.service']).returncode:
            raise ValueError('Taralink systemd installation required before this upgrade adapter can run')
        helper = shutil.which('mariadb_config') or shutil.which('mysql_config')
        if not helper: raise ValueError('Install MariaDB development headers first')
        flags = shlex.split(run([helper, '--cflags', '--libs'], capture_output=True, text=True).stdout)
        binary = directory / 'taralink.new'
        run(['gcc', '-g', REPO / 'taralink/taralink.c', '-o', binary, *flags, '-lcurl', '-lcjson'])
        backup(target, directory)
        active = subprocess.run(['systemctl', 'is-active', '--quiet', 'taralink.service']).returncode == 0
        run(['systemctl', 'stop', 'taralink.service'])
        try:
            run(['install', '-o', 'root', '-g', 'root', '-m', '0755', binary, target])
        finally:
            if active: run(['systemctl', 'start', 'taralink.service'])
        if active: run(['systemctl', 'is-active', '--quiet', 'taralink.service'])
    elif name == 'gatekeeper':
        target = Path('/var/www/html')
        if not (target / 'dbfunc.php').exists():
            raise ValueError('Gatekeeper is not installed; complete initial installation first')
        backup(target, directory)
        copy_tree(REPO / 'html', target, ('dbfunc.php',))
        run(['php', '-l', target / 'deploymentMode.php'])
    elif name == 'google':
        backup(Path('/etc/tarasec'), directory)
        backup(Path('/etc/tarasec-server-manager.conf'), directory)
        flag = '--no-enrollment' if Path('/etc/tarasec/agent-enrollment.json').exists() else '--enroll-google'
        run(['bash', REPO / 'misc/install_agent_approvals.sh', flag])
        print('Verify Google/TOTP and console recovery before enabling temporary SSH closure; see docs/google-ssh.md.')
    elif name == 'ssh-window':
        if not Path('/etc/tarasec/agent-enrollment.json').exists():
            raise ValueError('Complete Google enrollment first')
        if os.environ.get('SSH_CONNECTION') or os.environ.get('SSH_TTY'):
            raise ValueError('Enable SSH closure from the independent console, not an SSH session')
        if input('Verify Google/TOTP opening AND independent console recovery. Type VERIFIED: ') != 'VERIFIED':
            raise ValueError('MFA and recovery were not confirmed')
        policy = Path('/etc/tarasec-server-manager.conf')
        backup(policy, directory)
        set_setting(policy, 'SSH_GOOGLE_REOPEN_ENABLED', 'yes')
        run(['python3', '/usr/local/lib/tarasec/ssh_google_window.py', 'reconcile'])
        run(['systemctl', 'start', 'tarasec-agent-approvals.service'])
        print('Google gate applied. Test a NEW connection closed, approved, then expired; existing sessions can survive closure.')
    elif name == 'ssh-key':
        install_key(directory)
    elif name == 'honeypot':
        cfg_path = Path('/etc/tarasecfw.conf')
        cfg = settings(cfg_path)
        if not cfg or cfg.get('SSH_PORT', '22').strip() == '22':
            port = input('New administrator SSH port [48222]: ').strip() or '48222'
            if not port.isdigit() or not 1 <= int(port) <= 65535 or int(port) == 22:
                raise ValueError('Choose an administrator port other than 22, within 1..65535')
            backup(cfg_path, directory)
            set_setting(cfg_path, 'SSH_PORT', port)
            set_setting(cfg_path, 'SSH_HONEYPOT', 'on')
            set_setting(cfg_path, 'SSH_FAILSAFE', 'on')
        if cfg.get('SSH_FAILSAFE', 'on').strip().lower() not in ('on', 'yes', '1', 'true'):
            raise ValueError('Enable SSH_FAILSAFE before migrating SSH')
        if input('Verify console recovery and a new login are available. Type VERIFIED: ') != 'VERIFIED':
            raise ValueError('SSH recovery was not confirmed')
        backup(cfg_path, directory)
        run(['bash', REPO / 'misc/setup_ssh_honeypot.sh'])
        # The existing installer deliberately leaves its rollback armed.
        raise PendingVerification('SSH staged with rollback armed. Verify a NEW login and complete the printed recovery checks; upgrade does not cancel rollback')


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('upgrade',))
    parser.add_argument('--no-pull', action='store_true', help='Use the current checkout without fetching updates')
    parser.add_argument('--mode', choices=('demo', 'production'))
    parser.add_argument('--roles', help='Comma-separated local roles: database,gateway,node')
    parser.add_argument('--review-declined', action='store_true')
    parser.add_argument('--status', action='store_true', help='Read status without changing anything')
    parser.add_argument('--plan', action='store_true', help='Show applicable features without changing anything')
    args = parser.parse_args(argv)
    state = load_state()
    roles = args.roles.split(',') if args.roles else state.get('roles', detect_roles())
    if not roles or any(r not in ROLES for r in roles): parser.error('Invalid roles')
    old_mode = read_mode()
    print('TaraSec upgrade — host: ' + os.uname().nodename)
    print('Mode: ' + old_mode + '; roles: ' + ', '.join(roles))
    applicable = [f for f in FEATURES if set(roles) & f[2]]
    for name, title, _ in applicable:
        prior = state['features'].get(name, {})
        print(f"  {name}: {title} [{prior.get('status', 'not recorded')}]")
    if args.status or args.plan: return 0
    if os.geteuid() != 0: parser.error('Run with sudo on the intended server')
    if not sys.stdin.isatty(): parser.error('An interactive terminal is required for owner confirmation')
    STATE.parent.mkdir(parents=True, exist_ok=True)
    with (STATE.parent / 'upgrade.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = load_state()
        roles = args.roles.split(',') if args.roles else state.get('roles', roles)
        mode = args.mode or (input('Choose deployment mode [demo/production]: ').strip().lower()
                             if old_mode == 'unconfigured' else old_mode)
        if mode not in ('demo', 'production'): raise ValueError('Choose demo or production explicitly')
        print('Source: ' + str(REPO) + ' (feature changes require your confirmation)')
        if choice('Confirm local roles: ' + ', '.join(roles)) != 'yes': return 0
        # Backups and audit state are private even when the caller has a permissive umask.
        os.umask(0o077)
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')
        directory = STATE.parent / 'backups' / stamp
        directory.mkdir(parents=True, mode=0o700)
        if mode != old_mode:
            print('Mode change: ' + old_mode + ' → ' + mode)
            print('Production updates the installed web tree to enforce demo API/page restrictions; local DB credentials are preserved. Live demo services/sessions must be released first. History is preserved.')
            if choice('Apply this mode change') != 'yes': return 0
            if mode == 'production':
                production_preflight(roles)
                if Path('/var/www/html').exists():
                    apply_feature('gatekeeper', directory / 'mode-web')
            backup(MODE, directory)
            MODE.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            atomic(MODE, mode + '\n', 0o644)
            state['mode'] = mode
            state['history'].append({'time': stamp, 'mode': mode, 'previous_mode': old_mode})
        state['roles'] = roles
        save(state)
        launcher = COMMAND
        if launcher.exists() and launcher.resolve() != REPO / 'tarasec':
            raise ValueError('An unrelated /usr/local/sbin/tarasec exists; refusing to replace it')
        if not launcher.exists():
            launcher.parent.mkdir(parents=True, exist_ok=True)
            launcher.symlink_to(REPO / 'tarasec')
        failed = False
        for name, title, feature_roles in FEATURES:
            if not set(roles) & feature_roles: continue
            prior = state['features'].get(name, {})
            if prior.get('choice') == 'never' and not args.review_declined: continue
            print('\n' + title)
            print('Affected configuration is backed up under ' + str(directory))
            answer = choice('Apply ' + name)
            record = dict(prior, choice=answer, time=stamp)
            if answer != 'yes':
                record['status'] = 'declined' if answer == 'never' else 'postponed'
            else:
                record.update(status='running', backup=str(directory))
                state['features'][name] = record
                save(state)
                try:
                    apply_feature(name, directory / name)
                    record['status'] = 'completed'
                except (OSError, ValueError, subprocess.CalledProcessError) as error:
                    record['status'] = 'pending_verification' if isinstance(error, PendingVerification) else 'failed'
                    # Do not store command arguments or enrollment output in the audit.
                    record['error'] = type(error).__name__
                    print('Upgrade stopped: ' + (str(error) if isinstance(error, ValueError) else type(error).__name__))
                    failed = True
            state['features'][name] = record
            state['history'].append({'time': stamp, 'feature': name, 'status': record['status']})
            save(state)
            if failed: break
        print('Upgrade ' + ('stopped; fix the reported prerequisite and rerun.' if failed else 'finished.'))
        print('Status: sudo tarasec upgrade --status')
        return 1 if failed else 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, EOFError, KeyboardInterrupt, subprocess.CalledProcessError) as error:
        print('Upgrade stopped: ' + (str(error) if isinstance(error, ValueError) else type(error).__name__), file=sys.stderr)
        raise SystemExit(1)
