"""Owner-configured demo commands and typed production resource protection."""
import os
import fcntl
from pathlib import Path
import re
import stat
from operations_policy import maintenance_enabled, experimental_enabled


def validate_command(decision, policy):
    if not experimental_enabled(policy):
        raise ValueError('Experimental commands require explicit demo authorization')
    argv = decision.get('argv')
    if (not isinstance(argv, list) or not 1 <= len(argv) <= 64
            or any(not isinstance(x, str) or not x or '\x00' in x or len(x) > 4096 for x in argv)
            or not argv[0].startswith('/')):
        raise ValueError('Command must be a bounded argv array with an absolute executable')
    # This is explicit root authority, not a sandbox: scripts/interpreters can do more.
    if argv[0] in ('/sbin/reboot', '/sbin/shutdown', '/usr/sbin/reboot', '/usr/sbin/shutdown'):
        raise ValueError('Use the separately governed reboot action')
    if Path(argv[0]).name == 'systemctl' and any(x in argv for x in ('reboot', 'poweroff', 'halt', 'kexec')):
        raise ValueError('Use the separately governed reboot action')
    for field in ('reason', 'expected_result', 'recovery_plan'):
        if not isinstance(decision.get(field), str) or not decision[field].strip():
            raise ValueError('Command requires reason, expected result and recovery plan')
    return argv



def read_only_command(argv, results):
    """Only fixed syntax inspection of the observed gateway file skips quiet gating."""
    executable = results.get('gateway_startup', {}).get('result', {}).get('properties', {}).get('executable', {})
    path = executable.get('path')
    return (isinstance(path, str) and path.startswith('/') and executable.get('regular_file') is True
            and argv in (['/usr/bin/bash', '-n', path], ['/bin/bash', '-n', path]))


def pressure(config):
    disk = os.statvfs('/')
    used = 100 * (disk.f_blocks - disk.f_bfree) / max(1, disk.f_blocks - disk.f_bfree + disk.f_bavail)
    memory = dict((line.split(':')[0], int(line.split()[1]))
                  for line in Path('/proc/meminfo').read_text().splitlines())
    available = 100 * memory['MemAvailable'] / max(1, memory['MemTotal'])
    load = os.getloadavg()[0] / max(1, os.cpu_count() or 1)
    values = {'disk_used_bytes': (disk.f_blocks - disk.f_bfree) * disk.f_frsize,
              'disk_available_bytes': disk.f_bavail * disk.f_frsize, 'disk_used_percent': round(used, 2), 'memory_available_percent': round(available, 2),
              'load_per_cpu': round(load, 2)}
    values['disk_threshold_percent'] = float(config.get('disk_used_percent', 85))
    values['triggered'] = (used >= float(config.get('disk_used_percent', 85))
        or available <= float(config.get('memory_available_percent', 10))
        or load >= float(config.get('load_per_cpu', 2)))
    return values


def validate_resource(decision, policy, evidence):
    config = policy.get('resource_protection', {})
    if not maintenance_enabled(policy) or evidence.get('triggered') is not True:
        raise ValueError('Resource action requires Demo or Production mode and measured pressure')
    operation = decision.get('operation')
    target = decision.get('target')
    if operation == 'delete_log':
        if target not in config.get('disposable_logs', []):
            raise ValueError('Log is not explicitly disposable')
        path = Path(target)
        if not path.is_absolute() or '..' in path.parts or not str(path).startswith('/var/log/'):
            raise ValueError('Disposable logs must be explicit paths under /var/log')
        for part in [path, *path.parents]:
            if part.is_symlink():
                raise ValueError('Symlink log paths are prohibited')
        if not stat.S_ISREG(path.stat().st_mode):
            raise ValueError('Disposable log must be a regular file')
    elif operation == 'stop_service':
        protected = {'ssh.service', 'sshd.service', 'rsyslog.service', 'systemd-journald.service',
                     'netbird.service', 'tarasec-gateway.service', 'taralink.service',
                     'tarasec-operations-agent.service', 'tarasec-operations-activity.service'}
        protected.update(config.get('protected_services', []))
        if target not in config.get('stoppable_services', []) or target in protected:
            raise ValueError('Service is not designated nonessential')
        if not re.fullmatch(r'[A-Za-z0-9_.@-]+\.service', target):
            raise ValueError('Invalid service name')
    elif operation == 'request_assistance':
        if config.get('allow_request_assistance') is not True or not config.get('assistance_executable'):
            raise ValueError('Assistance adapter not configured')
    else:
        raise ValueError('Unknown resource operation')
    return config


