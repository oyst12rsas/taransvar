"""Typed automatic maintenance. DB advisories cannot select commands or widen policy."""
import json
from pathlib import Path
import time
from operations_policy import maintenance_enabled
from operations_issues import observe
from operations_reboot import session_activity

NOTICE = Path('/var/lib/tarasec/upgrade/notice.json')
SOFTWARE = Path('/var/lib/tarasec/upgrade/software.json')
LAUNCHER = Path('/usr/local/sbin/tarasec')
MODE = Path('/etc/tarasec-deployment-mode')


def read_notice(path, now):
    if path.stat().st_size > 16384: raise ValueError('Oversized upgrade receipt')
    data = json.loads(path.read_text())
    stamp = data.get('checked_at')
    if type(stamp) not in (int, float) or not 0 <= now - stamp <= 180:
        raise ValueError('Upgrade receipt is stale')
    if data.get('catalog_status') != 'ok' or not isinstance(data.get('pending'), list) or len(data['pending']) > 8:
        raise ValueError('Upgrade notice catalog unavailable')
    result = []
    for item in data['pending']:
        if not isinstance(item, dict) or type(item.get('minimum_version')) is not int or item['minimum_version'] < 1 or item.get('severity') not in ('security', 'important', 'routine'):
            raise ValueError('Invalid upgrade notice')
        if not isinstance(item.get('id'), str) or not isinstance(item.get('note'), str) or len(item['note']) > 192:
            raise ValueError('Invalid upgrade notice text')
        result.append(item)
    return result


def maintain(state, policy, now, run, trusted, quiet, persist, record):
    try:
        pending = read_notice(trusted(NOTICE), now)
    except (OSError, ValueError, TypeError, KeyError):
        state['upgrade'] = {'status': 'unknown', 'reason': 'No fresh primary DB upgrade receipt'}
        return False  # Unknown evidence must not close an existing upgrade issue.
    observe(state, 'upgrade', {str(n['id']): n['note'] for n in pending}, now)
    state['upgrade'] = {'status': 'pending' if pending else 'current', 'notices': pending}
    if not pending: return False
    if not maintenance_enabled(policy):
        state['upgrade']['reason'] = 'Inspection mode does not authorize upgrades'
        return False
    try:
        mode = trusted(MODE).read_text().strip()
        if mode == 'production' or policy.get('mode') == 'production':
            state['upgrade'].update(status='awaiting_confirmation',
                reason='Production requires user confirmation for each upgrade; run sudo tarasec upgrade')
            return False
        if mode != 'demo' or policy.get('mode') != 'demo':
            state['upgrade']['reason'] = 'Automatic upgrades require demo mode in both local policies'
            return False
        launcher = trusted(LAUNCHER.resolve())
        # Reject writable checkout ancestors as well as a writable launcher.
        trusted(launcher.parent / 'misc/standard_upgrade.py')
        today = int(now // 86400)
        attempts = state.setdefault('upgrade_attempts', {'day': today, 'count': 0})
        if attempts.get('day') != today: attempts.update(day=today, count=0)
        if attempts['count'] >= 3 or now - state.get('last_upgrade_at', 0) < 3600:
            state['upgrade']['reason'] = 'Automatic upgrade retry budget/cooldown'
            return False
        probe = trusted(policy['quiet_probe'])
        observed = run([str(probe)], timeout=20)
        sample = json.loads(observed['output']) if observed['exit_code'] == 0 else {}
        if not quiet(state, sample, now, max(60, int(policy.get('quiet_seconds', 300)))):
            state['upgrade']['reason'] = 'Waiting for quiet activity evidence'
            return False
        if session_activity(run, policy)['blockers']:
            state['upgrade']['reason'] = 'Interactive sessions active or unknown'
            return False
        state['last_upgrade_at'] = now
        attempts['count'] += 1
        state['upgrade']['status'] = 'running'
        persist()
        record({'automatic_upgrade_start': True})
        result = run([str(LAUNCHER), 'upgrade', '--automatic'], timeout=720)
        state['upgrade'].update(status='verification_pending' if result['exit_code'] == 0 else 'failed',
                                exit_code=result['exit_code'])
        record({'automatic_upgrade_result': {'exit_code': result['exit_code']}})
        persist()
        # Do not mark resolved from command exit: the next DB heartbeat must clear it.
        return True
    except (OSError, ValueError, TypeError, KeyError) as error:
        state['upgrade'].update(status='blocked', reason='Local upgrade prerequisites unavailable: ' + type(error).__name__)
        persist()
        return False
