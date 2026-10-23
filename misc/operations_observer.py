"""Routine activity readiness and bounded recovery, independent of model/reboot."""
import json
from pathlib import Path
from operations_policy import maintenance_enabled
from operations_reboot import activity_blockers

DEFAULT_PROBE = '/usr/local/lib/tarasec-operations/activity-probe'
UNIT = 'tarasec-operations-activity.service'


def maintain(state, policy, now, run, trusted, persist, root=Path('/')):
    probe = policy.get('quiet_probe', DEFAULT_PROBE)
    sample = {}
    try:
        result = run([str(trusted(probe))], 20)
        value = json.loads(result['output']) if result['exit_code'] == 0 else {}
        if isinstance(value, dict): sample = value
    except (OSError, ValueError, KeyError, TypeError):
        pass
    state['activity'] = sample
    stamp = sample.get('checked_at')
    fresh = (isinstance(stamp, (int, float)) and not isinstance(stamp, bool)
             and 0 <= now - stamp <= 30)
    ready = (fresh and sample.get('complete') is True
             and isinstance(sample.get('active_demo'), bool)
             and isinstance(sample.get('meaningful_traffic'), bool))
    status = {'checked_at': now, 'status': 'ready' if ready else 'unavailable',
              'blockers': [] if ready else activity_blockers(sample, now)}
    state['activity_observer'] = status
    # A configured custom probe has its own owner-managed lifecycle.
    if ready or probe != DEFAULT_PROBE:
        return status
    try:
        config = json.loads(trusted(root / 'etc/tarasec/operations-activity.json').read_text())
        if not isinstance(config, dict): raise ValueError('Invalid configuration')
        if not config.get('demo_url'):
            status['blockers'] = ['demo_feed_url_not_configured']
            return status
        if not trusted(config.get('demo_key_file', '/etc/tarasec/operations-activity.key')).is_file():
            raise ValueError('Missing key')
    except (OSError, ValueError, KeyError, TypeError):
        status['blockers'] = ['demo_configuration_or_key_missing']
        return status
    if not maintenance_enabled(policy):
        return status
    try:
        active = run(['systemctl', 'is-active', '--quiet', UNIT], 10)['exit_code'] == 0
        if active and fresh:
            return status  # Let the continuous collector retry its feed normally.
        if now - state.get('activity_observer_repair_at', -300) < 300:
            return status
        state['activity_observer_repair_at'] = now
        persist()  # Persist before dispatch so failures cannot restart every tick.
        argv = ['systemctl', 'restart', UNIT] if active else ['systemctl', 'enable', '--now', UNIT]
        repaired = run(argv, 30)
        status['status'] = 'starting' if repaired['exit_code'] == 0 else 'repair_failed'
        status['repair_exit_code'] = repaired['exit_code']
        # Starting a service is not proof of fresh activity coverage.
    except (OSError, ValueError, KeyError, TypeError):
        status['status'] = 'repair_failed'
    return status
