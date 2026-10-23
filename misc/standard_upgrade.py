#!/usr/bin/env python3
"""Owner-confirmed, resumable upgrades from the current TaraSec checkout."""
import argparse
import datetime
import fcntl
import getpass
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
import time
import urllib.parse
import urllib.request
from upgrade_release import release_info, deployment_status, automatic_features, AUTOMATIC_FEATURES, upgrade_risk, demo_automatic

REPO = Path(__file__).resolve().parent.parent
STATE = Path('/var/lib/tarasec/upgrade/state.json')
MODE = Path('/etc/tarasec-deployment-mode')
COMMAND = Path('/usr/local/sbin/tarasec')
SSH_AUTH_TARGET = Path('/etc/ssh/sshd_config.d/00-tarasec-key-password.conf')
ROLES = ('database', 'gateway', 'node')
FEATURES = (
    ('database', 'Back up and migrate the local database schema', {'database', 'gateway'}),
    ('runtime', 'Update installed Perl runtime (preserves local configuration)', set(ROLES)),
    ('taralink', 'Build and update the installed systemd-managed Taralink service', set(ROLES)),
    ('gatekeeper', 'Back up and update Gatekeeper web files', {'database', 'gateway'}),
    ('operations', 'Install/update and activate the AI operations agent', set(ROLES)),
    ('google', 'Install/update Google manager sign-in and approval worker', set(ROLES)),
    ('ssh-window', 'Enable/refresh Google-controlled temporary SSH access (requires verified console and MFA)', set(ROLES)),
    ('ssh-key', 'Add an administrator SSH public key (password policy unchanged)', set(ROLES)),
    ('ssh-auth', 'Require both administrator SSH key and account password', set(ROLES)),
    ('honeypot', 'Install or refresh the fake SSH service (honeypot)', set(ROLES)),
)
class FeaturePostponed(Exception):
    pass


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
    print('yes = apply this step; no (or Enter) = skip this time; never = skip on future runs too.')
    print('To reconsider a never choice later, run: sudo tarasec upgrade --review-declined')
    while True:
        answer = input(prompt + ' [yes/no/never]: ').strip().lower()
        if answer in ('yes', 'y'): return 'yes'
        if answer in ('no', 'n', ''): return 'no'
        if answer == 'never': return 'never'
        print('Enter yes, no or never. Enter postpones the change.')


def confirm_verified(prompt, help_callback):
    while True:
        answer = input(prompt + ' [VERIFIED / no / ?]: ').strip()
        if answer.upper() == 'VERIFIED': return
        if answer.lower() in ('', 'no', 'n', 'skip'):
            raise FeaturePostponed('Step postponed; verification was not confirmed')
        if answer.lower() in ('?', 'help'):
            help_callback()
        else:
            print('Not recognized. Type VERIFIED after completing the checks, no to postpone, or ? for help. This verification has not changed SSH access.')


def choose_mode():
    while True:
        mode = input('Choose deployment mode [demo/production]: ').strip().lower()
        if mode in ('demo', 'production'): return mode
        print('Enter demo for automatic testing upgrades, or production for confirmation before each upgrade. Ctrl+C cancels.')


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
    if state.get('roles'):
        atomic(STATE.parent / 'software.json', json.dumps(deployment_status(state, release_info(REPO))) + '\n', 0o644)


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


def public_key_input(value, home):
    value = value.strip()
    if len(value) > 16384: raise ValueError('Public-key input is too large')
    if re.match(r'^(ssh-|ecdsa-)[^ ]+ ', value):
        key = value
    else:
        if not value: raise ValueError('No key supplied. Find a .pub key on your administrator computer first.')
        path = Path(home) / value[2:] if value.startswith('~/') else Path(value)
        if not path.is_file():
            raise ValueError('Public-key file not found on this server. Paste the complete .pub line from your administrator computer instead.')
        if path.stat().st_size > 16384: raise ValueError('Public-key file is too large')
        key = path.read_text().strip()
    if len(key.splitlines()) != 1 or not re.fullmatch(r'(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+=*(?: .*)?', key):
        raise ValueError('Supply one OpenSSH PUBLIC key line from a .pub file, never a private key or authorized_keys list.')
    return key


