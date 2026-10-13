import sys
from pathlib import Path
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
from operations_schedule import choose_trigger, reserve_call

class ScheduleTests(unittest.TestCase):
    def evidence(self, disk=60, memory=50, used=1000, triggered=False):
        return dict(disk_used_percent=disk, memory_available_percent=memory, disk_used_bytes=used, triggered=triggered)
    def test_idle_tick_never_calls_model_by_default(self):
        self.assertIsNone(choose_trigger({}, {}, self.evidence(), [], 10000))
    def test_request_and_optional_periodic_review(self):
        self.assertEqual(choose_trigger({}, {}, self.evidence(), [], 10000, True), 'owner_request')
        self.assertEqual(choose_trigger({}, {'model_schedule': {'periodic_review_seconds': 3600}}, self.evidence(), [], 10000), 'periodic_review_due')
    def test_unchanged_pressure_retries_after_interval(self):
        evidence = self.evidence(disk=90, triggered=True)
        state = dict(last_model_pressure=evidence, last_model_attempt_at=1000)
        self.assertIsNone(choose_trigger(state, {}, evidence, [], 1899))
        self.assertEqual(choose_trigger(state, {}, evidence, [], 1900), 'persistent_resource_pressure')
        self.assertEqual(choose_trigger(state, {}, evidence, [], 10000), 'persistent_resource_pressure')
        self.assertEqual(choose_trigger(state, {}, self.evidence(disk=93, triggered=True), [], 10000), 'resource_pressure_worsened')
    def test_new_failure_and_cooldown(self):
        self.assertEqual(choose_trigger({}, {}, self.evidence(), ['taralink.service'], 10000), 'new_service_failure')
        self.assertIsNone(choose_trigger({'last_model_attempt_at': 9999}, {}, self.evidence(), ['taralink.service'], 10000))
    def test_disk_growth_trigger_and_short_sample_ignored(self):
        state = dict(resource_sample={'at': 1000, 'disk_used_bytes': 1000})
        self.assertEqual(choose_trigger(state, {}, self.evidence(used=10000000), [], 1060), 'rapid_disk_growth')
        self.assertIsNone(choose_trigger(state, {}, self.evidence(used=20000000), [], 1061))
    def test_every_call_counts_including_retries(self):
        state = {}
        policy = {'model_schedule': {'max_calls_per_hour': 2}}
        self.assertTrue(reserve_call(state, policy, 10000))
        self.assertTrue(reserve_call(state, policy, 10001))
        self.assertFalse(reserve_call(state, policy, 10002))
        self.assertTrue(reserve_call(state, policy, 14000))
    def test_reboot_requirement_triggers_once_even_without_resource_pressure(self):
        evidence = dict(self.evidence(), reboot_required=True)
        self.assertEqual(choose_trigger({}, {}, evidence, [], 10000), 'reboot_required')
        self.assertIsNone(choose_trigger(dict(last_model_pressure=evidence), {}, evidence, [], 10000))

    def test_zero_budget_disables_model(self):
        self.assertFalse(reserve_call({}, {'model_schedule': {'max_calls_per_day': 0}}, 10000))

    def test_continuation_requires_ready_evidence_and_cooldown(self):
        state = {'last_assessment_status': 'deferred_activity_or_unknown', 'last_model_attempt_at': 1000}
        self.assertIsNone(choose_trigger(state, {}, self.evidence(), [], 3000))
        state['continuation_ready'] = True
        self.assertEqual(choose_trigger(state, {}, self.evidence(), [], 3000), 'verify_completed_action')
        self.assertIsNone(choose_trigger(state, {}, self.evidence(), [], 1050))


class PersistentPressureTest(unittest.TestCase):
    def test_prior_failure_and_completed_reboot_do_not_suppress_pressure(self):
        evidence = dict(triggered=True, disk_used_percent=88, memory_available_percent=50)
        state = dict(last_model_attempt_at=1000, last_model_pressure=evidence,
                     last_assessment_status='blocked', reboot=dict(required=False))
        self.assertEqual(choose_trigger(state, {}, evidence, [], 2000), 'persistent_resource_pressure')
        self.assertIsNone(choose_trigger(state, {}, dict(evidence, triggered=False), [], 2000))

    def test_retry_remains_subject_to_cooldown_and_budget(self):
        evidence = dict(triggered=True, disk_used_percent=88)
        state = dict(last_model_attempt_at=1000, last_model_pressure=evidence)
        policy = dict(model_schedule=dict(resource_retry_seconds=60, cooldown_seconds=900, max_calls_per_day=0))
        self.assertIsNone(choose_trigger(state, policy, evidence, [], 1060))
        self.assertEqual(choose_trigger(state, policy, evidence, [], 1900), 'persistent_resource_pressure')
        self.assertFalse(reserve_call(state, policy, 1900))

class FairIssueQueueTests(unittest.TestCase):
    def state(self):
        return {'issues': {
            'reboot:required': {'key':'reboot:required','status':'pending','discovered_at':10,'next_action_at':100},
            'manual:ssh': {'key':'manual:ssh','status':'open','discovered_at':50,'next_action_at':100}}}

    def test_waiting_reboot_cannot_starve_ssh_report(self):
        from operations_schedule import choose_issue, activate_issue, mark_issue_assessed
        from operations_issues import progress
        state=self.state()
        for item in state['issues'].values():
            item.update(history=[],sequence=0,verification='manual',checked_at=100)
        activate_issue(state,choose_issue(state,100))
        self.assertEqual(state['active_issue_key'],'reboot:required')
        mark_issue_assessed(state,100)
        state.update(status='reported',last_model_attempt_at=100)
        progress(state,100)
        ssh=state['issues']['manual:ssh']
        self.assertEqual(ssh['status'],'open')
        self.assertEqual(ssh['next_action_at'],100)
        activate_issue(state,choose_issue(state,1000))
        self.assertEqual(state['active_issue_key'],'manual:ssh')
        mark_issue_assessed(state,1000)
        progress(state,1000)
        self.assertEqual(choose_issue(state,1900),'reboot:required')

    def test_upgrade_uses_previous_focus_and_honours_due_times(self):
        from operations_schedule import choose_issue
        state=self.state()
        state.update(active_issue_key='reboot:required',last_assessment_checked_at=99)
        self.assertEqual(choose_issue(state,100),'manual:ssh')
        state['issues']['manual:ssh']['next_action_at']=200
        self.assertEqual(choose_issue(state,100),'reboot:required')
        state['issues']['reboot:required']['status']='solved'
        self.assertIsNone(choose_issue(state,100))
        self.assertEqual(choose_issue(state,100,requested=True),'manual:ssh')

    def test_selection_does_not_consume_turn_before_budget_granted(self):
        from operations_schedule import choose_issue, activate_issue
        state=self.state()
        activate_issue(state,choose_issue(state,100))
        self.assertFalse(reserve_call(state,{'model_schedule':{'max_calls_per_day':0}},100))
        self.assertNotIn('last_assessed_at',state['issues']['reboot:required'])

    def test_switching_focus_restores_each_issues_own_task(self):
        from operations_schedule import activate_issue
        state=self.state()
        state.update(active_issue_key='reboot:required',task_state={'goal':'reboot'})
        activate_issue(state,'manual:ssh')
        self.assertEqual(state['task_state'],{})
        state['task_state']={'goal':'test SSH gate'}
        activate_issue(state,'reboot:required')
        self.assertEqual(state['task_state'],{'goal':'reboot'})
        activate_issue(state,'manual:ssh')
        self.assertEqual(state['task_state'],{'goal':'test SSH gate'})
