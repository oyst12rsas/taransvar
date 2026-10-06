import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location("report", Path(__file__).with_name("ssh_login_report.py"))
report = importlib.util.module_from_spec(spec)
spec.loader.exec_module(report)

def row(message, pid="1", boot="boot", tag="sshd"):
    return {"MESSAGE":message, "_PID":pid, "_BOOT_ID":boot, "SYSLOG_IDENTIFIER":tag,
            "__REALTIME_TIMESTAMP":"1791260000000000", "__CURSOR":message}

class LoginReportTest(unittest.TestCase):
    def test_requires_same_connection_for_two_factors(self):
        records = [row("Partial publickey for ubuntu from 100.68.10.7 port 1234 ssh2"),
                   row("Accepted password for ubuntu from 100.68.10.7 port 1234 ssh2"),
                   row("Accepted password for ubuntu from 100.68.10.7 port 1234 ssh2", pid="2")]
        events = report.parse_records(records)
        self.assertEqual(events[0]["authentication"], "password")
        self.assertEqual(events[1]["authentication"], "publickey+password")

    def test_filters_failure_and_honeypot_and_accepts_ipv6(self):
        events = report.parse_records([
            row("Failed password for root from 1.2.3.4 port 22 ssh2"),
            row("Accepted password for root from 1.2.3.4 port 22 ssh2", tag="honeypot"),
            row("Accepted publickey for ubuntu from ::1 port 5 ssh2", tag="sshd-session")])
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["source"], "::1")

    def test_limit(self):
        self.assertEqual(len(report.parse_records([row("Accepted publickey for u from ::1 port 5 ssh2")] * 70)), 50)

    def test_disabled_skips_journal_and_has_no_events(self):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "conf"
            config.write_text("SSH_LOGIN_REPORT_ENABLED=no\n")
            with patch.object(report, "CONFIG", config), patch.object(report.subprocess, "run") as command:
                result = report.collect()
                self.assertEqual(result["summary"], "report disabled")
                self.assertNotIn("events", result)
                command.assert_not_called()

if __name__ == "__main__":
    unittest.main()
