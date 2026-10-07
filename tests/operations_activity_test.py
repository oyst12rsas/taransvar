import importlib.util
from pathlib import Path
import sys
import unittest
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
from operations_activity import combine


class ObserverTest(unittest.TestCase):
    def inputs(self, now):
        return ({'checked_at': now, 'complete': True, 'active_demo': False},
                {'checked_at': now, 'complete': True, 'meaningful_traffic': False,
                 'reporting_exclusion_verified': True})

    def test_continuous_quiet(self):
        previous = {}
        for now in range(1000, 1310, 10):
            previous = combine(previous, *self.inputs(now), now, 'boot')
        self.assertEqual(previous['quiet_for_seconds'], 300)

    def test_reporting_is_not_meaningful_traffic(self):
        demo, traffic = self.inputs(1000)
        traffic['report_bytes'] = 1000000
        self.assertTrue(combine({}, demo, traffic, 1000, 'boot')['complete'])
        traffic['reporting_exclusion_verified'] = False
        self.assertFalse(combine({}, demo, traffic, 1000, 'boot')['complete'])

    def test_new_demo_resets(self):
        previous = combine({}, *self.inputs(1000), 1000, 'boot')
        demo, traffic = self.inputs(1010)
        demo['active_demo'] = True
        self.assertEqual(combine(previous, demo, traffic, 1010, 'boot')['quiet_for_seconds'], 0)

    def test_gap_boot_restart_and_stale(self):
        previous = combine({}, *self.inputs(1000), 1000, 'boot')
        self.assertEqual(combine(previous, *self.inputs(1040), 1040, 'boot')['quiet_for_seconds'], 0)
        self.assertEqual(combine(previous, *self.inputs(1010), 1010, 'new')['quiet_for_seconds'], 0)
        self.assertFalse(combine(previous, *self.inputs(1000), 1040, 'boot')['complete'])
        self.assertFalse(combine(previous, {}, {}, 1010, 'boot')['complete'])


if __name__ == '__main__':
    unittest.main()
