import hashlib
import os
import stat
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest import mock
import local_manager_policy as policy
import agent_approval_worker as worker
import ssh_google_window as window
import ssh_approval_poll as poll


class LocalPolicyTests(unittest.TestCase):
    def read(self, text):
        with tempfile.TemporaryDirectory() as folder:
            path=Path(folder)/'policy'; path.write_text(text); path.chmod(0o600)
            with mock.patch.object(policy.os,'fstat',return_value=SimpleNamespace(st_mode=stat.S_IFREG|0o600,st_uid=0)):
                return policy.manager_hashes(str(path))

    def test_normalized_emails_and_hashes_union(self):
        digest=policy.email_hash('owner@example.com')
        other=policy.email_hash('other@example.com')
        self.assertEqual(self.read('MANAGER_EMAILS=" OWNER@example.com ,owner@example.com"\nMANAGER_EMAIL_SHA256="'+other+'"'),{digest,other})
        self.assertEqual(self.read('MANAGER_EMAILS=""'),set())

    def test_malformed_policy_and_permissions_fail_closed(self):
        for text in ['MANAGER_EMAILS="bad"','MANAGER_EMAILS="*"','MANAGER_EMAIL_SHA256="bad"','MANAGER_EMAILS=a@example.com\nMANAGER_EMAILS=b@example.com','UNKNOWN=value']:
            with self.assertRaises(ValueError): self.read(text)
        with tempfile.NamedTemporaryFile() as source:
            with mock.patch.object(policy.os,'fstat',return_value=SimpleNamespace(st_mode=stat.S_IFREG|0o644,st_uid=0)):
                with self.assertRaises(RuntimeError): policy.manager_hashes(source.name)
            with mock.patch.object(policy.os,'fstat',return_value=SimpleNamespace(st_mode=stat.S_IFREG|0o600,st_uid=1000)):
                with self.assertRaises(RuntimeError): policy.manager_hashes(source.name)
        with self.assertRaises(FileNotFoundError): policy.manager_hashes('/nonexistent/local-access-policy')

    def test_revoke_is_read_on_every_job_and_all_operations_checked(self):
        digest=policy.email_hash('owner@example.com')
        with mock.patch.object(policy,'manager_hashes',side_effect=[{digest},set()]):
            policy.require_manager(digest)
            with self.assertRaises(RuntimeError): policy.require_manager(digest)
        for job in [{'operation':'open_ssh_temporarily'}, {'operation':'disable_obsolete_gateway_unit'}, {'operation':'arbitrary'}]:
            with mock.patch.object(worker,'run') as run, mock.patch.object(worker,'disable_obsolete_gateway_unit') as disable:
                with self.assertRaises(RuntimeError): worker.execute_approved_job(job)
                run.assert_not_called(); disable.assert_not_called()

    def test_helper_checks_identity_before_any_firewall_change(self):
        with mock.patch.object(policy,'manager_hashes',return_value=set()),mock.patch.object(window,'command') as command:
            with self.assertRaises(RuntimeError): window.open_window('a'*32,9999999999,'b'*64)
            command.assert_not_called()

    def test_reconcile_closes_window_after_local_revocation(self):
        with mock.patch.object(window,'configuration',return_value=(4810,[])), \
             mock.patch.object(window,'read_state',return_value={'until':9999999999,'approved_operator_sha256':'a'*64}), \
             mock.patch.object(policy,'manager_hashes',return_value=set()), \
             mock.patch.object(window,'apply_rules') as rules:
            window.reconcile(); rules.assert_called_once_with(4810,[],0)

    def test_fast_poller_uses_same_local_authorization(self):
        with tempfile.NamedTemporaryFile(mode='w') as token:
            token.write('a'*64); token.flush()
            job={'id':'b'*32,'nonce':'c'*32,'operation':'open_ssh_temporarily','approved_operator_sha256':'d'*64}
            with mock.patch.object(poll.os,'geteuid',return_value=0), \
                 mock.patch.object(worker,'enabled',return_value=True),mock.patch.object(worker,'TOKEN_PATH',token.name), \
                 mock.patch.object(worker,'api',side_effect=[{'job':job},{'ok':True}]) as api, \
                 mock.patch.object(policy,'manager_hashes',return_value=set()),mock.patch.object(worker,'run') as run:
                with self.assertRaises(RuntimeError): poll.poll_once()
                run.assert_not_called()
                self.assertFalse(api.call_args.args[1]['success'])
                self.assertNotIn('MANAGER_EMAIL',str(api.call_args_list))

if __name__ == '__main__': unittest.main()
