#!/usr/bin/env python3
"""Install bounded system log defaults while preserving explicit owner limits."""
import os
from pathlib import Path
import re
import subprocess

JOURNAL = '[Journal]\nSystemMaxUse=256M\n'

def install_defaults(root=Path('/'), effective_journal=''):
    root = Path(root)
    journal = root / 'etc/systemd/journald.conf.d/60-tarasec-retention.conf'
    changed = []
    # cat-config includes vendor, main and drop-in settings in precedence order.
    if not re.search(r'^\s*SystemMaxUse\s*=\s*\S+', effective_journal, re.M) and not journal.exists():
        journal.parent.mkdir(parents=True, exist_ok=True)
        journal.write_text(JOURNAL)
        journal.chmod(0o644)
        changed.append('journal')
    rsyslog = root / 'etc/logrotate.d/rsyslog'
    if rsyslog.is_symlink():
        raise ValueError('Refusing symlink rsyslog rotation configuration')
    if rsyslog.exists():
        original = rsyslog.read_text()
        def bound(match):
            block = match.group(0)
            if re.search(r'^\s*(?:maxsize|minsize|size)\s+\S+', block, re.M):
                return block
            return block.replace('{', '{\n    maxsize 100M', 1)
        updated = re.sub(r'^[^#\n]*\{\s*$.*?^\s*}\s*$', bound, original, flags=re.M | re.S)
        if updated != original:
            backup = root / 'var/lib/tarasec-retention/rsyslog.before-size-limit'
            backup.parent.mkdir(parents=True, exist_ok=True)
            if not backup.exists():
                backup.write_text(original)
                backup.chmod(0o600)
            temporary = rsyslog.with_name('.rsyslog.tarasec-new')
            temporary.write_text(updated)
            temporary.chmod(rsyslog.stat().st_mode & 0o777)
            temporary.replace(rsyslog)
            changed.append('rsyslog')
    return changed

def main():
    if os.geteuid() != 0:
        raise SystemExit('Run as root')
    effective = subprocess.run(['/usr/bin/systemd-analyze', 'cat-config', 'systemd/journald.conf'],
                               check=True, capture_output=True, text=True).stdout
    changed = install_defaults(effective_journal=effective)
    subprocess.run(['/usr/sbin/logrotate', '--debug', '/etc/logrotate.conf'], check=True)
    if 'journal' in changed:
        subprocess.run(['/usr/bin/systemctl', 'restart', 'systemd-journald.service'], check=True)
    subprocess.run(['/usr/bin/systemctl', 'enable', '--now', 'logrotate.timer'], check=True)
    print('System log retention defaults installed; explicit size limits preserved. No forced rotation or vacuum.')

if __name__ == '__main__':
    main()
