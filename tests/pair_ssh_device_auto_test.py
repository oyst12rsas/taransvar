import argparse
import base64
import contextlib
import hashlib
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
import pair_ssh_device as client


class AutomaticEnrollmentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.folder = Path(self.temp.name)
        self.key = self.folder / 'id_ed25519'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(self.key)], check=True)
        self.original = self.key.read_bytes()
        self.public = ' '.join(Path(str(self.key) + '.pub').read_text().split()[:2])
        self.fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(self.public.split()[1])).digest()).decode().rstrip('=')
        self.ref = 'a' * 64
        self.token = 'b' * 64
        self.id = 'c' * 32
        self.args = argparse.Namespace(server='http://gateway.test', service='https://approval.test/ops/agent/',
            user='alice', device='Acer', key=self.key)
        self.calls = []

    def service(self, states, wrong_ref=False):
        def request(service, action, body):
            self.calls.append(action)
            self.assertNotIn('PRIVATE KEY', json.dumps(body))
            if action == 'device_start':
                self.assertEqual(body['public_key'], self.public)
                return dict(pairing_token=self.token, server_ref=self.ref, ssh_user='alice', device_name='Acer', message='fresh enrollment challenge')
            if action == 'submit':
                self.assertEqual(body['pairing_token'], self.token)
                sig = self.folder / 'signature'
                sig.write_text(body['signature'])
                allowed = self.folder / 'allowed_signers'
                allowed.write_text('device ' + self.public + '\n')
                subprocess.run(['ssh-keygen', '-Y', 'verify', '-f', str(allowed), '-I', 'device',
                    '-n', 'tarasec-ssh-enrollment', '-s', str(sig)], input=b'fresh enrollment challenge',
                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
                return dict(server_ref=self.ref, fingerprint=self.fingerprint, id=self.id)
            self.assertEqual(action, 'status')
            return dict(server_ref='d' * 64 if wrong_ref else self.ref,
                fingerprint=self.fingerprint, id=self.id, state=next(states))
        return request

    def run_flow(self, states, wrong_ref=False):
        output = io.StringIO()
        with patch.object(client, 'discover', return_value=self.ref), \
             patch.object(client, 'request', side_effect=self.service(iter(states), wrong_ref)), \
             patch.object(client.getpass, 'getpass', side_effect=AssertionError('No pairing-code prompt allowed')), \
             patch.object(client.time, 'sleep'), contextlib.redirect_stdout(output):
            client.automatic(self.args)
        self.assertEqual(self.original, self.key.read_bytes(), 'Existing private key was replaced')
        self.assertNotIn(self.token, output.getvalue())
        return output.getvalue()

    def test_signed_submission_waits_for_worker_confirmation_without_copying(self):
        output = self.run_flow(['pending', 'approved', 'running', 'done'])
        self.assertIn('server confirmed installation', output)
        self.assertEqual(self.calls, ['device_start', 'submit', 'status', 'status', 'status', 'status'])

    def test_terminal_failures_are_not_reported_as_installation(self):
        for state in ['rejected', 'failed', 'expired', 'cancelled']:
            with self.subTest(state=state), self.assertRaisesRegex(ValueError, 'not installed: ' + state):
                self.run_flow([state])

    def test_status_for_another_server_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'does not match'):
            self.run_flow(['done'], wrong_ref=True)

    def test_discovery_cannot_switch_approval_service(self):
        class Response(io.BytesIO):
            pass
        class Opener:
            def open(self, req, timeout):
                return Response(json.dumps({'approvalTarget': {'approval_url': 'https://other.test/ops/agent/', 'server_ref': 'a' * 64}}).encode())
        with patch.object(client.urllib.request, 'build_opener', return_value=Opener()):
            with self.assertRaisesRegex(ValueError, 'does not match'):
                client.discover(self.args.server, self.args.service)

    def test_invalid_username_stops_before_network_or_key_access(self):
        self.args.user = 'alice@host'
        with patch.object(client, 'discover', side_effect=AssertionError('Unexpected network request')):
            with self.assertRaisesRegex(ValueError, 'Linux username'):
                client.automatic(self.args)


if __name__ == '__main__':
    unittest.main()
