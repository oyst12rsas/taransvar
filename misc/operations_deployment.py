"""Bounded deployment inventory. Never return cron text, configuration or key contents."""
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import time

UNITS = ('tarasec-agent-approvals.service', 'tarasec-agent-approvals.timer',
         'tarasec-operations-agent.service', 'tarasec-operations-agent.timer',
         'tarasec-operations-activity.service', 'tarasec-gateway-ai.timer',
         'tarasec-manager-requests.timer', 'tarasec-node-coordinator.service',
         'tarasec-minute-reporter.service')

def file_metadata(path):
    try:
        info = path.lstat()
        return {'present': True, 'regular_file': stat.S_ISREG(info.st_mode),
                'bytes': info.st_size, 'modified_at': info.st_mtime}
    except FileNotFoundError:
        return {'present': False}
    except OSError:
        return {'present': None, 'error': 'metadata_unavailable'}


def system_cron_reporters(root):
    """Inspect bounded system cron files without returning commands or secrets."""
    paths = [root / 'etc/crontab']
    directory = root / 'etc/cron.d'
    complete = True
    try:
        entries = sorted(directory.iterdir()) if directory.exists() else []
        if len(entries) > 128: complete = False
        paths += [p for p in entries[:128] if re.fullmatch(r'[A-Za-z0-9_-]+', p.name)]
    except OSError:
        complete = False
    reporters = []
    for path in paths:
        try:
            info = path.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o022:
                continue
            # The root override exists for isolated filesystem tests.
            if root == Path('/') and info.st_uid != 0: continue
            if info.st_size > 65536:
                complete = False
                continue
            for line in path.read_text(errors='replace').splitlines():
                if line.lstrip().startswith('#'): continue
                fields = line.split(None, 6)
                if (len(fields) != 7 or fields[5] != 'root'
                        or fields[0] not in ('*', '*/1') or fields[1:5] != ['*'] * 4):
                    continue
                for match in re.finditer(r'(?<![\w/])(/(?:root|home)/[A-Za-z0-9_./-]+/crontasks\.pl)(?=\s|$)', fields[6]):
                    reporter = match.group(1)
                    if '..' not in Path(reporter).parts and reporter not in reporters:
                        reporters.append(reporter)
        except FileNotFoundError:
            continue
        except OSError:
            complete = False
    return reporters, complete


