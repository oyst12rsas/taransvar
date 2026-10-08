"""Fixed read-only diagnostics. No model-supplied paths, commands or arguments."""
import os
from pathlib import Path
import re
import stat
import time

CATALOG = {
    'large_logs': 'Find the 20 largest regular log files; metadata only, no contents.',
    'storage_summary': 'Measure allocated space under /var/log and /var/lib.',
    'gateway_startup': 'Inspect gateway service execution metadata and executable accessibility.',
}


def large_logs(root=Path('/var/log'), seconds=10, maximum=20000):
    deadline = time.monotonic() + seconds
    device = root.stat().st_dev
    largest, errors, scanned, complete = [], 0, 0, True
    def failed(error):
        nonlocal errors
        errors += 1
    for directory, dirs, files in os.walk(root, followlinks=False, onerror=failed):
        kept = []
        for name in dirs:
            try:
                info = (Path(directory) / name).lstat()
                if stat.S_ISDIR(info.st_mode) and info.st_dev == device:
                    kept.append(name)
            except OSError:
                errors += 1
        dirs[:] = kept
        for name in files:
            if time.monotonic() >= deadline or scanned >= maximum:
                complete = False
                break
            scanned += 1
            path = Path(directory) / name
            try:
                info = path.lstat()
                if stat.S_ISREG(info.st_mode) and info.st_dev == device:
                    largest.append({'path': str(path), 'bytes': info.st_size,
                                    'allocated_bytes': info.st_blocks * 512})
                    largest.sort(key=lambda item: item['allocated_bytes'], reverse=True)
                    del largest[20:]
            except OSError:
                errors += 1
        if not complete:
            break
    return {'files': largest, 'scanned': scanned, 'complete': complete and not errors,
            'errors': errors}


def gateway_startup(run):
    result = run(['systemctl', 'show', 'tarasec-gateway.service', '--no-pager',
                  '--property=LoadState,ActiveState,SubState,Result,ExecMainStatus,FragmentPath,ExecStart'], 10)
    # Never submit full ExecStart arguments, which can contain credentials.
    fields = {}
    for line in result['output'].splitlines():
        key, _, value = line.partition('=')
        if key == 'ExecStart':
            match = re.search(r'\bpath=([^ ;]+)', value)
            if match:
                executable = Path(match.group(1))
                details = {'path': str(executable), 'exists': executable.exists(),
                           'executable_by_root': os.access(executable, os.X_OK)}
                try:
                    info = executable.stat()
                    details.update(mode=oct(stat.S_IMODE(info.st_mode)), uid=info.st_uid,
                                   regular_file=stat.S_ISREG(info.st_mode))
                except OSError:
                    pass
                fields['executable'] = details
        elif key in ('LoadState', 'ActiveState', 'SubState', 'Result', 'ExecMainStatus', 'FragmentPath'):
            fields[key] = value
    return {'exit_code': result['exit_code'], 'properties': fields}


def diagnose(name, run):
    if name not in CATALOG:
        raise ValueError('Unknown read-only diagnostic')
    if name == 'large_logs':
        return large_logs()
    if name == 'gateway_startup':
        return gateway_startup(run)
    return run(['du', '-x', '-h', '--max-depth=1', '/var/log', '/var/lib'], 20)
