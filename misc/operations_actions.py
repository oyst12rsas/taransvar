"""Owner-configured demo commands and typed production resource protection."""
import os
from pathlib import Path
import re
import stat


def validate_command(decision, policy):
    if (policy.get('mode') != 'demo' or policy.get('execute') is not True
            or policy.get('allow_experimental_commands') is not True):
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


def pressure(config):
    disk = os.statvfs('/')
    used = 100 * (disk.f_blocks - disk.f_bfree) / max(1, disk.f_blocks - disk.f_bfree + disk.f_bavail)
    memory = dict((line.split(':')[0], int(line.split()[1]))
                  for line in Path('/proc/meminfo').read_text().splitlines())
    available = 100 * memory['MemAvailable'] / max(1, memory['MemTotal'])
    load = os.getloadavg()[0] / max(1, os.cpu_count() or 1)
    values = {'disk_used_percent': round(used, 2), 'memory_available_percent': round(available, 2),
              'load_per_cpu': round(load, 2)}
    values['triggered'] = (used >= float(config.get('disk_used_percent', 85))
        or available <= float(config.get('memory_available_percent', 10))
        or load >= float(config.get('load_per_cpu', 2)))
    return values


def validate_resource(decision, policy, evidence):
    config = policy.get('resource_protection', {})
    if (policy.get('mode') not in ('demo', 'production') or policy.get('execute') is not True
            or config.get('enabled') is not True or evidence.get('triggered') is not True):
        raise ValueError('Resource action requires enabled owner policy and measured pressure')
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
