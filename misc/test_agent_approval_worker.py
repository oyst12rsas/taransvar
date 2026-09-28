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

    def test_protective_mode_requires_attack_and_verified_console(self):
        with mock.patch.object(worker, "agent_mode", return_value="protective"), \
                mock.patch.object(worker, "enabled", return_value=False), \
                mock.patch.object(worker, "containment_rule") as rule:
            self.assertIsNone(worker.contain_ssh(evidence(), {"verified": False}, {"ongoing": True}))
            self.assertIsNone(worker.contain_ssh(evidence(), {"verified": True}, {"ongoing": False}))
            rule.assert_not_called()

    def test_protective_mode_applies_bounded_new_connection_rule(self):
        done = mock.Mock(returncode=0)
        with mock.patch.object(worker, "agent_mode", return_value="protective"), \
                mock.patch.object(worker, "enabled", return_value=False), \
                mock.patch.object(worker, "containment_rule", return_value=done) as rule, \
                mock.patch.object(worker, "run", return_value=done), \
                mock.patch.object(worker, "forwarding_health", return_value={"is_gateway": False}), \
                mock.patch.object(worker.ssh_security_evidence, "collect", return_value=evidence()), \
                mock.patch.object(worker, "audit"), \
                mock.patch.object(worker.os.path, "exists", return_value=False), \
                mock.patch.object(worker.os, "makedirs"), \
                mock.patch("builtins.open", mock.mock_open()):
            result = worker.contain_ssh(evidence(), {"verified": True}, {"ongoing": True})
        self.assertEqual(result["result"], "applied")
        self.assertFalse(result["existing_sessions_interrupted"])
        rule.assert_called_once_with(48222)

    def test_unrecognized_mode_falls_back_to_conservative(self):
        with mock.patch.object(worker, "manager_setting", return_value="unrestricted"):
            self.assertEqual(worker.agent_mode(), "conservative")

    def test_protective_mode_warns_when_existing_sessions_remain(self):
        with mock.patch.object(worker, "agent_mode", return_value="protective"), \
                mock.patch.object(worker, "enabled", return_value=False):
            self.assertEqual(worker.policy_warnings(),
                             ["Protective mode does not terminate existing SSH sessions"])
        with mock.patch.object(worker, "agent_mode", return_value="protective"), \
                mock.patch.object(worker, "enabled", return_value=True):
            self.assertEqual(worker.policy_warnings(), [])

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
