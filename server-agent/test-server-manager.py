#!/usr/bin/env python3
import importlib.util, json, os, tempfile, unittest
from pathlib import Path

spec=importlib.util.spec_from_file_location("manager",Path(__file__).with_name("tarasec-server-manager.py")); manager=importlib.util.module_from_spec(spec); spec.loader.exec_module(manager)

class PolicyTests(unittest.TestCase):
    def test_agent_mode_defaults_conservative(self):
        self.assertEqual(manager.agent_mode({}), "conservative")
    def test_agent_mode_accepts_documented_values(self):
        for value in ("disabled", "conservative", "defensive", "autonomous"):
            self.assertEqual(manager.agent_mode({"AI_AGENT_MODE":value}), value)
    def test_agent_mode_unknown_falls_back_conservative(self):
        self.assertEqual(manager.agent_mode({"AI_AGENT_MODE":"surprise"}), "conservative")
    def test_rejects_shell_text(self):
        with self.assertRaises(RuntimeError): manager.services({"SERVICE_ALLOWLIST":"ok.service;reboot"})
    def test_rejects_unlisted_action(self):
        with self.assertRaises(RuntimeError): manager.validate({"SERVICE_ALLOWLIST":"safe.service"},{"action":"restart_service","service":"other.service"})
    def test_redacts_secrets(self):
        text=manager.redact("api_key=abc password: def Authorization: Bearer-xyz sk-abcdefghijklmno")
        for value in ("abc","def","Bearer-xyz","sk-abcdefghijklmno"): self.assertNotIn(value,text)
    def test_atomic_permissions(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/"value.json"; manager.atomic_json(path,{"ok":True}); self.assertEqual(os.stat(path).st_mode&0o777,0o600)
    def test_disabled_mode_replaces_old_proposal(self):
        with tempfile.TemporaryDirectory() as directory:
            cfg={"AI_AGENT_MODE":"disabled","STATE_DIRECTORY":directory}
            manager.atomic_json(Path(directory)/"proposal.json",{"proposal":{"severity":"critical"}})
            manager.advise(cfg)
            with open(Path(directory)/"proposal.json",encoding="utf-8") as handle: saved=json.load(handle)
            self.assertEqual(saved["status"],"disabled")
            self.assertIsNone(saved["proposal"])

if __name__=="__main__": unittest.main()
