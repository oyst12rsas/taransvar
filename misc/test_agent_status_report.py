#!/usr/bin/env python3
"""Exercise the actual Perl status reader without starting cron or a database."""
import json
import os
import re
import subprocess
import tempfile
import time
import unittest


class AgentMinuteStatusTests(unittest.TestCase):
    def test_explicit_non_gateway_role(self):
        with open(os.path.join(os.path.dirname(__file__), "crontasks.pl"), encoding="utf-8") as source:
            script = source.read()
        helper = re.search(r"sub configuredNodeGatewayRole \{.*?\n\}\n\nsub reportStatus", script, re.S)
        self.assertIsNotNone(helper)
        with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8") as config:
            config.write("IS_GATEWAY=0\n")
            config.flush()
            perl = (helper.group(0).removesuffix("\n\nsub reportStatus") +
                    "\nmy $role = configuredNodeGatewayRole($ARGV[0]); print defined($role) ? $role : 'missing';")
            result = subprocess.run(["perl", "-e", perl, config.name],
                                    text=True, capture_output=True, check=True)
            self.assertEqual(result.stdout, "0")

    def test_disabled_fresh_and_stale(self):
        with open(os.path.join(os.path.dirname(__file__), "crontasks.pl"), encoding="utf-8") as target:
            source = target.read()
        helper = re.search(r"sub agentStatusForReport \{.*?\n\}\n\nsub reportStatus", source, re.S)
        self.assertIsNotNone(helper)
        with tempfile.TemporaryDirectory() as directory:
            config = os.path.join(directory, "manager.conf")
            snapshot = os.path.join(directory, "status.json")
            now = int(time.time())
            with open(config, "w", encoding="utf-8") as target:
                target.write("AI_STATUS_REPORT_ENABLED=no\n")
            with open(snapshot, "w", encoding="utf-8") as target:
                json.dump({"checked_at": now, "mode": "conservative", "status": "ok"}, target)
            perl = ("use JSON::PP; *JSON::decode_json = \\&JSON::PP::decode_json; " +
                    helper.group(0).removesuffix("\n\nsub reportStatus") +
                    "\nprint JSON::PP::encode_json(agentStatusForReport(@ARGV));")
            def read(at):
                result = subprocess.run(["perl", "-e", perl, config, snapshot, str(at)],
                                        text=True, capture_output=True, check=True)
                return json.loads(result.stdout)
            self.assertEqual(read(now), "report disabled")
            with open(config, "w", encoding="utf-8") as target:
                target.write("AI_STATUS_REPORT_ENABLED=yes\n")
            self.assertEqual(read(now + 60)["age_seconds"], 60)
            self.assertEqual(read(now + 181)["status"], "stale")


if __name__ == "__main__":
    unittest.main()
