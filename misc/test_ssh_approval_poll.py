#!/usr/bin/env python3
import tempfile
import unittest
from pathlib import Path
from unittest import mock
import ssh_approval_poll as poll


class PollTests(unittest.TestCase):
    def test_disabled_policy_does_not_contact_api(self):
        with mock.patch.object(poll.os, 'geteuid', return_value=0), \
             mock.patch.object(poll.worker, 'enabled', return_value=False), \
             mock.patch.object(poll.worker, 'api') as api:
            poll.poll_once()
            api.assert_not_called()

    def test_lightweight_poll_has_no_assessment_or_model(self):
        with tempfile.TemporaryDirectory() as folder:
            token = Path(folder)/'token'; token.write_text('a'*64+'\n')
            with mock.patch.object(poll.os, 'geteuid', return_value=0), \
                 mock.patch.object(poll.worker, 'enabled', return_value=True), \
                 mock.patch.object(poll.worker, 'TOKEN_PATH', str(token)), \
                 mock.patch.object(poll.worker, 'api', return_value={'job':None}) as api, \
                 mock.patch.object(poll.worker.ssh_security_evidence, 'collect') as collect:
                poll.poll_once()
                api.assert_called_once_with('poll', {}, 'a'*64)
                collect.assert_not_called()

    def test_invalid_deadline_is_reported_failed_without_opening(self):
        with tempfile.TemporaryDirectory() as folder:
            token=Path(folder)/'token'; token.write_text('a'*64)
            job={'approved_operator_sha256':'d'*64,'operation':'open_ssh_temporarily','open_until':True,'id':'b'*32,'nonce':'c'*32}
            with mock.patch.object(poll.os, 'geteuid', return_value=0), \
                 mock.patch.object(poll.worker, 'enabled', return_value=True), \
                 mock.patch.object(poll.worker, 'TOKEN_PATH', str(token)), \
                 mock.patch.object(poll.worker, 'api', side_effect=[{'job':job},{'ok':True}]) as api, \
                 mock.patch.object(poll.worker.local_manager_policy, 'require_manager'), \
                 mock.patch.object(poll.window, 'open_window') as opening:
                with self.assertRaises(ValueError): poll.poll_once()
                opening.assert_not_called()
                self.assertEqual(api.call_args.args, ('result', {'id':job['id'],'nonce':job['nonce'],'success':False},'a'*64))


    def test_success_reports_checked_expiry_immediately_without_assessment(self):
        with tempfile.TemporaryDirectory() as folder:
            token = Path(folder)/'token'; token.write_text('a'*64)
            job = {'operation':'open_ssh_temporarily','id':'b'*32,'nonce':'c'*32}
            status = {'enabled':True,'state':'open','expires_at':12345}
            with mock.patch.object(poll.os, 'geteuid', return_value=0), \
                 mock.patch.object(poll.worker, 'enabled', return_value=True), \
                 mock.patch.object(poll.worker, 'TOKEN_PATH', str(token)), \
                 mock.patch.object(poll.worker, 'api', side_effect=[{'job':job},{'ok':True}]) as api, \
                 mock.patch.object(poll.worker, 'execute_approved_job', return_value=True), \
                 mock.patch.object(poll.worker, 'google_ssh_state', return_value=status) as read, \
                 mock.patch.object(poll.worker.ssh_security_evidence, 'collect') as collect:
                poll.poll_once()
                read.assert_called_once_with()
                collect.assert_not_called()
                self.assertEqual(api.call_args.args, ('result',
                    {'id':job['id'],'nonce':job['nonce'],'success':True,'ssh_access':status}, 'a'*64))

    def test_failed_status_collection_still_acknowledges_execution(self):
        job = {'operation':'open_ssh_temporarily','id':'b'*32,'nonce':'c'*32}
        with mock.patch.object(poll.worker, 'google_ssh_state', side_effect=RuntimeError('unavailable')), \
             mock.patch.object(poll.worker, 'api') as api:
            poll.worker.report_approved_result(job, True, 'token')
            api.assert_called_once_with('result',
                {'id':job['id'],'nonce':job['nonce'],'success':True}, 'token')

    def test_failed_execution_does_not_claim_an_opening(self):
        job = {'operation':'open_ssh_temporarily','id':'b'*32,'nonce':'c'*32}
        with mock.patch.object(poll.worker, 'google_ssh_state') as read, \
             mock.patch.object(poll.worker, 'api') as api:
            poll.worker.report_approved_result(job, False, 'token')
            read.assert_not_called()
            self.assertNotIn('ssh_access', api.call_args.args[1])


if __name__ == '__main__': unittest.main()
