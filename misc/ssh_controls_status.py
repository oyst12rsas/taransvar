#!/usr/bin/env python3
"""Read-only summary of which SSH login controls are in place on this node.

Prints one JSON object for the status reporter (crontasks.pl). It never
changes SSH, firewall or service state. Results are cached so the per-minute
reporter stays cheap.

Controls:
  moved_port     administrative sshd listens on SSH_PORT, which is not 22
  honeypot       the SSH honeypot service is active
  key_login      sshd accepts public keys and an administrator key is installed
  no_password_only  a password alone cannot log in
  google_window  new SSH connections need a Google/MFA-approved timed window
  manager_ssh    the manager SSH worker is healthy (only checked when installed)
"""
import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path

CACHE = Path('/var/lib/tarasec/ssh-controls.json')
MAX_AGE = 300
LABELS = {
    'moved_port': 'administrative SSH moved off port 22',
    'honeypot': 'SSH honeypot running',
    'key_login': 'administrator SSH key login',
    'no_password_only': 'password alone cannot log in',
    'google_window': 'Google/MFA timed SSH window',
    'manager_ssh': 'manager SSH worker healthy',
}


def run(argv):
    try:
        result = subprocess.run(argv, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                text=True, timeout=15, check=False)
        return result.returncode, result.stdout
    except (OSError, subprocess.SubprocessError):
        return 1, ''


def settings(path):
    values = {}
    try:
        for line in Path(path).read_text().splitlines():
            match = re.match(r'^\s*([A-Z_]+)\s*=\s*(.*?)\s*(?:#.*)?$', line)
            if match:
                values[match.group(1)] = match.group(2).strip().strip('"\'')
    except OSError:
        pass
    return values


def on(value):
    return str(value).strip().lower() in ('on', 'yes', 'true', '1')


def authorized_keys(root):
    homes = [root / 'root'] + sorted((root / 'home').glob('*'))
    for home in homes:
        path = home / '.ssh' / 'authorized_keys'
        try:
            if any(line.strip() and not line.lstrip().startswith('#') for line in path.read_text().splitlines()):
                return True
        except OSError:
            continue
    return False


def unit_state(unit):
    code, out = run(['systemctl', 'show', unit, '-p', 'LoadState', '-p', 'ActiveState', '-p', 'Result'])
    return dict(line.split('=', 1) for line in out.splitlines() if '=' in line) if code == 0 else {}


def collect(root=Path('/')):
    fw = settings(root / 'etc/tarasecfw.conf')
    manager = settings(root / 'etc/tarasec-server-manager.conf')
    port = fw.get('SSH_PORT', '22').strip() or '22'
    code, effective = run(['/usr/sbin/sshd', '-T'])
    sshd = {}
    for line in effective.splitlines():
        key, _, value = line.partition(' ')
        sshd.setdefault(key, value.strip())
    ports = {line.split()[1] for line in effective.splitlines() if line.startswith('port ')}
    _, listeners = run(['ss', '-H', '-ltnp', 'sport = :' + port])
    controls = {}
    controls['moved_port'] = port != '22' and port in ports and 'sshd' in listeners
    controls['honeypot'] = on(fw.get('SSH_HONEYPOT')) and unit_state('tarasec-ssh-honeypot.service').get('ActiveState') == 'active'
    controls['key_login'] = sshd.get('pubkeyauthentication') == 'yes' and authorized_keys(root)
    methods = sshd.get('authenticationmethods', 'any')
    controls['no_password_only'] = code == 0 and (
        (sshd.get('passwordauthentication') == 'no' and sshd.get('kbdinteractiveauthentication') == 'no')
        or (methods != 'any' and all('publickey' in chain for chain in methods.split())))
    window = on(manager.get('SSH_GOOGLE_REOPEN_ENABLED')) and (root / 'etc/tarasec/agent-enrollment.json').is_file()
    if window and port.isdigit():
        for tool in ('iptables', 'ip6tables'):
            rc, _ = run([tool, '-w', '5', '-C', 'INPUT', '-p', 'tcp', '--dport', port,
                         '-m', 'conntrack', '--ctstate', 'NEW', '-j', 'TARASEC_SSH_GOOGLE'])
            window = window and rc == 0
    controls['google_window'] = bool(window)
    worker = unit_state('tarasec-manager-ssh.service')
    if worker.get('LoadState') == 'loaded' and (root / 'etc/tarasec/manager-ssh.enabled').exists():
        controls['manager_ssh'] = worker.get('Result') == 'success'
    missing = [name for name, ok in controls.items() if not ok]
    return {'checked_at': int(time.time()), 'controls': controls, 'missing': missing,
            'missing_labels': [LABELS[name] for name in missing]}


def cached(max_age=MAX_AGE):
    try:
        value = json.loads(CACHE.read_text())
        if 0 <= time.time() - value['checked_at'] < max_age:
            return value
    except (OSError, ValueError, KeyError, TypeError):
        pass
    value = collect()
    try:
        CACHE.parent.mkdir(parents=True, exist_ok=True)
        tmp = CACHE.with_suffix('.tmp')
        tmp.write_text(json.dumps(value))
        os.replace(tmp, CACHE)
    except OSError:
        pass
    return value


if __name__ == '__main__':
    if os.geteuid() != 0:
        raise SystemExit('Run as root')
    print(json.dumps(collect() if '--fresh' in sys.argv else cached()))
