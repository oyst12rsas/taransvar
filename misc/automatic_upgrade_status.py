#!/usr/bin/env python3
"""Read-only, credential-free evidence for the automatic-maintenance dot."""
import json
import os
import tempfile
import subprocess
import time
from pathlib import Path


def collect(root=Path('/'), probe=None, now=None):
    now = time.time() if now is None else now
    def systemd(unit, property):
        try:
            return subprocess.run(['systemctl', 'show', unit, '--property='+property, '--value'],
                                  capture_output=True, text=True, timeout=2, check=True).stdout.strip()
        except (OSError, subprocess.SubprocessError):
            return 'unknown'
    probe = probe or systemd
    enrolled = (root/'etc/tarasec/managed-upgrade.json').is_file()
    result = dict(enrolled=enrolled, checked_at=int(now))
    if not enrolled:
        return result
    timer = 'tarasec-managed-upgrade.timer'
    service = 'tarasec-managed-upgrade.service'
    result.update(timer_enabled=probe(timer, 'UnitFileState'),
                  timer_active=probe(timer, 'ActiveState'),
                  service_active=probe(service, 'ActiveState'),
                  service_result=probe(service, 'Result'))
    try:
        ended = int(probe(service, 'ExecMainExitTimestampMonotonic')) / 1_000_000
        if ended > 0:
            result['last_run_age_seconds'] = max(0, int(time.monotonic() - ended))
    except (ValueError, TypeError):
        pass
    try:
        state = json.loads((root/'var/lib/tarasec/upgrade/state.json').read_text())
        managed = state.get('managed_upgrade', {})
        scheduler = managed.get('scheduler', {})
        result['scheduler_status'] = scheduler.get('status', 'unknown')
        result['last_check_at'] = scheduler.get('checked_at')
    except (OSError, ValueError, AttributeError):
        result['scheduler_status'] = 'unknown'
    return result


if __name__ == '__main__':
    import sys
    result = collect()
    if sys.argv[1:] == ['--write']:
        destination = Path('/var/lib/tarasec/upgrade/automatic-status.json')
        destination.parent.mkdir(parents=True, exist_ok=True)
        fd, temporary = tempfile.mkstemp(prefix='automatic-', dir=destination.parent)
        try:
            with os.fdopen(fd, 'w') as target:
                os.fchmod(target.fileno(), 0o644)
                json.dump(result, target)
                target.write('\n')
            os.replace(temporary, destination)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
    elif not sys.argv[1:]:
        print(json.dumps(result))
    else:
        raise SystemExit('Usage: automatic_upgrade_status.py [--write]')
