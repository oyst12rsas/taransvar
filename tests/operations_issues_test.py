import sys
from pathlib import Path
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
from operations_issues import observe, progress, report
from operations_schedule import choose_trigger

class IssueTests(unittest.TestCase):
    def test_deduplicate_reopen_and_verified_close(self):
        state = {}
        observe(state, 'service', {'a.service': 'Failed'}, 10)
        observe(state, 'service', {'a.service': 'Failed'}, 20)
        item = state['issues']['service:a.service']
        self.assertEqual(len(item['history']), 1)
        self.assertEqual(item['discovered_at'], 10)
        state.update(status='command_executed', last_action_result={'exit_code': 0})
        progress(state, 21)
        self.assertEqual(item['status'], 'verification_pending')
        observe(state, 'service', {}, 30)
        self.assertEqual(item['status'], 'solved')
        observe(state, 'service', {'a.service': 'Failed'}, 40)
        self.assertEqual(item['status'], 'open')
        self.assertEqual(item['history'][-1]['kind'], 'reopened')

    def test_failed_command_remains_pending_and_retries(self):
        state = {}
        observe(state, 'service', {'a.service': 'Failed'}, 10)
        state.update(status='command_failed', last_model_attempt_at=20, last_action_result={'exit_code': 1})
        progress(state, 20)
        item = state['issues']['service:a.service']
        self.assertEqual(item['status'], 'blocked')
        state['last_model_attempt_at'] = 20
        evidence = {'triggered': False}
        self.assertIsNone(choose_trigger(state, {}, evidence, [], 919))
        self.assertEqual(choose_trigger(state, {}, evidence, [], 920), 'pending_issue')
        progress(state, 921)
        self.assertEqual(item['next_action_at'], 920) # repeated saves do not postpone work

    def test_outage_history_replays_oldest_until_receipt_and_argv_stays_local(self):
        state = {}
        observe(state, 'service', {'a': 'Failed'}, 1)
        for i in range(40):
            state.update(status='command_failed', last_action_result={'exit_code': i},
                         issue_decision={'argv': ['/bin/tool', 'SECRET']})
            progress(state, i+2)
        item = state['issues']['service:a']
        self.assertEqual(len(item['history']), 41)
        first = report(state, {})[0]
        self.assertEqual(first['history'][0]['event_id'], 1)
        second = report(state, {item['incarnation']: 16})[0]
        self.assertEqual(second['history'][0]['event_id'], 17)
        self.assertNotIn('SECRET', str(first))
        self.assertEqual(report(state, {item['incarnation']: 41})[0]['history'], [])

    def test_other_check_cannot_close_issue(self):
        state = {}
        observe(state, 'deployment', {'missing': 'Missing'}, 1)
        observe(state, 'service', {}, 2)
        self.assertEqual(state['issues']['deployment:missing']['status'], 'open')

    def test_incomplete_inventory_never_closes_and_model_text_cannot_solve(self):
        state = {}
        observe(state, 'deployment', {'missing': 'Missing'}, 1)
        observe(state, 'deployment', {'inventory_unknown': 'Unknown'}, 2, complete=False)
        self.assertEqual(state['issues']['deployment:missing']['status'], 'open')
        state.update(status='reported', task_state={'verified':['Everything fixed'], 'next_check':'SECRET'})
        progress(state, 3)
        self.assertNotEqual(state['issues']['deployment:missing']['status'], 'solved')
        self.assertNotIn('SECRET', str(report(state, {})))

    def test_command_is_not_assigned_to_unrelated_issue(self):
        state = {}
        observe(state, 'service', {'a':'Failed', 'b':'Failed'}, 1)
        state.update(status='command_executed', active_issue_key='service:a', issue_decision={'argv':['/bin/repair']})
        progress(state, 2)
        self.assertEqual(state['issues']['service:a']['commands'], ['/bin/repair'])
        self.assertEqual(state['issues']['service:b']['commands'], [])
        self.assertNotEqual(state['issues']['service:b']['status'], 'verification_pending')

    def test_string_receipt_is_accepted_and_description_explains_verification(self):
        state = {}
        observe(state, 'service', {'a.service':'Failed'}, 1)
        item = state['issues']['service:a.service']
        view = report(state, {item['incarnation']:'1'})[0]
        self.assertEqual(view['history'], [])
        self.assertIn('a.service', view['verification_needed'])
        self.assertIn('no repair result', view['status_explanation'])

    def test_read_only_command_is_not_a_successful_repair(self):
        state = {}
        observe(state, 'service', {'a':'Failed'}, 1)
        state.update(status='command_executed', last_action_result={'read_only':True,'exit_code':0})
        progress(state, 2)
        self.assertEqual(state['issues']['service:a']['status'], 'pending')
