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
    def test_unchanged_pressure_does_not_repeat(self):
        evidence = self.evidence(disk=90, triggered=True)
        state = dict(last_model_pressure=evidence, last_model_attempt_at=1000)
        self.assertIsNone(choose_trigger(state, {}, evidence, [], 10000))
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
    def test_zero_budget_disables_model(self):
        self.assertFalse(reserve_call({}, {'model_schedule': {'max_calls_per_day': 0}}, 10000))

    def test_continuation_requires_ready_evidence_and_cooldown(self):
        state = {'last_assessment_status': 'deferred_activity_or_unknown', 'last_model_attempt_at': 1000}
        self.assertIsNone(choose_trigger(state, {}, self.evidence(), [], 3000))
        state['continuation_ready'] = True
        self.assertEqual(choose_trigger(state, {}, self.evidence(), [], 3000), 'verify_completed_action')
        self.assertIsNone(choose_trigger(state, {}, self.evidence(), [], 1050))
