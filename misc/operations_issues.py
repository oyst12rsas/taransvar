"""Durable issue lifecycle from deterministic observations, never model claims."""
import hashlib
import json
import time
import uuid
from pathlib import Path


def event(issue, kind, now, detail):
    history = issue.setdefault('history', [])
    serial = issue.get('sequence', 0) + 1
    issue['sequence'] = serial
    history.append(dict(event_id=serial, at=now, kind=kind, detail=detail))


def observe(state, category, findings, now=None, complete=True):
    """Only call with a fresh, successful check; absent evidence is not recovery."""
    now = time.time() if now is None else now
    issues = state.setdefault('issues', {})
    for key, description in findings.items():
        identity = category + ':' + key
        item = issues.get(identity)
        if item is None:
            item = dict(key=identity, description=description, discovered_at=now,
                        status='open', next_action_at=now, commands=[], verification=category,
                        history=[], sequence=0, incarnation=uuid.uuid4().hex)
            issues[identity] = item
            event(item, 'discovered', now, description)
        elif item['status'] == 'solved':
            item.update(status='open', solved_at=None, next_action_at=now)
            event(item, 'reopened', now, description)
        item.update(last_seen_at=now, checked_at=now, verification_checked_at=now)
    for identity, item in issues.items():
        if complete and identity.startswith(category + ':') and identity.split(':', 1)[1] not in findings and item['status'] != 'solved':
            item.update(status='solved', solved_at=now, checked_at=now, verification_checked_at=now, next_action_at=None,
                        blocker=None)
            event(item, 'verified_solved', now, 'Fresh ' + category + ' check no longer detects the issue.')


def progress(state, now=None):
    now = time.time() if now is None else now
    status = state.get('status')
    if status in ('inspecting', 'diagnosing', 'monitoring', 'resource_attention'):
        return
    decision = state.get('issue_decision', {})
    result = state.get('last_action_result', {})
    detail = {'assessment_at': state.get('last_model_attempt_at'), 'worker_status': status, 'blocker': state.get('error_detail') or state.get('decision_feedback'),
              'action': decision.get('action'), 'exit_code': result.get('exit_code'),
              'next_check': 'Continue repair or verify with fresh ' + ', '.join(sorted({i.get('verification', '') for i in state.get('issues', {}).values() if i['status'] != 'solved'}))}
    digest = hashlib.sha256(json.dumps(detail, sort_keys=True).encode()).hexdigest()
    for item in state.get('issues', {}).values():
        if item['status'] == 'solved' or item.get('progress_fingerprint') == digest:
            continue
        selected = state.get('active_issue_key') in (None, item['key'])
        item['progress_fingerprint'] = digest
        item['status'] = ('verification_pending' if selected and status in ('command_executed', 'procedure_ok', 'resource_ok')
                          else 'blocked' if status in ('blocked', 'model_stalled', 'model_budget_deferred', 'command_failed', 'procedure_failed')
                          else 'pending')
        item['checked_at'] = now
        item['blocker'] = detail['blocker'] or (status if item['status'] == 'blocked' else None)
        item['next_action_at'] = now + (3600 if status == 'model_budget_deferred' else 900)
        # Commands stay local: model argv may contain sensitive data.
        if selected and decision.get('argv'):
            item['commands'] = decision['argv']
        event(item, 'progress', now, detail if selected else dict(worker_status=status, action='other_issue'))


def report(state, acknowledgements=None):
    if acknowledgements is None:
        try:
            acknowledgements = json.loads(Path('/var/lib/tarasec-operations/issue-ack.json').read_text())
        except (OSError, ValueError):
            acknowledgements = {}
    if not isinstance(acknowledgements, dict):
        acknowledgements = {}
    result = []
    for item in state.get('issues', {}).values():
        public = {k: v for k, v in item.items() if k not in ('commands', 'progress_fingerprint', 'history')}
        ack = acknowledgements.get(item.get('incarnation'), 0)
        ack = ack if isinstance(ack, int) and not isinstance(ack, bool) else 0
        public['history'] = [e for e in item.get('history', []) if e['event_id'] > ack][:16]
        public['command_executable'] = item.get('commands', [None])[0] if item.get('commands') else None
        result.append(public)
    return sorted(result, key=lambda x: (not bool(x['history']), x['status'] == 'solved', x['discovered_at']))[:64]
