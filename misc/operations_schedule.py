"""Local cheap trigger selection and durable per-call model budgets."""
import time

DEFAULTS = dict(periodic_review_seconds=0, cooldown_seconds=900,
                worsening_cooldown_seconds=300, max_calls_per_hour=8,
                max_calls_per_day=32, continuation_seconds=300, resource_retry_seconds=900,
                disk_growth_bytes_per_hour=268435456)

def limits(policy):
    config = dict(DEFAULTS, **policy.get('model_schedule', {}))
    for name in DEFAULTS:
        value = config[name]
        if not isinstance(value, int) or isinstance(value, bool) or value < 0:
            raise ValueError('Invalid model schedule setting')
    return config

def choose_trigger(state, policy, evidence, failed_services, now, requested=False):
    cfg = limits(policy)
    last = state.get('last_model_attempt_at', 0)
    elapsed = now - last
    old = state.get('last_model_pressure', {})
    worsening = (evidence.get('disk_used_percent', 0) >= old.get('disk_used_percent', 100) + 2
        or evidence.get('memory_available_percent', 100) <= old.get('memory_available_percent', 0) - 5)
    previous = state.get('resource_sample')
    growth = False
    if isinstance(previous, dict) and 60 <= now - previous.get('at', now) <= 7200:
        delta = evidence.get('disk_used_bytes', 0) - previous.get('disk_used_bytes', 0)
        growth = delta * 3600 / (now - previous['at']) >= cfg['disk_growth_bytes_per_hour'] > 0
    state['resource_sample'] = dict(at=now, disk_used_bytes=evidence.get('disk_used_bytes', 0))
    failed = sorted(failed_services)
    new_failure = failed and failed != state.get('last_model_failed_services', [])
    if requested:
        reason = 'owner_request'
    elif evidence.get('triggered') and (not old.get('triggered') or worsening):
        reason = 'resource_pressure_worsened' if worsening else 'resource_threshold_crossed'
    elif evidence.get('reboot_required') and not old.get('reboot_required'):
        reason = 'reboot_required'
    elif growth:
        reason = 'rapid_disk_growth'
    elif new_failure:
        reason = 'new_service_failure'
    elif ((state.get('last_assessment_status') in ('command_executed', 'procedure_ok')
                or state.get('continuation_ready') is True)
          and elapsed >= max(60, cfg['continuation_seconds'])):
        reason = 'verify_completed_action'
    elif evidence.get('triggered') and elapsed >= max(60, cfg['resource_retry_seconds']):
        reason = 'persistent_resource_pressure'
    elif any(item.get('status') != 'solved' and isinstance(item.get('next_action_at'), (int, float))
             and now >= item['next_action_at'] for item in state.get('issues', {}).values()):
        reason = 'pending_issue'
    elif cfg['periodic_review_seconds'] and elapsed >= cfg['periodic_review_seconds']:
        reason = 'periodic_review_due'
    else:
        return None
    cooldown = cfg['worsening_cooldown_seconds'] if worsening else cfg['cooldown_seconds']
    if not requested and last and 0 <= elapsed < cooldown:
        return None
    return reason

def reserve_call(state, policy, now=None):
    now = time.time() if now is None else now
    cfg = limits(policy)
    calls = [stamp for stamp in state.get('model_call_times', []) if 0 <= now - stamp < 86400]
    state['model_call_times'] = calls
    if (len(calls) >= cfg['max_calls_per_day'] or
            sum(now - stamp < 3600 for stamp in calls) >= cfg['max_calls_per_hour']):
        return False
    calls.append(now)
    return True


def choose_issue(state, now, requested=False):
    """Rotate due work by last assessment; discovery order only breaks ties."""
    pending = [item for item in state.get('issues', {}).values()
               if item.get('status') != 'solved']
    due = [item for item in pending if isinstance(item.get('next_action_at'), (int, float))
           and not isinstance(item['next_action_at'], bool) and item['next_action_at'] <= now]
    candidates = due or (pending if requested else [])
    def priority(item):
        # Upgrade existing state without assessing its oldest issue once again.
        previous = (state.get('last_assessment_checked_at', 0)
                    if not state.get('fair_issue_queue') and state.get('active_issue_key') == item['key'] else 0)
        return (item.get('last_assessed_at', previous), item['discovered_at'], item['key'])
    return min(candidates, key=priority)['key'] if candidates else None


def activate_issue(state, key):
    """Keep another issue's old model goal from overriding the selected work."""
    previous = state.get('active_issue_key')
    if not state.get('fair_issue_queue'):
        item = state.get('issues', {}).get(previous)
        if item and state.get('last_assessment_checked_at'):
            item.setdefault('last_assessed_at', state['last_assessment_checked_at'])
        state['fair_issue_queue'] = True
    if previous != key:
        tasks = state.setdefault('issue_task_states', {})
        if previous:
            tasks[previous] = state.get('task_state', {})
        state['task_state'] = tasks.get(key, {}) if key else {}
    state['active_issue_key'] = key


def mark_issue_assessed(state, now):
    item = state.get('issues', {}).get(state.get('active_issue_key'))
    if item and item['status'] != 'solved':
        item['last_assessed_at'] = now
