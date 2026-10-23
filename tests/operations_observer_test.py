import json
from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
from operations_observer import maintain, DEFAULT_PROBE


class ObserverReadinessTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        config = self.root / 'etc/tarasec/operations-activity.json'
        config.parent.mkdir(parents=True)
        key = self.root / 'node.key'
        key.write_text('private')
        config.write_text(json.dumps({'demo_url': 'https://example.test/feed', 'demo_key_file': str(key)}))
        self.sample = {}
        self.active = False
        self.calls = []
        self.state = {}
        self.saved = []

    def runner(self, argv, timeout):
        self.calls.append(argv)
        if argv == [DEFAULT_PROBE]:
            return {'exit_code': 0, 'output': json.dumps(self.sample)}
        if 'is-active' in argv:
            return {'exit_code': 0 if self.active else 3, 'output': ''}
        return {'exit_code': 0, 'output': ''}

    def check(self, now=1000, mode='demo'):
        return maintain(self.state, {'mode': mode}, now, self.runner, Path,
                        lambda: self.saved.append(dict(self.state)), self.root)

    def test_healthy_observer_is_not_restarted_even_during_active_demo(self):
        self.sample = dict(checked_at=990, complete=True, active_demo=True, meaningful_traffic=False)
        self.assertEqual(self.check()['status'], 'ready')
        self.assertEqual(self.calls, [[DEFAULT_PROBE]])

    def test_stopped_service_is_resumed_without_claiming_quiet(self):
        self.assertEqual(self.check()['status'], 'starting')
        self.assertIn(['systemctl', 'enable', '--now', 'tarasec-operations-activity.service'], self.calls)
        self.assertEqual(self.saved[0]['activity_observer_repair_at'], 1000)
        self.assertNotEqual(self.state['activity_observer']['status'], 'ready')
        self.calls.clear()
        self.check(1060)
        self.assertFalse(any('enable' in c or 'restart' in c for c in self.calls))

    def test_stale_running_service_restarts_but_fresh_incomplete_does_not(self):
        self.active = True
        self.assertEqual(self.check()['status'], 'starting')
        self.assertIn(['systemctl', 'restart', 'tarasec-operations-activity.service'], self.calls)
        self.calls.clear()
        self.sample = dict(checked_at=1390, complete=False, blockers=['demo_feed_http_403'])
        self.assertEqual(self.check(1400)['blockers'], ['demo_feed_http_403'])
        self.assertFalse(any('restart' in c for c in self.calls))

    def test_missing_enrollment_is_reported_without_inventing_credentials(self):
        config = self.root / 'etc/tarasec/operations-activity.json'
        config.write_text('{}')
        self.assertEqual(self.check()['blockers'], ['demo_feed_url_not_configured'])
        self.assertEqual(self.calls, [[DEFAULT_PROBE]])

    def test_inspection_mode_and_custom_probe_never_restart_service(self):
        self.check(mode='inspect')
        self.assertEqual(self.calls, [[DEFAULT_PROBE]])
        self.calls.clear()
        maintain(self.state, {'mode': 'demo', 'quiet_probe': '/custom'}, 1400,
                 self.runner, Path, lambda: None, self.root)
        self.assertEqual(self.calls, [['/custom']])


if __name__ == '__main__':
    unittest.main()