def perform_resource(decision, config, run, trusted):
    operation, target = decision['operation'], decision.get('target')
    if operation == 'delete_log':
        # Truncate the authorized disposable file: writers retain their inode.
        fd = os.open(target, os.O_WRONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                raise ValueError('Log changed type')
            before = os.fstat(fd).st_size
            os.ftruncate(fd, 0)
            os.fsync(fd)
            return {'exit_code': 0, 'before_bytes': before, 'after_bytes': os.fstat(fd).st_size,
                    'method': 'truncate authorized disposable log'}
        finally:
            os.close(fd)
    if operation == 'stop_service':
        result = run(['/usr/bin/systemctl', 'stop', target], 30)
        check = run(['/usr/bin/systemctl', 'show', target, '--property=ActiveState', '--value'], 10)
        result['verification'] = check
        if check['output'].strip() not in ('inactive', 'failed'):
            result['exit_code'] = 1
        return result
    return run([str(trusted(config['assistance_executable']))], 30)


def prune_upgrade_dumps(directory=Path('/var/lib/tarasec/upgrade')):
    """Retain two newest completed SQL dumps; never touch other recovery files."""
    result = {'removed': 0, 'freed_bytes': 0, 'status': 'unchanged'}
    try:
        if not directory.exists():
            return result
        if directory.resolve() != directory.absolute():
            return dict(result, status='unsafe_path')
        if (directory / 'upgrade.lock').is_symlink():
            return dict(result, status='unsafe_path')
        with (directory / 'upgrade.lock').open('a') as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return dict(result, status='upgrade_busy')
            complete = []
            for path in sorted((directory / 'backups').glob('*/database/taransvar.sql')):
                if (not re.fullmatch(r'[0-9]{8}T[0-9]{6}\.[0-9]{6}Z', path.parent.parent.name)
                        or path.resolve() != path.absolute()
                        or not stat.S_ISREG(path.lstat().st_mode)):
                    continue
                with path.open('rb') as stream:
                    info = os.fstat(stream.fileno())
                    if not stat.S_ISREG(info.st_mode):
                        continue
                    stream.seek(max(0, info.st_size - 8192))
                    if b'-- Dump completed on' in stream.read():
                        complete.append((path, info))
            for path, info in complete[:-2]:
                current = path.lstat()
                if (current.st_dev, current.st_ino, current.st_size, current.st_mtime_ns) != (
                        info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns):
                    continue
                path.unlink()
                result['removed'] += 1
                result['freed_bytes'] += info.st_size
            result['status'] = 'completed'
    except OSError:
        result['status'] = 'filesystem_error'
    return result


def automatic_log_rotation(policy, evidence, state, now, run, save, record):
    """Apply installed retention rules before any model scheduling or budget check."""
    config = policy.get('resource_protection', {})
    if (not maintenance_enabled(policy)
            or evidence.get('disk_used_percent', 0) < float(config.get('disk_used_percent', 85))
            or now - state.get('last_log_rotation_at', -900) < 900):
        return False
    # Persist before starting so failures/timeouts cannot cause a retry every tick.
    state['last_log_rotation_at'] = now
    save()
    record({'automatic_log_rotation_start': True})
    result = run(['/usr/sbin/logrotate', '/etc/logrotate.conf'], 120)
    after = pressure(config)
    cache_result = None
    if after['disk_used_percent'] >= float(config.get('disk_used_percent', 85)):
        # Package downloads are reproducible; installed packages, databases and
        # recovery configuration files remain outside this housekeeping operation.
        cache_result = run(['/usr/bin/apt-get', 'clean'], 120)
        after = pressure(config)
    backup_result = None
    if after['disk_used_percent'] >= float(config.get('disk_used_percent', 85)):
        backup_result = prune_upgrade_dumps()
        after = pressure(config)
    state['last_log_rotation'] = {
        'checked_at': now, 'exit_code': result['exit_code'],
        'upgrade_backup_cleanup': backup_result,
        'disk_used_percent_before': evidence['disk_used_percent'],
        'disk_used_percent_after': after['disk_used_percent'],
        'package_cache_exit_code': cache_result['exit_code'] if cache_result is not None else None,
    }
    state['resource_pressure'] = after
    save()
    record({'automatic_log_rotation_result': state['last_log_rotation']})
    return True
