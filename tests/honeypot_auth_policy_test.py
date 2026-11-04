import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]


class AuthenticationPolicyTests(unittest.TestCase):
    def test_normal_default_legacy_settings_debug_switch_and_demo_challenge(self):
        with tempfile.TemporaryDirectory() as folder:
            key = Path(folder) / 'key'
            subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(key)], check=True)
            for legacy, debug, expected in [(None, None, False), ('accept-all', None, False),
                                            ('password', 'no', False), ('reject-all', 'yes', True),
                                            ('accept-all', 'no', False)]:
                with self.subTest(legacy=legacy, debug=debug), mock.patch.dict(os.environ, {'TARASEC_SSH_HONEYPOT_KEY': str(key)}):
                    os.environ.pop('TARASEC_SSH_HONEYPOT_DEBUG_SHELL', None)
                    os.environ.pop('TARASEC_SSH_HONEYPOT_AUTH_MODE', None)
                    os.environ.pop('TARASEC_SSH_HONEYPOT_DEMO_PORT', None)
                    if legacy is not None:
                        os.environ['TARASEC_SSH_HONEYPOT_AUTH_MODE'] = legacy
                    if debug is not None:
                        os.environ['TARASEC_SSH_HONEYPOT_DEBUG_SHELL'] = debug
                    spec = importlib.util.spec_from_file_location('honeypot_policy_case', ROOT / 'misc/tarasec_ssh_honeypot.py')
                    module = importlib.util.module_from_spec(spec)
                    spec.loader.exec_module(module)
                    server = module.SSHServer({'dst_port': 2345})
                    output = io.StringIO()
                    with contextlib.redirect_stdout(output):
                        result = server.check_auth_password('tester', 'example-secret')
                    self.assertEqual(result == module.paramiko.AUTH_SUCCESSFUL, expected)
                    record = json.loads(output.getvalue())
                    self.assertEqual(record['event'], 'ssh_login_success' if expected else 'ssh_login_failed')
                    self.assertNotIn('example-secret', output.getvalue())
                    module.DEMO_PORT = 4000
                    server = module.SSHServer({'dst_port': 4000})
                    with mock.patch.object(module, 'validate_demo', return_value=(True, 'accepted')) as validate, contextlib.redirect_stdout(io.StringIO()):
                        self.assertEqual(server.check_auth_password('tester', 'challenge'), module.paramiko.AUTH_SUCCESSFUL)
                        validate.assert_called_once()

    @unittest.skipUnless(os.geteuid() == 0, 'Root-owned configuration migration')
    def test_persisted_policy_switches_preserve_other_configuration_and_permissions(self):
        script = (ROOT / 'misc/setup_ssh_honeypot.sh').read_text()
        body = script.split("<<'HONEYPOT_POLICY'\n", 1)[1].split('\nHONEYPOT_POLICY', 1)[0]
        with tempfile.TemporaryDirectory() as folder:
            config = Path(folder) / 'config'
            config.write_text('SSH_PORT="2345"\nSSH_HONEYPOT_AUTH_MODE="accept-all"\nOTHER="retain"\n')
            config.chmod(0o640)
            for mode, debug in [('reject-all', 'no'), ('accept-all', 'yes'), ('reject-all', 'no')]:
                subprocess.run(['/usr/bin/python3', '-', str(config), mode, debug], input=body, text=True, check=True)
                text = config.read_text()
                self.assertIn('SSH_PORT="2345"', text)
                self.assertIn('OTHER="retain"', text)
                self.assertEqual(text.count('SSH_HONEYPOT_AUTH_MODE='), 1)
                self.assertIn('SSH_HONEYPOT_AUTH_MODE="' + mode + '"', text)
                self.assertIn('SSH_HONEYPOT_DEBUG_SHELL="' + debug + '"', text)
                self.assertEqual(config.stat().st_mode & 0o777, 0o640)


if __name__ == '__main__':
    unittest.main()
