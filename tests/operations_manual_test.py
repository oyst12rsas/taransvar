import sys
from pathlib import Path
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
from operations_manual import ingest, sync
from operations_issues import observe, progress, report
from operations_schedule import choose_trigger


class ManualIssueTests(unittest.TestCase):
    def row(self, **values):
        return dict(issue_key='manual:'+'a'*32, description='SSH accepts a new connection while closed',
                    discovered_at=10, status='open', solved_at=None, **values)

    def test_manual_report_enters_queue_and_survives_checks_and_action_success(self):
        state = {}
        row = self.row()
        ingest(state, [row], 20)
        ingest(state, [row], 30)
        item = state['issues'][row['issue_key']]
        self.assertEqual(len(item['history']), 1)
        self.assertEqual(choose_trigger(state, {}, {}, [], 30), 'pending_issue')
        observe(state, 'service', {}, 31)
        state.update(status='command_executed', last_action_result={'exit_code': 0})
        progress(state, 32)
        self.assertEqual(item['status'], 'verification_pending')
        self.assertIn('administrator', report(state, {})[0]['verification_needed'])
        row.update(status='solved', solved_at=40)
        ingest(state, [row], 41)
        self.assertEqual(item['status'], 'solved')
        ingest(state, [row], 42)
        self.assertEqual(len(item['history']), 3)

    def test_invalid_keys_do_not_enter_queue(self):
        row = self.row(); row['issue_key'] = 'service:foreign'
        state = {}; ingest(state, [row], 20)
        self.assertEqual(state['issues'], {})

    def test_bridge_failure_preserves_work_and_is_visible(self):
        state = {}; ingest(state, [self.row()], 20)
        with patch('operations_manual.subprocess.run', side_effect=OSError):
            sync(state, 30)
        self.assertIn('manual_issue_sync_error', state)
        self.assertEqual(next(iter(state['issues'].values()))['status'], 'open')

    def test_all_categories_replay_locally_in_bounded_acknowledged_batches(self):
        import json
        from types import SimpleNamespace
        from operations_issues import observe, event
        state={}
        observe(state,'service',{'a.service':'Failed'},1)
        observe(state,'reboot',{'required':'Reboot required'},1)
        item=state['issues']['service:a.service']
        for i in range(35):
            event(item,'progress',i+2,{'worker_status':'pending'})
        replies=SimpleNamespace(returncode=0,stdout=json.dumps({'issues':[],
            'acknowledgements':{item['incarnation']:16}}))
        with patch('operations_manual.subprocess.run',return_value=replies) as runner:
            sync(state,40)
            first=json.loads(runner.call_args.kwargs['input'])
            self.assertEqual({i['verification'] for i in first},{'service','reboot'})
            self.assertEqual(len(next(i for i in first if i['key']==item['key'])['history']),16)
            sync(state,41)
            second=json.loads(runner.call_args.kwargs['input'])
            self.assertEqual(next(i for i in second if i['key']==item['key'])['history'][0]['event_id'],17)
            self.assertNotIn('commands',str(second))
