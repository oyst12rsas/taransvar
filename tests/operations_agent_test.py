import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / "misc"))
import importlib.util
from pathlib import Path
import unittest
import sys

spec = importlib.util.spec_from_file_location('agent', Path(__file__).parents[1] / 'misc/operations_agent.py')
agent = importlib.util.module_from_spec(spec)
spec.loader.exec_module(agent)


class QuietTest(unittest.TestCase):
    def sample(self, now, **changes):
        return dict(checked_at=now, complete=True, active_demo=False,
                    meaningful_traffic=False, **changes)

    def test_five_minutes_required(self):
        state = {}
        for now in range(1000, 1300, 60):
            self.assertFalse(agent.quiet(state, self.sample(now), now, 300))
        self.assertTrue(agent.quiet(state, self.sample(1300), 1300, 300))

    def test_demo_and_traffic_reset(self):
        for field in ('active_demo', 'meaningful_traffic'):
            state = {'quiet_since': 1000, 'last_activity_check': 1240}
            sample = self.sample(1300)
            sample[field] = True
            self.assertFalse(agent.quiet(state, sample, 1300, 300))
            self.assertNotIn('quiet_since', state)

    def test_missing_stale_future_and_wrong_type(self):
        for sample in ({}, self.sample(1000), self.sample(1400),
                       dict(checked_at=1300, complete=True, active_demo='false', meaningful_traffic=False)):
            state = {'quiet_since': 1000, 'last_activity_check': 1240}
            self.assertFalse(agent.quiet(state, sample, 1300, 300))

    def test_sampling_gap_and_clock_regression(self):
        for last in (1000, 1400):
            state = {'quiet_since': 900, 'last_activity_check': last}
            self.assertFalse(agent.quiet(state, self.sample(1300), 1300, 300))


class ReadinessTest(unittest.TestCase):
    def setUp(self):
        self.commit = 'a' * 40
        self.policy = dict(mode='demo', execute=True, target_commit=self.commit)
        self.entry = {'validation': dict(readiness='deployment_tested', platform='ubuntu-24.04',
            commit=self.commit, tested_at='2026-10-07', evidence='reviewed acceptance transcript')}

    def test_matching_tested_release(self):
        self.assertTrue(agent.eligible(self.entry, self.policy, 'ubuntu-24.04'))

    def test_platform_commit_and_readiness(self):
        for field, value in [('readiness', 'automated_tests_passed'), ('commit', 'b' * 40),
                             ('platform', 'ubuntu-26.04'), ('evidence', '')]:
            entry = {'validation': dict(self.entry['validation'], **{field: value})}
            self.assertFalse(agent.eligible(entry, self.policy, 'ubuntu-24.04'))

    def test_production_and_inspection_cannot_mutate(self):
        for policy in [dict(self.policy, mode='production'), dict(self.policy, mode='inspect'),
                       dict(self.policy, execute=False), dict(self.policy, execute='true')]:
            self.assertFalse(agent.eligible(self.entry, policy, 'ubuntu-24.04'))

    def test_timeout_stops_process_group(self):
        result = agent.run(['/bin/sh', '-c', 'sleep 5'], timeout=0.05)
        self.assertEqual(result['exit_code'], 124)


class CommandCaptureTests(unittest.TestCase):
    def test_verbose_command_has_bounded_output(self):
        result = agent.run([sys.executable, '-c', 'print("x" * 1000000)'])
        self.assertEqual(result['exit_code'], 0)
        self.assertEqual(len(result['output']), 16000)

    def test_timeout_stops_command(self):
        result = agent.run([sys.executable, '-c', 'import time; time.sleep(10)'], timeout=0.05)
        self.assertEqual(result['exit_code'], 124)


class ErrorReportTest(unittest.TestCase):
    def test_static_policy_error_is_visible(self):
        self.assertEqual(agent.safe_error(ValueError('Unknown model action')), 'Unknown model action')
    def test_arbitrary_exception_text_is_private(self):
        self.assertNotIn('SECRET', agent.safe_error(ValueError('SECRET')))
        self.assertNotIn('SECRET', agent.safe_error(RuntimeError('SECRET')))

if __name__ == '__main__':
    unittest.main()