def deployment_inventory(run, root=Path('/'), now=None):
    now = time.time() if now is None else now
    root = Path(root)
    local = lambda path: root / path.lstrip('/')
    cron = run(['/usr/bin/crontab', '-l'], 10)
    reporters = []
    if cron['exit_code'] == 0:
        for line in cron.get('output', '').splitlines():
            if line.lstrip().startswith('#'):
                continue
            # Return only known reporter paths, never arbitrary cron arguments.
            for match in re.finditer(r'(?<![\w/])(/(?:root|home)/[A-Za-z0-9_./-]+/crontasks\.pl)(?=\s|$)', line):
                path = match.group(1)
                if '..' not in Path(path).parts and path not in reporters:
                    reporters.append(path)
    cron_complete = cron['exit_code'] == 0
    system_reporters, system_cron_complete = system_cron_reporters(root)
    for path in system_reporters:
        if path not in reporters: reporters.append(path)
    coordinator_configured = False
    config = local('/etc/tarasec/node-coordinator.json')
    meta = file_metadata(config)
    if meta.get('regular_file') and meta['bytes'] <= 4096:
        try:
            path = json.loads(config.read_text()).get('reporter', '')
            if isinstance(path, str) and re.fullmatch(r'/(?:root|home)/[A-Za-z0-9_./-]+/crontasks\.pl', path) and '..' not in Path(path).parts:
                coordinator_configured = True
                if path not in reporters:
                    reporters.append(path)
        except (ValueError, OSError, AttributeError):
            pass
    reporters = reporters[:8]
    manifest = {}
    manifest_path = local('/usr/local/lib/tarasec-operations/deployment-reference.json')
    meta = file_metadata(manifest_path)
    if meta.get('regular_file') and meta['bytes'] <= 32768:
        try:
            manifest = json.loads(manifest_path.read_text())
            if not isinstance(manifest, dict):
                manifest = {}
        except (ValueError, OSError):
            pass
    expected = manifest.get('reporter_sha256')
    inventory = []
    for path in reporters:
        source = local(path)
        item = dict(path=path, **file_metadata(source))
        if item.get('regular_file') and item['bytes'] <= 1024 * 1024:
            try:
                data = source.read_bytes()
                text = data.decode('utf-8', errors='replace')
                digest = hashlib.sha256(data).hexdigest()
                item.update(sha256=digest,
                    security_status_field=bool(re.search(r'[\"\x27]aiAgent[\"\x27]', text)),
                    operations_status_field=bool(re.search(r'[\"\x27]operationsAgent[\"\x27]', text)),
                    matches_deployed_source_reference=(digest == expected) if expected else None)
            except OSError:
                item['inspection_error'] = 'reporter_unreadable'
        inventory.append(item)
    files = {name: file_metadata(local(path)) for name, path in {
        'security_worker': '/usr/local/lib/tarasec/agent_approval_worker.py',
        'security_config': '/etc/tarasec-server-manager.conf',
        'security_snapshot': '/var/lib/tarasec/agent-status.json',
        'security_enrollment': '/etc/tarasec/agent-node.token',
        'operations_worker': '/usr/local/lib/tarasec-operations/operations_agent.py',
    }.items()}
    # Token existence and non-empty regular-file status only: do not open it.
    files['security_enrollment']['nonempty_regular_file'] = (
        files['security_enrollment'].get('regular_file') is True and
        files['security_enrollment'].get('bytes', 0) > 0)
    units = {}
    for name in UNITS:
        response = run(['/usr/bin/systemctl', 'show', name, '--no-pager',
            '--property=LoadState,ActiveState,SubState,UnitFileState,Result,ExecMainStatus'], 5)
        allowed = {'LoadState', 'ActiveState', 'SubState', 'UnitFileState', 'Result', 'ExecMainStatus'}
        units[name] = {key: value for line in response.get('output', '').splitlines()
                      for key, _, value in [line.partition('=')] if key in allowed}
        units[name]['query_exit_code'] = response['exit_code']
    findings = []
    if not reporters:
        findings.append('minute_reporter_not_found_in_cron' if cron_complete and system_cron_complete
                        else 'minute_reporter_inventory_unknown')
    if any(item.get('security_status_field') is False for item in inventory):
        findings.append('minute_reporter_missing_security_status_field')
    if any(item.get('operations_status_field') is False for item in inventory):
        findings.append('minute_reporter_missing_operations_status_field')
    for name in ('security_worker', 'security_config', 'security_snapshot'):
        if files[name]['present'] is False:
            findings.append(name + '_missing')
    if not files['security_enrollment']['nonempty_regular_file']:
        findings.append('security_enrollment_missing_or_unverified')
    coordinator_active = units['tarasec-node-coordinator.service'].get('ActiveState') == 'active'
    if coordinator_configured and not coordinator_active:
        findings.append('tarasec-node-coordinator.service_not_active')
    for name in (() if coordinator_active else ('tarasec-agent-approvals.timer', 'tarasec-operations-agent.timer')):
        if units[name].get('query_exit_code') != 0:
            findings.append(name + '_state_unknown')
        elif units[name].get('LoadState') == 'not-found':
            findings.append(name + '_not_installed')
        elif units[name].get('ActiveState') != 'active':
            findings.append(name + '_not_active')
    return {'checked_at': int(now), 'root_cron_inspected': cron_complete,
            'system_cron_inspected': system_cron_complete,
            'reporters': inventory, 'components': files, 'units': units, 'findings': findings,
            'source_reference': {key: manifest.get(key) for key in ('revision', 'reference_kind')},
            'limitations': ['Reporter field detection is static; DB receipt is unverified.',
                'Deployed source reference is not release approval or the current repository head.',
                'Inactive oneshot services between timer runs are normal.',
                'Disabled operations timer may be intentional during pilot testing.']}
