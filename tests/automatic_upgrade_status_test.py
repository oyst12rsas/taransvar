import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('automatic_upgrade_status', Path(__file__).resolve().parents[1]/'misc/automatic_upgrade_status.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class CollectorTest(unittest.TestCase):
    def test_missing_enrollment_never_probes_or_reports_healthy(self):
        with tempfile.TemporaryDirectory() as temp:
            result = module.collect(Path(temp), lambda *args: self.fail('Not enrolled'), now=100)
        self.assertEqual(result, {'enrolled': False, 'checked_at': 100})

    def test_enrolled_observes_timer_and_service_and_no_secrets(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            policy = root/'etc/tarasec/managed-upgrade.json'
            policy.parent.mkdir(parents=True)
            policy.write_text('{"secret":"must-not-leak"}')
            state = root/'var/lib/tarasec/upgrade/state.json'
            state.parent.mkdir(parents=True)
            state.write_text(json.dumps({'managed_upgrade': {'scheduler': {'status':'blocked','checked_at':90,'reason':'private'}}}))
            values = {'UnitFileState':'enabled','ActiveState':'active','Result':'success','ExecMainExitTimestampMonotonic':'0'}
            result = module.collect(root, lambda unit, prop: values[prop], now=100)
            self.assertTrue(result['enrolled'])
            self.assertEqual(result['scheduler_status'], 'blocked')
            self.assertEqual(result['timer_enabled'], 'enabled')
            self.assertNotIn('last_run_age_seconds', result)
            self.assertNotIn('private', json.dumps(result))
            self.assertNotIn('must-not-leak', json.dumps(result))

if __name__ == '__main__':
    unittest.main()
