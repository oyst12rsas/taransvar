#!/usr/bin/env python3
import importlib.util
import os
import sys
import tempfile
import time
import unittest
from unittest import mock


HERE = os.path.dirname(__file__)
sys.path.insert(0, HERE)
SPEC = importlib.util.spec_from_file_location(
    "agent_approval_worker", os.path.join(HERE, "agent_approval_worker.py"))
worker = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(worker)


def evidence(gateway=False):
    return {
        "tarasecfw_selected_fields": {"IS_GATEWAY": "1" if gateway else "0", "SSH_PORT": "48222"},
        "ip_forward": "1",
        "services": {"tarasec-gateway.service": {"stdout": "ActiveState=active"}},
    }


class ServerManagerTests(unittest.TestCase):
    def test_minute_status_is_bounded_and_keeps_pending_approval(self):
        item = evidence()
        item.update({
            "sshd_effective_selected_fields": {"stdout": "authenticationmethods publickey,password\npasswordauthentication yes\n"},
            "ssh_listeners": {"exit_code": 0}, "sshd_syntax": {"exit_code": 0},
            "filter_rules": {"exit_code": 0}, "ipv6_filter_rules": {"exit_code": 0},
        })
        report = worker.status_snapshot(item, ["Failed local service"],
                                        {"verified": True}, {"ongoing": False}, [],
                                        ["Approval pending: disable obsolete gateway service"],
                                        "connected")
        self.assertEqual(report["status"], "attention")
        self.assertEqual(report["ssh_protection"]["authentication_methods"], "publickey,password")
        self.assertFalse(report["ssh_protection"]["password_alone_possible"])
        self.assertEqual(len(report["pending_operator_messages"]), 2)
        self.assertNotIn("48222", str(report))
        with tempfile.TemporaryDirectory() as folder, \
                mock.patch.object(worker, "STATUS_PATH", os.path.join(folder, "status.json")):
            worker.write_status_snapshot(report)
            self.assertEqual(os.stat(worker.STATUS_PATH).st_mode & 0o777, 0o600)

    def test_terminal_requires_declaration_and_fresh_heartbeat(self):
        with tempfile.NamedTemporaryFile() as heartbeat:
            values = {
                "TERMINAL_AVAILABLE": "yes",
                "TERMINAL_HEARTBEAT_FILE": heartbeat.name,
                "TERMINAL_HEARTBEAT_MAX_AGE_SECONDS": "120",
            }
            with mock.patch.object(worker, "manager_setting",
                                   side_effect=lambda name, default="": values.get(name, default)):
                self.assertTrue(worker.terminal_state()["verified"])
                os.utime(heartbeat.name, (time.time() - 180, time.time() - 180))
                self.assertFalse(worker.terminal_state()["verified"])

    def test_assessment_prioritizes_intrusion_before_forwarding(self):
        item = worker.assessment(evidence(gateway=True), [], {"verified": True},
                                 {"ongoing": True}, [])
        self.assertEqual(item["priority"], "contain_intrusion")
        self.assertTrue(item["forwarding"]["ip_forward"])

    def test_containment_requires_all_three_guards(self):
        attack = {"ongoing": True}
        terminal = {"verified": True}
        with mock.patch.object(worker, "enabled", return_value=False), \
                mock.patch.object(worker, "containment_rule") as rule:
            self.assertIsNone(worker.contain_ssh(evidence(), terminal, attack))
            rule.assert_not_called()

    def test_containment_rule_changes_input_not_forward(self):
        completed = mock.Mock(returncode=0)
        with mock.patch.object(worker, "run", return_value=completed) as run:
            worker.containment_rule(48222)
        argv = run.call_args.args
        self.assertIn("INPUT", argv)
        self.assertNotIn("FORWARD", argv)
        self.assertIn("NEW", argv)

    def test_established_sessions_are_limited_to_admin_port_and_sshd(self):
        listing = mock.Mock(returncode=0, stdout=(
            '0 0 10.0.0.1:48222 10.0.0.2:50000 users:(("sshd",pid=321,fd=4))\n'
            '0 0 10.0.0.1:443 10.0.0.3:50001 users:(("nginx",pid=22,fd=8))\n'
            '0 0 [::1]:48222 [::1]:50002 users:(("sshd",pid=654,fd=4))\n'))
        with mock.patch.object(worker, "run", return_value=listing):
            self.assertEqual(worker.established_ssh_session_pids(48222), [321, 654])

    def test_existing_session_termination_requires_all_guards(self):
        with mock.patch.object(worker, "enabled", return_value=True), \
                mock.patch.object(worker, "established_ssh_session_pids", return_value=[321]), \
                mock.patch.object(worker.os, "kill") as kill:
            self.assertIsNone(worker.terminate_existing_ssh_sessions(
                evidence(), {"verified": False}, {"ongoing": True}))
            kill.assert_not_called()


if __name__ == "__main__":
    unittest.main()
