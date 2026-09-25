#!/usr/bin/env python3
import importlib.util, os, tempfile, unittest
from pathlib import Path

spec=importlib.util.spec_from_file_location("manager",Path(__file__).with_name("tarasec-server-manager.py")); manager=importlib.util.module_from_spec(spec); spec.loader.exec_module(manager)

class PolicyTests(unittest.TestCase):
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

if __name__=="__main__": unittest.main()
