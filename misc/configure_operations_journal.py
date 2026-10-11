#!/usr/bin/env python3
"""Install the Audi demo journal bound; never vacuum or touch database files."""
import os
from pathlib import Path
import subprocess

CONTENT = '[Journal]\nSystemMaxUse=1G\nSystemKeepFree=2G\nCompress=yes\n'

def install_limit(directory):
    directory = Path(directory)
    directory.mkdir(mode=0o755, parents=True, exist_ok=True)
    if directory.is_symlink() or directory.stat().st_uid != 0 or directory.stat().st_mode & 0o022:
        raise ValueError('Unsafe journal configuration directory')
    target = directory / '60-tarasec-operations.conf'
    if target.is_symlink():
        raise ValueError('Journal drop-in must not be a symlink')
    if target.exists():
        if target.read_text() != CONTENT:
            raise ValueError('Existing owner journal drop-in differs; preserve it for review')
        return False
    fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644)
    with os.fdopen(fd, 'w') as stream:
        stream.write(CONTENT)
        stream.flush()
        os.fsync(stream.fileno())
    return True

if __name__ == '__main__':
    if os.geteuid() != 0:
        raise SystemExit('Run as root')
    install_limit('/etc/systemd/journald.conf.d')
    subprocess.run(['/usr/bin/systemctl', 'restart', 'systemd-journald.service'], check=True)
    subprocess.run(['/usr/bin/systemd-analyze', 'cat-config', 'systemd/journald.conf'], check=True)
    subprocess.run(['/usr/bin/journalctl', '--disk-usage'], check=True)
    print('Journal bound configured. Verify effective limits and logger health next run. No explicit vacuum performed.')
