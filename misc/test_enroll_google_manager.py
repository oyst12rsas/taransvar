"""Run with sudo: fixtures exercise real root-owned policy/token checks."""
import hashlib
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest import mock
import urllib.error

import enroll_google_manager as enroll
import local_manager_policy
import agent_approval_worker as worker


class EnrollmentTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.calls = []
        self.opener = mock.Mock()
        self.opener.open.side_effect = self.answer

    def answer(self, req, timeout):
        self.calls.append(req)
        payload = json.loads(req.data)
        token = req.get_header('Authorization').split()[1]
        return io.BytesIO(json.dumps(dict(ok=True, node_id='enrolled-' + hashlib.sha256(token.encode()).hexdigest(),
            manager_count=len(payload['manager_hashes']), nickname=payload['nickname'])).encode())

    def run_enroll(self, emails=None, **kwargs):
        return enroll.enroll(emails or [], root=self.root, opener=self.opener, **kwargs)

    def test_new_node_sends_hashes_only_and_preserves_identity_on_retry(self):
        one = self.run_enroll([' OWNER@example.com '])
        two = self.run_enroll(['owner@example.com'])
        self.assertEqual(one['node_id'], two['node_id'])
        payload = json.loads(self.calls[0].data)
        self.assertEqual(payload['manager_hashes'], [local_manager_policy.email_hash('owner@example.com')])
        self.assertNotIn('owner@example.com', self.calls[0].data.decode().lower())
        self.assertEqual(set(payload), {'nickname', 'manager_hashes'})
        token = self.root/'etc/tarasec/agent-node.token'
        self.assertEqual(token.stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.calls[0].full_url, 'https://tarasec.org/ops/agent/enroll.php')
        self.assertEqual(self.calls[0].get_header('Authorization'), self.calls[1].get_header('Authorization'))

    def test_add_manager_preserves_existing_local_grants_and_sync_revokes(self):
        self.run_enroll(['one@example.com'])
        self.run_enroll(['two@example.com'])
        self.assertEqual(len(json.loads(self.calls[-1].data)['manager_hashes']), 2)
        path = self.root/'etc/tarasec/access.conf'
        path.write_text('MANAGER_EMAILS=""\nMANAGER_EMAIL_SHA256=""\n')
        self.run_enroll(sync=True)
        self.assertEqual(json.loads(self.calls[-1].data)['manager_hashes'], [])

    def test_rejected_registration_keeps_identity_and_does_not_change_ssh_policy(self):
        manager = self.root/'etc/tarasec-server-manager.conf'
        manager.parent.mkdir(parents=True)
        manager.write_text('SSH_GOOGLE_REOPEN_ENABLED=no\n')
        manager.chmod(0o600)
        self.opener.open.side_effect = urllib.error.HTTPError('https://example.org', 503, 'failure', {}, None)
        with self.assertRaises(urllib.error.HTTPError): self.run_enroll(['owner@example.com'])
        self.assertTrue((self.root/'etc/tarasec/agent-node.token').exists())
        self.assertEqual(manager.read_text(), 'SSH_GOOGLE_REOPEN_ENABLED=no\n')
        self.assertFalse((self.root/'etc/tarasec/agent-enrollment.json').exists())

    def test_custom_endpoint_used_for_enrollment_and_worker(self):
        url = 'https://private.example.org/ops/agent/api.php'
        self.run_enroll(['owner@example.com'], approval_url=url)
        self.assertEqual(self.calls[-1].full_url, url.replace('api.php','enroll.php'))
        manager = self.root/'etc/tarasec-server-manager.conf'
        self.assertEqual(enroll.setting(manager.read_text(), 'AGENT_APPROVAL_URL'), url)
        answer = mock.MagicMock()
        answer.__enter__.return_value = io.BytesIO(b'{"ok":true}')
        opener = mock.Mock(); opener.open.return_value = answer
        with mock.patch.object(worker, 'manager_setting', return_value=url), \
             mock.patch('urllib.request.build_opener', return_value=opener):
            worker.api('poll', {}, 'a'*64)
        self.assertEqual(opener.open.call_args.args[0].full_url, url+'?action=poll')

    def test_http_credential_urls_redirects_and_invalid_email_rejected(self):
        for url in ('http://example.org/api.php', 'https://user:secret@example.org/api.php',
                    'https://example.org/api.php?x=1', 'https://example.org/api.php#x'):
            with self.subTest(url=url), self.assertRaises(ValueError):
                self.run_enroll(['owner@example.com'], approval_url=url)
        self.assertFalse((self.root/'etc/tarasec/agent-node.token').exists())
        with self.assertRaises(ValueError): self.run_enroll(['bad\nemail'])
        with self.assertRaises(ValueError): enroll.NoRedirect().redirect_request(None,None,302,'',{},'https://other.example')
        self.opener.open.assert_not_called()

    def test_symlink_or_insecure_credential_never_replaced(self):
        path=self.root/'etc/tarasec/agent-node.token'; path.parent.mkdir(parents=True)
        other=self.root/'other'; other.write_text('a'*64); other.chmod(0o600)
        path.symlink_to(other)
        with self.assertRaises(OSError): self.run_enroll(['owner@example.com'])
        self.assertEqual(other.read_text(), 'a'*64)
        path.unlink();path.write_text('a'*64);path.chmod(0o644)
        with self.assertRaises(ValueError): self.run_enroll(['owner@example.com'])
        self.opener.open.assert_not_called()

    def test_invalid_reply_does_not_create_success_receipt(self):
        self.opener.open.side_effect=None
        self.opener.open.return_value=io.BytesIO(b'{"ok":true,"node_id":"some-node","manager_count":999}')
        with self.assertRaises(ValueError): self.run_enroll(['owner@example.com'])
        self.assertFalse((self.root/'etc/tarasec/agent-enrollment.json').exists())


if __name__=='__main__': unittest.main()