def feature_guidance(name, root=Path('/')):
    explanations = {
        'database': (
            'The database stores TaraSec units, reports and settings. This updates its tables to the format required by this release.',
            'Yes creates a database backup and applies schema migrations; this does not upgrade MariaDB itself.',
            'Choose yes on a server hosting the TaraSec database. Skipping may leave newer features unavailable.',
        ),
        'runtime': (
            'The runtime is the installed set of Perl scripts used for scheduled TaraSec maintenance and reporting.',
            'Yes replaces those scripts while preserving local configuration. Choose yes to use the current maintenance code.',
        ),
        'taralink': (
            'Taralink is the background service that processes TaraSec traffic and security reports.',
            'Yes builds and replaces the installed program. A running service is briefly stopped and restarted.',
            'Choose yes to update an existing installation; this step requires its build tools and libraries.',
        ),
        'gatekeeper': (
            'Gatekeeper is the web interface for units, issues, reports and administration.',
            'Yes backs up and updates the installed web files while preserving the database connection settings.',
            'Choose yes on a server already hosting Gatekeeper to make the current pages available.',
        ),
        'operations': (
            'The operations agent monitors this server and can perform maintenance allowed by its configured policy.',
            'If missing, you are asked before installation, including in demo mode. Yes installs or updates it and activates its recurring timer.',
            'Activation uses the demo/production mode already selected and first tests the configured model connection without executing model actions.',
            'Missing model settings or a failed connection are reported as pending activation; rerun after fixing them.',
        ),
        'google': (
            'This connects the manager/approval pages to Google sign-in and the approval worker.',
            'Yes refreshes an existing integration, or starts enrollment if none exists and you explicitly approve it.',
            'This step alone does not enable temporary SSH closure. The separate ssh-window step controls that.',
        ),
        'ssh-window': (
            'SSH is remote terminal access. An SSH window temporarily permits NEW connections after Google sign-in and authenticator verification.',
            'Yes enables or refreshes this access control: new SSH connections are blocked outside an approved opening.',
            'You still need your SSH key and any required server password. Existing sessions may survive window expiry.',
            'Choose yes only after Google enrollment and recovery-console testing; choose no to leave the current access policy unchanged.',
        ),
        'ssh-auth': (
            'A working SSH key does not prevent password-only login when AuthenticationMethods is any.',
            'Yes requires BOTH the SSH key and the server account password for new SSH connections.',
            'The private-key passphrase is separate and does not replace the account password.',
            'Google/TOTP temporary opening remains an additional requirement; ports and firewall rules are unchanged.',
            'First test your key and account password, and keep independent console recovery available.',
            'A ten-minute rollback is armed before reloading SSH. Confirm a NEW key-plus-password login to retain the change.',
            'No skips this run; never suppresses this upgrade offer until --review-declined.',
        ),
        'ssh-key': (
            'An SSH key identifies your administrator computer when logging in to this server.',
            'Yes adds its public key to a chosen server account. The private key and its passphrase stay on your computer.',
            'Choose no if your intended key already works. Adding a key does not remove existing keys or change password requirements.',
        ),
        'honeypot': (
            'A honeypot is a fake SSH service that records suspicious connection and login attempts for TaraSec reporting.',
            'It is separate from your real administrator login; do not use the honeypot port for administration.',
            'Yes refreshes an existing setup, or deploys the honeypot and moves real SSH to a separate administrator port when needed.',
            'Choose no to leave the current setup unchanged for this run. A port migration needs console recovery and a fresh login test.',
        ),
    }
    for explanation in explanations.get(name, ()):
        print(explanation)
    if name == 'ssh-key':
        print('Choose the Linux account ON THIS SERVER that you want to log in to: the username you normally use with ssh.')
        print('Find the key on your ADMINISTRATOR COMPUTER (for example Acer), in another terminal:')
        print('  ls ~/.ssh/*.pub')
        print('  cat ~/.ssh/id_ed25519.pub   # use a .pub filename from the list')
        print('To compare the fingerprint shown here, run there: ssh-keygen -lf ~/.ssh/id_ed25519.pub')
        print('Paste that entire public-key line here; it normally starts with ssh-ed25519 or ssh-rsa.')
        print('A filename entered here is read ON THIS SERVER, not on your administrator computer.')
        print('If no public key exists, create one on your administrator computer: ssh-keygen -t ed25519')
        print('Keep the matching private key on that computer. Only the .pub public key is installed on this server.')
        print('Adding a key does not disable password authentication. Test a NEW SSH login afterwards.')
    elif name == 'ssh-window':
        cfg = settings(root / 'etc/tarasecfw.conf')
        nickname = cfg.get('AGENT_PUBLIC_NICKNAME', '')
        if not re.fullmatch(r'[A-Za-z][A-Za-z0-9 _-]{2,31}', nickname): nickname = 'not configured'
        print('Server nickname on the approvals page: ' + nickname)
        manager = settings(root / 'etc/tarasec-server-manager.conf')
        url = manager.get('AGENT_APPROVAL_URL', 'https://tarasec.org/ops/agent/api.php')
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme == 'https' and parsed.hostname and not parsed.username and not parsed.password and not parsed.query and not parsed.fragment:
            print('Open the approvals page in your browser: ' + urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, parsed.path.rsplit('/', 1)[0] + '/', '', '')))
        else:
            print('Find your approval service URL in AGENT_APPROVAL_URL in /etc/tarasec-server-manager.conf.')
        print('Sign in with the enrolled Google account and authenticator (TOTP), then select this nickname and approve a temporary SSH opening.')
        print('Google sign-in alone does not open SSH. Keep a working VM/hypervisor or physical console open for recovery.')
        print('A console is independent of SSH: for a VM, open its console in the hypervisor. Keep it open while testing.')
        print('After enabling, test a NEW SSH connection while closed, after an approved opening, and after expiry.')
        print('Only type VERIFIED after checking the Google/TOTP flow and console access. Otherwise choose no or press Ctrl+C.')
    elif name == 'honeypot':
        print('An existing setup is refreshed without changing administrator SSH ports or firewall rules.')
        print('Only an actual port migration changes SSH and arms a recovery timer.')
        print('Keep the VM/physical console open. Afterward, test a NEW SSH login on the displayed administrator port.')
        print('The rollback timer stays armed until you complete those checks; this upgrader does not cancel it.')


def ssh_auth_policy(user=None):
    user = user or os.environ.get('SUDO_USER') or getpass.getuser()
    context = f'user={user},host=localhost,addr=127.0.0.1'
    connection = os.environ.get('SSH_CONNECTION', '').split()
    if len(connection) == 4:
        context = f'user={user},host={connection[0]},addr={connection[0]},laddr={connection[2]},lport={connection[3]}'
    output = run(['/usr/sbin/sshd', '-T', '-C', context], capture_output=True, text=True).stdout
    return dict(line.split(None, 1) for line in output.splitlines() if ' ' in line)


