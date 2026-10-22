import unittest
from unittest import mock
import agent_approval_worker as worker

class OperatorSSHControls(unittest.TestCase):
    def job(self):
        return {'operation':'close_ssh_and_disconnect','id':'a'*32,'approved_operator_sha256':'b'*64}

    def test_manual_close_precedes_disconnect_and_uses_local_guards(self):
        events=[]
        with mock.patch.object(worker.local_manager_policy,'require_manager') as manager, \
             mock.patch.object(worker,'enabled',return_value=True), \
             mock.patch.object(worker,'terminal_state',return_value={'verified':True}), \
             mock.patch.object(worker,'run',side_effect=lambda *a:(events.append('close') or mock.Mock(returncode=0))) as run, \
             mock.patch.object(worker.ssh_security_evidence,'collect',return_value={'tarasecfw_selected_fields':{'SSH_PORT':'5822'}}), \
             mock.patch.object(worker,'established_ssh_session_pids',return_value=[]), \
             mock.patch.object(worker,'terminate_existing_ssh_sessions',side_effect=lambda *a,**k:(events.append('disconnect') or {'result':'applied'})) as terminate, \
             mock.patch.object(worker,'audit'):
            self.assertTrue(worker.execute_approved_job(self.job()))
            self.assertEqual(events,['close','disconnect'])
            self.assertIn('lock',run.call_args.args)
            self.assertTrue(terminate.call_args.kwargs['operator_requested'])
            manager.assert_called_with('b'*64)

    def test_stale_console_or_disabled_permission_cannot_disconnect(self):
        for permission,console in ((False,True),(True,False)):
            with mock.patch.object(worker.local_manager_policy,'require_manager'), \
                 mock.patch.object(worker,'enabled',return_value=permission), \
                 mock.patch.object(worker,'terminal_state',return_value={'verified':console}), \
                 mock.patch.object(worker,'run') as run:
                with self.assertRaises(RuntimeError):worker.execute_approved_job(self.job())
                run.assert_not_called()

    def test_failed_gate_close_never_disconnects(self):
        with mock.patch.object(worker.local_manager_policy,'require_manager'), \
             mock.patch.object(worker,'enabled',return_value=True), \
             mock.patch.object(worker,'terminal_state',return_value={'verified':True}), \
             mock.patch.object(worker,'run',return_value=mock.Mock(returncode=1)), \
             mock.patch.object(worker,'terminate_existing_ssh_sessions') as terminate, \
             mock.patch.object(worker,'audit'):
            self.assertFalse(worker.execute_approved_job(self.job()))
            terminate.assert_not_called()
