import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
import tempfile
import unittest
from operations_diagnostics import large_logs, gateway_startup, diagnose

class DiagnosticsTest(unittest.TestCase):
    def test_log_discovery_ignores_links_and_bounds_scan(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'small').write_bytes(b'x')
            (root / 'large').write_bytes(b'x' * 10000)
            (root / 'linked').symlink_to(root / 'large')
            before = (root / 'large').read_bytes()
            result = large_logs(root)
            self.assertTrue(result['complete'])
            self.assertEqual(result['files'][0]['path'], str(root / 'large'))
            self.assertNotIn(str(root / 'linked'), [x['path'] for x in result['files']])
            self.assertEqual(before, (root / 'large').read_bytes())
            self.assertFalse(large_logs(root, maximum=1)['complete'])

    def test_service_arguments_never_leave_diagnostic(self):
        calls = []
        def runner(argv, timeout):
            calls.append(argv)
            return {'exit_code': 0, 'output': 'ActiveState=failed\nExecMainStatus=203\nExecStart={ path=/missing/gateway ; argv[]=/missing/gateway --token=SECRET ; }\n'}
        result = gateway_startup(runner)
        self.assertNotIn('SECRET', str(result))
        self.assertFalse(result['properties']['executable']['exists'])
        self.assertEqual(result['properties']['ExecMainStatus'], '203')
        self.assertEqual(calls[0][:3], ['systemctl', 'show', 'tarasec-gateway.service'])

    def test_unknown_action_cannot_run_command(self):
        with self.assertRaises(ValueError):
            diagnose('/bin/sh', lambda *args: self.fail('Executed unknown command'))

if __name__ == '__main__':
    unittest.main()

class SshAccessDiagnosticsTest(unittest.TestCase):
    def test_fixed_read_only_commands_and_no_credential_fields(self):
        calls=[]
        def runner(argv,timeout):
            calls.append(argv)
            if argv==['/usr/sbin/sshd','-T']:
                return {'exit_code':0,'output':'port 5822\nlistenaddress [::]:5822\nauthorizedkeyscommand /example SECRET\npasswordauthentication yes\n'}
            return {'exit_code':0,'output':'Observed policy'}
        result=diagnose('ssh_access',runner)
        self.assertEqual(result['sshd_base']['settings']['port'],['5822'])
        self.assertNotIn('SECRET',str(result))
        self.assertEqual(calls,[['/usr/sbin/sshd','-T'],['/usr/bin/ss','-lnt'],
            ['/usr/sbin/iptables','-S'],['/usr/sbin/ip6tables','-S'],
            ['/usr/sbin/ipset','list','tarasec_app_ssh']])

    def test_missing_binary_does_not_abort_other_observations(self):
        def runner(argv,timeout):
            if argv[0]=='/usr/sbin/ipset': raise FileNotFoundError('SECRET')
            return {'exit_code':0,'output':''}
        result=diagnose('ssh_access',runner)
        self.assertEqual(result['app_window_set']['exit_code'],127)
        self.assertNotIn('SECRET',str(result))