def require_ssh_key_password(directory):
    user = input('Administrator Linux username ON THIS SERVER [blank = postpone]: ').strip()
    if not user:
        raise FeaturePostponed('SSH authentication change postponed')
    try:
        pwd.getpwnam(user)
    except KeyError:
        raise ValueError('Administrator account does not exist; no SSH changes made')
    status = run(['passwd', '-S', user], capture_output=True, text=True).stdout.split()
    if len(status) < 2 or status[1] != 'P':
        raise ValueError('Set an account password with sudo passwd USER before requiring both factors')
    current = ssh_auth_policy(user)
    if current.get('authenticationmethods') == 'publickey,password':
        return {'authentication_methods': 'publickey,password', 'changed': False}
    if current.get('authenticationmethods') != 'any':
        raise ValueError('Custom SSH authentication policy requires manual review; nothing changed')
    confirm_verified('Confirm a tested key login, known account password AND independent console recovery',
                     lambda: feature_guidance('ssh-auth'))
    target = SSH_AUTH_TARGET
    if any(p.is_symlink() for p in [target, *target.parents]):
        raise ValueError('Symlinked SSH configuration requires manual review')
    if target.exists() and not target.is_file():
        raise ValueError('SSH authentication drop-in must be a regular file')
    run(['/usr/sbin/sshd', '-t'])
    service = next((unit for unit in ('ssh.service', 'sshd.service') if
                    run(['systemctl', 'show', unit, '--property=LoadState', '--value'],
                        capture_output=True, text=True).stdout.strip() == 'loaded'), None)
    if service is None:
        raise ValueError('No installed SSH service found; nothing changed')
    backup(target, directory)
    snapshot = directory / str(target).lstrip('/')
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    rollback = directory / 'restore-ssh-auth.sh'
    restore = ('cp -p -- ' + shlex.quote(str(snapshot)) + ' ' + shlex.quote(str(target))
               if target.exists() else 'rm -f -- ' + shlex.quote(str(target)))
    atomic(rollback, '#!/bin/sh\nset -eu\n' + restore + '\n/usr/sbin/sshd -t\nsystemctl reload ' + service + '\n', 0o700)
    unit = 'tarasec-ssh-auth-rollback-' + str(os.getpid()) + '-' + str(time.time_ns())
    run(['systemd-run', '--unit=' + unit, '--on-active=10m', '/bin/sh', rollback])
    try:
        atomic(target, 'PubkeyAuthentication yes\nPasswordAuthentication yes\nAuthenticationMethods publickey,password\n', 0o644)
        run(['/usr/sbin/sshd', '-t'])
        effective = ssh_auth_policy(user)
        if any(effective.get(key) != value for key, value in {
                'authenticationmethods': 'publickey,password', 'pubkeyauthentication': 'yes',
                'passwordauthentication': 'yes'}.items()):
            raise ValueError('Existing SSH configuration overrides the new policy; restoring previous configuration')
        run(['systemctl', 'reload', service])
        print('Rollback armed for ten minutes. Keep this session open. Open the normal Google/TOTP SSH window if configured.')
        print('From your administrator computer, open a NEW connection with connection sharing disabled:')
        print('  ssh -o ControlMaster=no -o ControlPath=none YOUR_SSH_ALIAS')
        print('It must accept your key AND ask for the SERVER ACCOUNT password. A private-key passphrase is not this password.')
        confirm_verified('Confirm the NEW key-plus-account-password login succeeded', lambda: feature_guidance('ssh-auth'))
        run(['systemctl', 'stop', unit + '.timer'])
        run(['systemctl', 'stop', unit + '.service'])
        if ssh_auth_policy(user).get('authenticationmethods') != 'publickey,password':
            raise ValueError('SSH policy changed or rollback already ran; retry verification')
    except BaseException:
        # Restore immediately on failure, refusal or Ctrl+C. Timer remains a fallback
        # if the session disappears before restoration can run.
        run(['/bin/sh', rollback])
        run(['systemctl', 'stop', unit + '.timer'])
        raise
    return {'authentication_methods': 'publickey,password', 'changed': True, 'new_login_confirmed': True}


def install_key(directory):
    feature_guidance('ssh-key')
    print('You can enter ? for help or leave an answer blank to postpone this step.')
    while True:
        user = input('Local administrator username ON THIS SERVER [blank = postpone, ? = help]: ').strip()
        if not user or user.lower() in ('skip', 'no'): raise FeaturePostponed('Public-key installation postponed')
        if user.lower() in ('?', 'help'):
            feature_guidance('ssh-key'); continue
        try:
            account = pwd.getpwnam(user)
            break
        except KeyError:
            print('That account does not exist on this server. Use its Linux login username (for example the name before @ in your server prompt). Try again or leave blank to postpone.')
    print('Target account: ' + user + '; server home: ' + account.pw_dir)
    while True:
        value = input('Paste administrator PUBLIC key, or enter its path ON THIS SERVER [blank = postpone, ? = help]: ').strip()
        if not value or value.lower() in ('skip', 'no'): raise FeaturePostponed('Public-key installation postponed')
        if value.lower() in ('?', 'help'):
            feature_guidance('ssh-key'); continue
        try:
            key = public_key_input(value, account.pw_dir)
            with tempfile.NamedTemporaryFile(mode='w', prefix='tarasec-public-key-') as public:
                public.write(key + '\n'); public.flush()
                run(['ssh-keygen', '-l', '-f', public.name])
            break
        except ValueError as error:
            print(str(error) + ' Try again, enter ? for help, or leave blank to postpone.')
        except (OSError, UnicodeError):
            print('Cannot read a public-key file on this server. Paste the complete .pub line from your administrator computer, or enter ? for help.')
        except subprocess.CalledProcessError:
            print('OpenSSH could not validate that public key. Paste the complete .pub line again, enter ? for help, or leave blank to postpone.')
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


def existing_google_setup(root=Path('/')):
    # Observe existing local setup without evaluating PHP or reading secrets.
    return any((root / path).is_file() and (root / path).stat().st_size > 0 for path in (
        'etc/tarasec/gatekeeper-google.php', 'etc/tarasec/agent-enrollment.json',
        'etc/tarasec/agent-node.token'))


def refresh_google(directory, root=Path('/')):
    web = root / 'var/www/html/gatekeeper'
    refreshed = False
    details = {}
    if (root / 'etc/tarasec/gatekeeper-google.php').is_file() and web.is_dir():
        if not (web.parent / 'script/serviceDiscoveryCommon.php').is_file():
            raise ValueError('Update Gatekeeper before refreshing its Google integration')
        names = ('google_start.php', 'google_callback.php', 'google_local_managers.php')
        for name in names:
            run(['php', '-l', REPO / 'html/gatekeeper' / name])
        for name in names:
            backup(web / name, directory)
            shutil.copy2(REPO / 'html/gatekeeper' / name, web / name)
        refreshed = True
        details['gatekeeper_google'] = 'updated'
        print('Existing Gatekeeper Google login refreshed; client configuration retained.')
    if (root / 'etc/tarasec/agent-node.token').is_file():
        missing = [path for path in ('/etc/tarasec/access.conf', '/etc/tarasec-server-manager.conf')
                   if not (root / path.lstrip('/')).is_file() or
                   not os.access(root / path.lstrip('/'), os.R_OK)]
        if not settings(root / 'etc/tarasecfw.conf').get('AGENT_PUBLIC_NICKNAME'):
            missing.append('AGENT_PUBLIC_NICKNAME in /etc/tarasecfw.conf')
        if missing:
            details.update(pending_configuration=True, approval_worker='needs_configuration', missing_policy=missing)
            print('Optional approval worker needs configuration: ' + ', '.join(missing))
            print('Worker left unchanged; continuing other upgrades. Existing Google web login is independent.')
        else:
            run(['bash', REPO / 'misc/install_agent_approvals.sh', '--no-enrollment', '--update-only'])
            refreshed = True
            details['approval_worker'] = 'updated'
    if not refreshed and not details.get('pending_configuration'):
        raise ValueError('Existing enrollment receipt has no deployed Google configuration or worker identity; repair is required')
    print('Existing Google setup retained. No manager enrollment or SSH policy change requested.')
    return details


