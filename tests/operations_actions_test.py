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
        for change in [dict(mode='production'), dict(execute=False), dict(allow_experimental_commands=False)]:
            with self.assertRaises(ValueError):
                validate_command(self.decision(), dict(self.demo(), **change))
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

    def test_measured_pressure(self):
        result=pressure(dict(disk_used_percent=0))
        self.assertTrue(result['triggered'])
        self.assertIn('memory_available_percent',result)

if __name__ == '__main__': unittest.main()
