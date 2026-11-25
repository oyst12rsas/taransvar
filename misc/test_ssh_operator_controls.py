import subprocess
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
             mock.patch.object(worker.ssh_security_evidence,'collect',return_value={'tarasecfw_selected_fields':{}}), \
             mock.patch.object(worker,'established_ssh_session_pids',return_value=[]) as sessions, \
             mock.patch.object(worker,'terminate_existing_ssh_sessions',side_effect=lambda *a,**k:(events.append('disconnect') or {'result':'applied'})) as terminate, \
             mock.patch.object(worker,'audit'):
            self.assertTrue(worker.execute_approved_job(self.job()))
            self.assertEqual(events,['close','disconnect'])
            self.assertIn('lock',run.call_args.args)
            sessions.assert_called_once_with(22)
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

class EstablishedSessionMatching(unittest.TestCase):
    def test_sessions_on_any_port_are_found_but_not_the_honeypot(self):
        # -Htnp state established: Recv-Q Send-Q Local Peer Process
        out = ('0 0 100.68.56.81:4810 100.68.10.7:45426 users:(("sshd-session",pid=301,fd=4),("sshd-session",pid=300,fd=4))\n'
               '0 0 100.68.56.81:22 100.68.10.7:43750 users:(("sshd",pid=236527,fd=4),("sshd",pid=236453,fd=4))\n'
               '0 0 100.68.56.81:22 203.0.113.9:51000 users:(("python3",pid=243006,fd=7))\n'
               '0 0 127.0.0.1:3306 127.0.0.1:40000 users:(("mariadbd",pid=900,fd=20))\n')
        with mock.patch.object(worker, 'run', return_value=subprocess.CompletedProcess([], 0, out, '')):
            self.assertEqual(worker.established_ssh_session_pids(4810), [300, 301, 236453, 236527])