def record_feature_result(record, name, result, version, revision):
    details = result if isinstance(result, dict) else {}
    partial = details.get('pending_configuration') is True
    record['status'] = 'partial' if partial else 'completed'
    if details: record['details'] = details
    else: record.pop('details', None)
    if name in AUTOMATIC_FEATURES:
        record.update(installed_once=True, installed_version=0 if partial else version, installed_revision=revision)


def existing_honeypot_setup(cfg):
    port = cfg.get('SSH_PORT', '')
    if not port.isdigit() or not 1 <= int(port) <= 65535: return False
    if not Path('/etc/systemd/system/tarasec-ssh-honeypot.service').is_file(): return False
    if not Path('/usr/local/lib/tarasec/tarasec_ssh_honeypot.py').is_file(): return False
    try:
        effective = run(['/usr/sbin/sshd', '-T'], capture_output=True, text=True).stdout
        ports = {line.split()[1] for line in effective.splitlines() if line.startswith('port ')}
        listeners = run(['ss', '-H', '-ltn', 'sport = :' + port], capture_output=True, text=True).stdout
        return ports == {port} and bool(listeners.strip())
    except (OSError, subprocess.CalledProcessError):
        return False


def ssh_window_current(root=Path('/')):
    """Read-only deployment check; never reconcile or open/close an SSH lease."""
    policy = settings(root / 'etc/tarasec-server-manager.conf')
    if policy.get('SSH_GOOGLE_REOPEN_ENABLED', '').lower() not in ('yes', 'on', 'true', '1'):
        return False
    if not (root / 'etc/tarasec/agent-enrollment.json').is_file(): return False
    try:
        for name in ('ssh_google_window.py', 'local_manager_policy.py', 'agent_approval_worker.py', 'ssh_approval_poll.py'):
            if (root / 'usr/local/lib/tarasec' / name).read_bytes() != (REPO / 'misc' / name).read_bytes():
                return False
        port = settings(root / 'etc/tarasecfw.conf').get('SSH_PORT', '22')
        if not port.isdigit() or not 1 <= int(port) <= 65535: return False
        for timer in ('tarasec-agent-approvals.timer', 'tarasec-ssh-approval-poll.timer'):
            run(['systemctl', 'is-active', '--quiet', timer], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        for tool in ('iptables', 'ip6tables'):
            run([tool, '-w', '5', '-C', 'INPUT', '-p', 'tcp', '--dport', port,
                 '-m', 'conntrack', '--ctstate', 'NEW', '-j', 'TARASEC_SSH_GOOGLE'],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        return True
    except (OSError, subprocess.SubprocessError):
        return False


def operations_installed():
    return (Path('/usr/local/lib/tarasec-operations/operations_agent.py').is_file()
            and Path('/etc/systemd/system/tarasec-operations-agent.timer').is_file())


def wait_operations_approval(request, answer):
    from enroll_google_manager import NoRedirect
    print('Waiting up to 5 minutes. Press Ctrl+C to skip AI activation for now and continue the remaining upgrades.')
    print('You can approve later and rerun sudo tarasec upgrade to resume.')
    deadline = time.monotonic() + 300
    try:
        while answer.get('state') == 'pending' and answer.get('approved') is not True:
            remaining = deadline - time.monotonic()
            if remaining <= 0: break
            time.sleep(min(5, remaining))
            remaining = deadline - time.monotonic()
            if remaining <= 0: break
            with urllib.request.build_opener(NoRedirect()).open(request, timeout=min(20, remaining)) as response:
                answer = json.loads(response.read(8193))
    except KeyboardInterrupt:
        print('\nAI activation postponed; the approval request remains available. Continuing remaining upgrades.')
        return {'approved': False, 'state': 'pending'}
    except (OSError, ValueError):
        print('Approval check unavailable; activation postponed. Rerun sudo tarasec upgrade to resume.')
        return {'approved': False, 'state': 'pending'}
    if answer.get('approved') is not True and answer.get('state') == 'pending':
        print('Five-minute wait ended; activation postponed. Approve on the website and rerun sudo tarasec upgrade to resume.')
    return answer


def enroll_operations_model(policy, policy_path, directory, root=Path('/')):
    from enroll_google_manager import private_read, NoRedirect, enrollment_url
    receipt_path = root / 'etc/tarasec/agent-enrollment.json'
    if not receipt_path.is_file():
        print('TaraSec AI enrollment pending: complete Google manager enrollment first so the site can identify this node and its authorized managers.')
        return False
    receipt = json.loads(private_read(receipt_path))
    api = receipt['approval_url']
    enrollment_url(api)  # Strict HTTPS service URL validation.
    token_path = root / 'etc/tarasec/agent-node.token'
    token = private_read(token_path).strip()
    endpoint = api + '?action=operations_model'
    request = urllib.request.Request(api + '?action=operations_enroll',
        data=json.dumps({'mode': read_mode()}).encode(),
        headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=20) as response:
            answer = json.loads(response.read(8193))
    except (OSError, ValueError):
        print('TaraSec AI enrollment unavailable; deploy the central enrollment update and retry. Local credentials were retained.')
        return False
    if answer.get('approved') is not True:
        print('\nACTION REQUIRED IN YOUR BROWSER — activate the AI agent')
        print('1. Open ' + api.rsplit('/', 1)[0] + '/')
        print('2. Sign in with Google and select machine: ' + str(receipt.get('nickname', 'this node')))
        print('3. Find "Activate AI operations agent", enter your authenticator code, and click Approve.')
        print('Leave this terminal open. No prediction URL or provider key is needed.')
        if sys.stdin.isatty() and answer.get('state') == 'pending':
            answer = wait_operations_approval(request, answer)
        if answer.get('approved') is not True: return False
    if answer.get('model_ready') is not True:
        print('AI activation approved; the central TaraSec model connection still needs configuration by its administrator. Rerun after it is ready.')
        return False
    backup(policy_path, directory)
    policy.update(model_provider='flowise', model_url=endpoint,
                  model_key_file=str(token_path), model_connection='tarasec')
    atomic(policy_path, json.dumps(policy, indent=2) + '\n')
    return True


def configure_operations_model(policy, policy_path, directory, root=Path('/')):
    """Collect dedicated model settings locally; never print or reuse credentials."""
    provider = policy.get('model_provider', 'flowise')
    key_field = 'openai_key_file' if provider == 'openai' else 'model_key_file'
    key_path = Path(policy.get(key_field, '/etc/tarasec/operations-model.key'))
    key_ready = key_path.is_file() and bool(key_path.read_text().strip())
    endpoint_ready = bool(policy.get('openai_model') if provider == 'openai' else policy.get('model_url'))
    if policy.get('model_connection') == 'tarasec':
        return enroll_operations_model(policy, policy_path, directory, root)
    if endpoint_ready and key_ready: return True
    print('AI agent needs a dedicated model connection. Demo/production mode is already selected.')
    print('Blank uses TaraSec enrollment and Google/TOTP approval on the agent page. A custom Flowise URL or OpenAI connection is optional. Model calls may incur provider charges.')
    if not sys.stdin.isatty():
        return enroll_operations_model(policy, policy_path, directory, root)
    while True:
        selected = input('Model provider [flowise/openai; blank = TaraSec system, none = postpone]: ').strip().lower()
        if selected == 'none': return False
        if not selected: return enroll_operations_model(policy, policy_path, directory, root)
        if selected in ('flowise', 'openai'): break
        print('Enter flowise or openai.')
    updated = dict(policy, model_provider=selected)
    if selected == 'flowise':
        while True:
            value = input('Flowise prediction URL [blank = TaraSec system, none = postpone]: ').strip()
            if value.lower() == 'none': return False
            if not value: return enroll_operations_model(policy, policy_path, directory, root)
            url = urllib.parse.urlsplit(value)
            if url.scheme in ('https', 'http') and url.hostname and not url.username and not url.password and not url.query and not url.fragment: break
            print('Use an HTTP(S) prediction URL without embedded credentials, query or fragment.')
        updated['model_url'] = value
        updated['allow_insecure_model_http'] = url.scheme == 'http'
        if url.scheme == 'http': print('Custom HTTP connection selected: its model key and requests travel without TLS.')
        field, filename = 'model_key_file', 'operations-model.key'
    else:
        value = input('OpenAI model name supported by your account [blank = postpone]: ').strip()
        if not value: return False
        updated['openai_model'] = value
        field, filename = 'openai_key_file', 'operations-openai.key'
    secret = getpass.getpass('Dedicated model API key (hidden; blank = postpone): ').strip()
    if not secret: return False
    destination = root / 'etc/tarasec' / filename
    backup(policy_path, directory)
    backup(destination, directory)
    atomic(destination, secret + '\n')
    updated[field] = str(destination)
    atomic(policy_path, json.dumps(updated, indent=2) + '\n')
    policy.clear(); policy.update(updated)
    print('Model settings saved privately. Testing the connection before activating the agent.')
    return True


def ensure_operations_activity(root=Path('/'), interactive=False):
    """Resume a configured observer; require real feed enrollment for missing data."""
    path = root / 'etc/tarasec/operations-activity.json'
    try:
        config = json.loads(path.read_text())
    except (OSError, ValueError):
        config = {}
    if interactive:
        print('The reboot observer needs the authenticated central demo feed, separately from minute status reports.')
        print('On the central DB server, enroll this node source IP with misc/install_operations_activity_feed.sh SOURCE_IP WEB_ROOT.')
        print('Transfer its node key securely to THIS server; never paste a key into chat.')
        print('Use HTTPS, or explicitly enter the existing NetBird HTTP feed. No missing response is treated as idle.')
        url = input('Demo feed URL [Enter = keep existing, none = postpone]: ').strip()
        if url.lower() == 'none':
            return 'needs_configuration'
        if url:
            key = input('Node feed key file ON THIS SERVER [Enter = existing configured path]: ').strip() or config.get('demo_key_file', '/etc/tarasec/operations-activity.key')
            parsed = urllib.parse.urlsplit(url)
            flag = []
            if parsed.scheme == 'http':
                import ipaddress
                try:
                    address = ipaddress.ip_address(parsed.hostname or '')
                except ValueError:
                    raise ValueError('HTTP demo feed requires a literal loopback or NetBird address')
                if address.is_loopback:
                    flag = ['--loopback-http']
                elif address.version == 4 and address in ipaddress.ip_network('100.64.0.0/10'):
                    flag = ['--netbird-http']
                else:
                    raise ValueError('HTTP demo feed must use loopback or NetBird; otherwise use HTTPS')
            run(['bash', REPO / 'misc/configure_operations_activity.sh', url, key, *flag])
            config = json.loads(path.read_text())
    if not config.get('demo_url'):
        print('Activity observer pending: no demo feed URL configured. Run sudo tarasec upgrade --configure-activity.')
        return 'needs_configuration'
    try:
        # Verify the configured collector before claiming activity readiness.
        collector = config.get('demo_collector', '/usr/local/lib/tarasec-operations/demo-collector')
        from operations_agent import trusted
        executable = trusted(collector)
        observed = run([executable], capture_output=True, text=True, timeout=15)
        sample = json.loads(observed.stdout)
        stamp = sample.get('checked_at')
        if (sample.get('complete') is not True or not isinstance(sample.get('active_demo'), bool)
                or not isinstance(stamp, (int, float)) or isinstance(stamp, bool)
                or not 0 <= time.time() - stamp <= 30):
            print('Activity observer pending: authenticated demo feed is incomplete. Run the demo-collector for its specific reason.')
            return 'feed_unavailable'
        run(['systemctl', 'enable', '--now', 'tarasec-operations-activity.service'])
        run(['systemctl', 'restart', 'tarasec-operations-activity.service'])
        run(['systemctl', 'is-active', '--quiet', 'tarasec-operations-activity.service'])
    except (OSError, ValueError, TypeError, subprocess.SubprocessError):
        print('Activity observer pending: collector or observer service unavailable. Inspect demo-collector and the activity-service journal.')
        return 'feed_unavailable'
    print('Authenticated demo feed verified; continuous observer active. Fresh traffic baseline and quiet time are still required.')
    return 'active'


def activate_operations(directory, root=Path('/')):
    policy_path = root / 'etc/tarasec/operations-agent.json'
    policy = json.loads(policy_path.read_text())
    mode = read_mode()
    if mode not in ('demo', 'production'):
        raise ValueError('Select demo or production mode before AI agent activation')
    if not configure_operations_model(policy, policy_path, directory, root):
        print('AI agent installed; activation pending model configuration. Rerun sudo tarasec upgrade to resume setup.')
        return {'pending_configuration': True, 'activation': 'pending_model_configuration'}
    # Probe before policy/timer changes. The probe never dispatches returned actions.
    probe = subprocess.run(['python3', '/usr/local/lib/tarasec-operations/operations_model_probe.py', '--connection-check'],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=240)
    if probe.returncode:
        print('AI agent activation pending: model connection/protocol check failed. Check the endpoint, credentials and model configuration; rerun sudo tarasec upgrade.')
        return {'pending_configuration': True, 'activation': 'pending_model_connection'}
    backup(policy_path, directory)
    policy['mode'] = mode
    atomic(policy_path, json.dumps(policy, indent=2) + '\n')
    activity = ensure_operations_activity(root)
    run(['systemctl', 'enable', '--now', 'tarasec-operations-agent.timer'])
    run(['systemctl', 'is-active', '--quiet', 'tarasec-operations-agent.timer'])
    print('AI operations agent activated in ' + mode + ' mode. Scheduled checks are enabled; action-specific readiness checks still apply.')
    return {'activation': 'active', 'mode': mode, 'activity_observer': activity}


def apply_feature(name, directory, allow_enrollment=False):
    directory.mkdir(parents=True, mode=0o700, exist_ok=True)
    if name == 'database':
        client = shutil.which('mariadb') or shutil.which('mysql')
        if not client: raise ValueError('Install mariadb-client before database migration')
        versions = re.findall(r'^#version\s+(\d+)\b', (REPO / 'misc/install.sql').read_text(), re.M)
        if not versions: raise ValueError('No database migrations found')
        latest = max(map(int, versions))
        current_text = run([client, '--batch', '--skip-column-names', 'taransvar', '-e',
                            'SELECT dbVersion FROM setup'], capture_output=True, text=True).stdout.strip()
        if not current_text.isdigit(): raise ValueError('Expected one database schema version')
        current = int(current_text)
        if current > latest: raise ValueError('Database schema is newer than this checkout')
        if current == latest:
            print('Database schema ' + str(current) + ' is already current; no migration or database dump needed.')
            return {'schema_version': current, 'migration': 'not_needed'}
        dump = shutil.which('mariadb-dump') or shutil.which('mysqldump')
        if not dump: raise ValueError('Install mariadb-client before database migration')
        size = run([client, '--batch', '--skip-column-names', '-e',
                    "SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema='taransvar'"],
                   capture_output=True, text=True).stdout.strip()
        if not size.isdigit(): raise ValueError('Could not estimate database backup size')
        required = 2 * int(size) + 256 * 1024 * 1024
        if shutil.disk_usage(directory).free < required:
            raise ValueError('Insufficient space for a safe database backup: need approximately ' + str(required // (1024*1024)) + ' MiB including reserve. No dump or migration started. Free space or expand the disk, then rerun.')
        partial = directory / 'taransvar.sql.partial'
        created = False
        try:
            with partial.open('xb') as out:
                created = True
                run([dump, '--single-transaction', '--routines', '--events', '--triggers', 'taransvar'], stdout=out)
                out.flush(); os.fsync(out.fileno())
            partial.replace(directory / 'taransvar.sql')
        except BaseException:
            if created: partial.unlink(missing_ok=True)
            raise
        run(['perl', REPO / 'misc/upgrade_db.pl', '--confirmed'])
    elif name == 'runtime':
        target = Path('/root/taransvar/perl')
        backup(target, directory)
        copy_tree(REPO / 'misc', target, ('dbfunc.php',))
        # Runtime replacement must preserve operations reporting/receipt integration.
        from install_operations_reporter_bridge import patched_source
        reporter = target / 'crontasks.pl'
        atomic(reporter, patched_source(reporter.read_text()), 0o644)
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
    elif name == 'operations':
        backup(Path('/usr/local/lib/tarasec-operations'), directory)
        backup(Path('/etc/tarasec/operations-agent.json'), directory)
        run(['bash', REPO / 'misc/install_operations_agent.sh'])
        return activate_operations(directory)
    elif name == 'google':
        backup(Path('/etc/tarasec'), directory)
        backup(Path('/etc/tarasec-server-manager.conf'), directory)
        if existing_google_setup():
            return refresh_google(directory)
        elif allow_enrollment:
            run(['bash', REPO / 'misc/install_agent_approvals.sh', '--enroll-google'])
        else:
            raise ValueError('New Google enrollment requires explicit confirmation')
        print('Verify Google/TOTP and console recovery before enabling temporary SSH closure; see docs/google-ssh.md.')
    elif name == 'ssh-window':
        feature_guidance('ssh-window')
        if not Path('/etc/tarasec/agent-enrollment.json').exists():
            raise ValueError('Complete Google enrollment first')
        if os.environ.get('SSH_CONNECTION') or os.environ.get('SSH_TTY'):
            raise ValueError('Enable SSH closure from the independent console, not an SSH session')
        confirm_verified('Confirm Google/TOTP opening AND independent console recovery', lambda: feature_guidance('ssh-window'))
        policy = Path('/etc/tarasec-server-manager.conf')
        backup(policy, directory)
        set_setting(policy, 'SSH_GOOGLE_REOPEN_ENABLED', 'yes')
        run(['python3', '/usr/local/lib/tarasec/ssh_google_window.py', 'reconcile'])
        run(['systemctl', 'start', 'tarasec-agent-approvals.service'])
        print('Google gate applied. Test a NEW connection closed, approved, then expired; existing sessions can survive closure.')
    elif name == 'ssh-key':
        install_key(directory)
    elif name == 'ssh-auth':
        return require_ssh_key_password(directory)
    elif name == 'honeypot':
        cfg_path = Path('/etc/tarasecfw.conf')
        cfg = settings(cfg_path)
        if existing_honeypot_setup(cfg):
            print('Existing administrator SSH and honeypot detected; refreshing without port migration.')
            run(['bash', REPO / 'misc/setup_ssh_honeypot.sh', '--refresh-only'])
            return {'administrator_ssh': 'unchanged', 'honeypot': 'refreshed'}
        feature_guidance('honeypot')
        confirm_verified('Confirm independent console recovery and ability to test a NEW login', lambda: feature_guidance('honeypot'))
        cfg_path = Path('/etc/tarasecfw.conf')
        cfg = settings(cfg_path)
        if not cfg or cfg.get('SSH_PORT', '22').strip() == '22':
            while True:
                port = input('New administrator SSH port [48222; no = postpone, ? = help]: ').strip() or '48222'
                if port.lower() in ('no', 'skip'): raise FeaturePostponed('SSH port migration postponed')
                if port.lower() in ('?', 'help'):
                    feature_guidance('honeypot'); continue
                if port.isdigit() and 1 <= int(port) <= 65535 and int(port) != 22: break
                print('Enter a port from 1 to 65535 other than 22, or no to postpone. Try again.')
            backup(cfg_path, directory)
            set_setting(cfg_path, 'SSH_PORT', port)
            set_setting(cfg_path, 'SSH_HONEYPOT', 'on')
            set_setting(cfg_path, 'SSH_FAILSAFE', 'on')
        if cfg.get('SSH_FAILSAFE', 'on').strip().lower() not in ('on', 'yes', '1', 'true'):
            raise ValueError('Enable SSH_FAILSAFE before migrating SSH')
        backup(cfg_path, directory)
        run(['bash', REPO / 'misc/setup_ssh_honeypot.sh'])
        # A first honeypot deployment may also find SSH already moved.
        armed = subprocess.run(['systemctl', 'is-active', '--quiet', 'tarasec-ssh-rollback.timer'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
        if not armed:
            return {'administrator_ssh': 'unchanged', 'honeypot': 'updated'}
        raise PendingVerification('SSH staged with rollback armed. Verify a NEW login and complete the printed recovery checks; upgrade does not cancel rollback')


def disable_production_migration_timer():
    # Older installations have an independent scheduler that must not bypass
    # the per-upgrade production confirmation required by the standard command.
    if Path('/etc/systemd/system/tarasec-db-migrate.timer').exists():
        run(['systemctl', 'disable', '--now', 'tarasec-db-migrate.timer'])


def source_revision():
    revision = run(['git', '-C', REPO, 'rev-parse', 'HEAD'], capture_output=True, text=True).stdout.strip()
    if not re.fullmatch('[a-f0-9]{40}', revision): raise ValueError('Invalid source revision')
    return revision


def automatic_upgrade(args, state, mode):
    if os.geteuid() != 0: raise ValueError('Automatic upgrade requires root')
    if args.mode or args.roles or args.review_declined:
        raise ValueError('Automatic upgrades cannot change mode, roles or feature choices')
    if mode != 'demo':
        raise ValueError('Production upgrades require user confirmation for every upgrade')
    if not state.get('roles'):
        raise ValueError('Complete interactive setup before automatic upgrades')
    os.umask(0o077)
    STATE.parent.mkdir(parents=True, exist_ok=True)
    with (STATE.parent / 'upgrade.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = load_state()
        state['google_existing'] = existing_google_setup()
        names = [name for name, _, roles in FEATURES if set(state['roles']) & roles]
        # Re-read authoritative policy after acquiring the deployment lock.
        if read_mode() != 'demo': raise ValueError('Node is no longer in demo mode')
        release = release_info(REPO)
        state['mode'] = 'demo'
        selected = automatic_features(state, names, 'demo', release)
        if 'operations' in selected and not operations_installed():
            selected.remove('operations')
            print('AI agent not installed: run sudo tarasec upgrade to approve installation.')
        if not selected: raise ValueError('No upgrades eligible for automatic demo testing')
        revision = source_revision()
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')
        directory = STATE.parent / 'backups' / stamp
        directory.mkdir(parents=True, mode=0o700)
        for name in selected:
            if read_mode() != 'demo': raise ValueError('Mode changed; automatic upgrades stopped')
            record = state['features'].setdefault(name, {})
            if record.get('installed_revision') == revision and record.get('installed_version') == release['version']:
                continue
            record.update(status='running', installed_version=0, backup=str(directory), time=stamp,
                          approval='demo_policy', risk=upgrade_risk(name, release, state))
            save(state)
            try:
                result = apply_feature(name, directory / name)
                record_feature_result(record, name, result, release['version'], revision)
            except (OSError, ValueError, subprocess.CalledProcessError) as error:
                record.update(status='failed', error=type(error).__name__)
                state['history'].append({'time': stamp, 'feature': name, 'status': 'failed', 'automatic': True})
                save(state)
                raise
            state['history'].append({'time': stamp, 'feature': name, 'status': record['status'], 'automatic': True})
            save(state)
        print('Eligible component updates finished; the next heartbeat verifies notice clearance.')
        if any(r.get('status') == 'partial' for r in state['features'].values()):
            print('Configuration warnings remain; see sudo tarasec upgrade --status.')
        return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('upgrade',))
    parser.add_argument('--automatic', action='store_true', help='Demo only: automatically apply upgrades rated none through medium risk')
    parser.add_argument('--no-pull', action='store_true', help='Use the current checkout without fetching updates')
    parser.add_argument('--mode', choices=('demo', 'production'))
    parser.add_argument('--roles', help='Comma-separated local roles: database,gateway,node')
    parser.add_argument('--review-declined', action='store_true')
    parser.add_argument('--configure-activity', action='store_true', help='Repair authenticated activity-feed configuration and resume the observer without a full upgrade')
    parser.add_argument('--status', action='store_true', help='Read status without changing anything')
    parser.add_argument('--plan', action='store_true', help='Show applicable features without changing anything')
    args = parser.parse_args(argv)
    state = load_state()
    roles = args.roles.split(',') if args.roles else (state.get('roles') or detect_roles())
    if not roles or any(r not in ROLES for r in roles): parser.error('Invalid roles')
    state['google_existing'] = existing_google_setup()
    old_mode = read_mode()
    print('TaraSec upgrade — host: ' + os.uname().nodename)
    print('Mode: ' + old_mode + '; roles: ' + ', '.join(roles))
    applicable = [f for f in FEATURES if set(roles) & f[2]]
    for name, title, _ in applicable:
        prior = state['features'].get(name, {})
        print(f"  {name}: {title} [{prior.get('status', 'not recorded')}; risk: {upgrade_risk(name, release_info(REPO), state)}]")
        details = prior.get('details', {})
        if details.get('approval_worker') == 'needs_configuration':
            print('    Optional approval worker needs configuration: ' + ', '.join(details.get('missing_policy', [])))
    if args.status or args.plan: return 0
    if args.configure_activity:
        if args.automatic: parser.error('--configure-activity requires interactive owner configuration')
        if os.geteuid() != 0 or not sys.stdin.isatty(): parser.error('Run with sudo in an interactive terminal')
        STATE.parent.mkdir(parents=True, exist_ok=True)
        with (STATE.parent / 'upgrade.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            # Preserve the previous configuration before any enrollment change.
            stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S.%fZ')
            directory = STATE.parent / 'backups' / stamp
            backup(Path('/etc/tarasec/operations-activity.json'), directory)
            backup(Path('/etc/tarasec/operations-activity.key'), directory)
            run(['bash', REPO / 'misc/install_operations_agent.sh'])
            readiness = ensure_operations_activity(interactive=True)
            print('Activity observer: ' + readiness)
            return 0 if readiness == 'active' else 1
    if args.automatic: return automatic_upgrade(args, state, old_mode)
    if os.geteuid() != 0: parser.error('Run with sudo on the intended server')
    if not sys.stdin.isatty(): parser.error('An interactive terminal is required for owner confirmation')
    STATE.parent.mkdir(parents=True, exist_ok=True)
    with (STATE.parent / 'upgrade.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = load_state()
        roles = args.roles.split(',') if args.roles else state.get('roles', roles)
        mode = args.mode or (choose_mode() if old_mode == 'unconfigured' else old_mode)
        if mode not in ('demo', 'production'): raise ValueError('Choose demo or production explicitly')
        print('Source: ' + str(REPO))
        print('Demo automatically applies none/low/medium risk upgrades; production confirms each upgrade. High/unknown risk always requires confirmation.')
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
        if mode == 'production':
            disable_production_migration_timer()
        state['roles'] = roles
        state['mode'] = mode
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
            if name == 'ssh-window' and ssh_window_current():
                print('\nSSH window already enabled and current; installed code, polling timers and IPv4/IPv6 firewall hooks checked. No changes made.')
                continue
            if name == 'ssh-auth':
                try:
                    methods = ssh_auth_policy().get('authenticationmethods', 'unknown')
                except (OSError, subprocess.CalledProcessError):
                    methods = 'unknown'
                if methods == 'publickey,password':
                    print('\nSSH already requires both public key and account password; no change needed.')
                    continue
                print('\nWARNING: SSH AuthenticationMethods is ' + methods +
                      ('; a public key and account password are not both required.' if methods == 'any'
                       else '; inspect the effective policy before changing it.'))
            print('\n' + title)
            feature_guidance(name)
            print('Affected configuration is backed up under ' + str(directory))
            release = release_info(REPO)
            state['google_existing'] = existing_google_setup()
            if name == 'google' and state['google_existing']:
                print('Google login already configured: refresh existing integration only.')
            risk = upgrade_risk(name, release, state)
            # A low-risk dependent still waits for its schema/runtime prerequisites.
            applicable_names = [n for n, _, rs in FEATURES if set(roles) & rs]
            auto = mode == 'demo' and name in automatic_features(state, applicable_names, mode, release)
            if name == 'operations':
                installed = operations_installed()
                print('AI operations agent: ' + ('installed; update and activate.' if installed else 'not installed; installation requires your confirmation.'))
                if not installed:
                    auto = False
            print('Risk: ' + risk + '; release: ' + str(release['version']))
            if auto:
                print('Applying automatically under demo testing policy.')
                answer = 'yes'
            else:
                answer = choice('Apply ' + name)
            record = dict(prior, choice=answer, time=stamp, risk=risk,
                          approval='demo_policy' if auto else 'user_confirmation')
            if answer != 'yes':
                record['status'] = 'declined' if answer == 'never' else 'postponed'
            else:
                record.update(status='running', backup=str(directory))
                state['features'][name] = record
                save(state)
                try:
                    if name in AUTOMATIC_FEATURES:
                        record['installed_version'] = 0
                        save(state)
                    if name == 'google' and not auto:
                        result = apply_feature(name, directory / name, allow_enrollment=True)
                    else:
                        result = apply_feature(name, directory / name)
                    record_feature_result(record, name, result, release_info(REPO)['version'], source_revision())
                except FeaturePostponed as error:
                    record.update(status='postponed', choice='no')
                    print(str(error) + '; continuing the remaining upgrade steps.')
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
        if any(r.get('status') == 'partial' for r in state['features'].values()):
            print('Configuration warnings remain; optional components were left unchanged.')
        print('Status: sudo tarasec upgrade --status')
        return 1 if failed else 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError, EOFError, KeyboardInterrupt, subprocess.CalledProcessError) as error:
        print('Upgrade stopped: ' + (str(error) if isinstance(error, ValueError) else type(error).__name__), file=sys.stderr)
        raise SystemExit(1)
