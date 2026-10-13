"""Import local admin reports without treating their text as authority."""
import json
import re
import subprocess
from pathlib import Path
from operations_issues import event


def ingest(state, rows, now):
    issues = state.setdefault('issues', {})
    for row in rows:
        if not isinstance(row, dict) or not re.fullmatch(r'manual:[a-f0-9]{32}', row.get('issue_key', '')):
            continue
        key = row['issue_key']
        item = issues.get(key)
        if item is None:
            if row['status'] == 'solved':
                continue
            item = dict(key=key, incarnation=key.split(':')[1], description=row['description'],
                        discovered_at=float(row['discovered_at']), last_seen_at=now, checked_at=now,
                        status='open', next_action_at=now, commands=[], verification='manual',
                        history=[], sequence=0)
            issues[key] = item
            event(item, 'discovered', item['discovered_at'], row['description'])
        if row['status'] == 'solved' and item['status'] != 'solved':
            item.update(status='solved', solved_at=float(row['solved_at']), checked_at=now,
                        next_action_at=None, blocker=None)
            event(item, 'admin_verified_solved', now, 'Server administrator recorded a fresh verification check in Gatekeeper.')


def sync(state, now):
    bridge = Path(__file__).with_name('operations_manual_issues.pl')
    # Send local progress only. Model claims cannot set solved in this bridge.
    items = [{k: v for k, v in item.items() if k not in ('commands', 'progress_fingerprint')}
             for item in state.get('issues', {}).values() if item.get('verification') == 'manual']
    try:
        result = subprocess.run(['/usr/bin/perl', str(bridge)], input=json.dumps(items),
                                text=True, capture_output=True, timeout=15)
        if result.returncode:
            raise ValueError('Local issue database unavailable')
        rows = json.loads(result.stdout)
        if not isinstance(rows, list):
            raise ValueError('Invalid local issue response')
        ingest(state, rows, now)
        state.pop('manual_issue_sync_error', None)
    except (OSError, ValueError, subprocess.TimeoutExpired):
        state['manual_issue_sync_error'] = 'Manual issue collection unavailable; inspect the local database and bridge installation.'
