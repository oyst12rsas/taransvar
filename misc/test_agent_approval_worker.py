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

    def test_containment_rule_changes_input_not_forward(self):
        completed = mock.Mock(returncode=0)
        with mock.patch.object(worker, "run", return_value=completed) as run:
            worker.containment_rule(48222)
        argv = run.call_args.args
        self.assertIn("INPUT", argv)
        self.assertNotIn("FORWARD", argv)
        self.assertIn("NEW", argv)


if __name__ == "__main__":
    unittest.main()
