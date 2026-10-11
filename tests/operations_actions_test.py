from unittest.mock import patch
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
import unittest
import tempfile
from operations_actions import validate_command, validate_resource, pressure, perform_resource

class ActionsTest(unittest.TestCase):
    def decision(self):
        return dict(argv=['/usr/bin/true'], reason='test', expected_result='zero exit', recovery_plan='no changes')
    def demo(self):
        return dict(mode='demo', execute=True, allow_experimental_commands=True)
    def test_demo_explicit_root_authorization(self):
        self.assertEqual(validate_command(self.decision(), self.demo()), ['/usr/bin/true'])
        for change in [dict(mode='production'), dict(mode='inspect'), dict(mode='invalid')]:
            with self.assertRaises(ValueError):
                validate_command(self.decision(), dict(self.demo(), **change))
    def test_demo_mode_overrides_legacy_false_flags(self):
        self.assertEqual(validate_command(self.decision(), dict(mode='demo', execute=False,
            allow_experimental_commands=False)), ['/usr/bin/true'])

    def test_resource_mode_overrides_legacy_disabled_flags(self):
        action = dict(operation='stop_service', target='demo-worker.service')
        for mode in ('demo', 'production'):
            policy = dict(mode=mode, execute=False, resource_protection=dict(enabled=False,
                stoppable_services=['demo-worker.service']))
            validate_resource(action, policy, dict(triggered=True))
        for mode in ('inspect', 'invalid'):
            with self.assertRaises(ValueError):
                validate_resource(action, dict(self.production(), mode=mode), dict(triggered=True))

    def test_no_arbitrary_string_or_missing_recovery(self):
        for change in [dict(argv='echo hello'), dict(argv=['true']), dict(argv=['/bin/echo', '\x00']),dict(recovery_plan='')]:
            with self.assertRaises(ValueError):
                validate_command(dict(self.decision(), **change), self.demo())
    def test_direct_reboot_separate(self):
        for argv in [['/sbin/reboot'], ['/usr/bin/systemctl','reboot']]:
            with self.assertRaises(ValueError):
                validate_command(dict(self.decision(),argv=argv),self.demo())
    def production(self):
        return dict(mode='production',execute=True,resource_protection=dict(enabled=True,stoppable_services=['demo-worker.service','ssh.service']))
    def test_production_resource_needs_pressure(self):
        action=dict(operation='stop_service',target='demo-worker.service')
        validate_resource(action,self.production(),dict(triggered=True))
        with self.assertRaises(ValueError):
            validate_resource(action,self.production(),dict(triggered=False))
        with self.assertRaises(ValueError):
            validate_resource(dict(action,target='ssh.service'),self.production(),dict(triggered=True))
        with self.assertRaises(ValueError):
            validate_resource(dict(operation='delete_log',target='/var/log/syslog'),self.production(),dict(triggered=True))
        with self.assertRaises(ValueError):
            validate_resource(dict(operation='request_assistance'),self.production(),dict(triggered=True))
    def test_stop_verification(self):
        def runner(argv,timeout):
            return dict(exit_code=0,output='active\n' if 'show' in argv else '')
        result=perform_resource(dict(operation='stop_service',target='demo-worker.service'),{},runner,lambda p:p)
        self.assertEqual(result['exit_code'],1)
    def test_disposable_truncation_retains_open_writer(self):
        with tempfile.TemporaryDirectory() as directory:
            log = Path(directory) / 'disposable.log'
            log.write_bytes(b'old diagnostic data')
            with log.open('ab') as writer:
                result = perform_resource(dict(operation='delete_log',target=str(log)),{},None,None)
                self.assertEqual(result['after_bytes'],0)
                writer.write(b'new diagnostics')
            self.assertEqual(log.read_bytes(),b'new diagnostics')

    def test_log_rotation_is_mode_authorized_and_independent_of_model_budget(self):
        from operations_actions import automatic_log_rotation
        for mode in ('demo', 'production'):
            state = {}
            calls = []
            policy = dict(mode=mode, execute=False, model_schedule=dict(max_calls_per_day=0))
            runner = lambda argv, timeout: calls.append((argv, timeout)) or dict(exit_code=1)
            with patch('operations_actions.pressure', return_value=dict(disk_used_percent=89, triggered=True)):
                self.assertTrue(automatic_log_rotation(policy, dict(disk_used_percent=89), state,
                                1000, runner, lambda: None, lambda event: None))
                self.assertFalse(automatic_log_rotation(policy, dict(disk_used_percent=89), state,
                                1060, runner, lambda: None, lambda event: None))
            self.assertEqual(calls, [(['/usr/sbin/logrotate', '/etc/logrotate.conf'], 120)])
            self.assertEqual(state['last_log_rotation']['exit_code'], 1)
            self.assertEqual(state['last_log_rotation']['disk_used_percent_after'], 89)

    def test_log_rotation_only_runs_for_disk_pressure_in_maintenance_mode(self):
        from operations_actions import automatic_log_rotation
        for mode, disk in [('inspect', 95), ('unknown', 95), ('demo', 84), ('production', 50)]:
            self.assertFalse(automatic_log_rotation(dict(mode=mode), dict(disk_used_percent=disk),
                             {}, 1000, None, lambda: None, lambda event: None))

    def test_measured_pressure(self):
        result=pressure(dict(disk_used_percent=0))
        self.assertTrue(result['triggered'])
        self.assertIn('memory_available_percent',result)

if __name__ == '__main__': unittest.main()
