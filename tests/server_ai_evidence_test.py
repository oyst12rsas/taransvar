import base64
import json
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).parents[1] / "misc"))
import server_ai_evidence as ev  # noqa: E402
import server_ai_report_client as client  # noqa: E402

KEY = "11" * 32
NOW = 1795000000


def row(message, tag="systemd", priority=4, unit=None, usec=NOW * 10**6):
    r = {"MESSAGE": message, "SYSLOG_IDENTIFIER": tag, "PRIORITY": str(priority),
         "__REALTIME_TIMESTAMP": str(usec)}
    if unit:
        r["UNIT"] = unit
    return r


def user(name, home="/nonexistent"):
    return SimpleNamespace(pw_name=name, pw_dir=home)


class RedactionTest(unittest.TestCase):
    def setUp(self):
        self.r = ev.Redactor(KEY)

    def test_masks_secrets_emails_and_tokens(self):
        text = self.r.text("login password=hunter2 by ops@example.com token abcdef0123456789abcdef0123")
        self.assertNotIn("hunter2", text)
        self.assertNotIn("ops@example.com", text)
        self.assertNotIn("abcdef0123456789abcdef0123", text)
        self.assertIn("password=<redacted>", text)

    def test_keeps_unit_names_and_paths(self):
        text = "Failed to start tarasec-ssh-approval-poll.service in /usr/local/lib/tarasec-operations/run"
        self.assertEqual(self.r.text(text), text)

    def test_public_ips_are_pseudonyms_private_kept(self):
        text = self.r.text("from 8.8.4.4 and 100.68.10.7 and 10.0.0.1 and 2606:4700::1111 at 12:34:56")
        self.assertNotIn("8.8.4.4", text)
        self.assertIn("100.68.10.7", text)
        self.assertIn("10.0.0.1", text)
        self.assertIn("12:34:56", text)
        self.assertNotIn("2606:4700::1111", text)
        self.assertEqual(self.r.ip("8.8.4.4"), self.r.ip("8.8.4.4"))
        self.assertNotEqual(ev.Redactor("22" * 32).ip("8.8.4.4"), self.r.ip("8.8.4.4"))

    def test_template_folds_numbers_and_ips(self):
        a = ev.template(self.r.text("Found left-over process 506053 (perl) from 8.8.8.8"))
        b = ev.template(self.r.text("Found left-over process 1681 (perl) from 1.1.1.1"))
        self.assertEqual(a, b)


class LogsTopicTest(unittest.TestCase):
    def test_groups_orders_and_tracks_novelty(self):
        rows = [row("cron.service: Found left-over process %d (perl)" % n) for n in range(5)]
        rows += [row("Failed to start x.service.", priority=3, unit="x.service")]
        rows += [row("IN=eth0 OUT= SRC=1.2.3.4 DST=5.6.7.8", tag="kernel")]
        seen = {}
        topic = ev.logs_topic(rows, ev.Redactor(KEY), seen, NOW, baseline=True)
        self.assertEqual(topic["entries"], 6)
        self.assertEqual(topic["firewall_log_lines"], 1)
        self.assertEqual(topic["groups"][0]["priority"], 3)
        self.assertEqual(topic["groups"][0]["unit"], "x.service")
        self.assertEqual(topic["groups"][1]["count"], 5)
        self.assertTrue(all(g["new"] for g in topic["groups"]))
        again = ev.logs_topic(rows, ev.Redactor(KEY), seen, NOW, baseline=False)
        self.assertEqual(again["new_messages"], 0)

    def test_fits_byte_budget(self):
        rows = [row("message " + "".join(chr(97 + (i >> s) % 26) for s in range(0, 30, 3))) for i in range(400)]
        topic = ev.logs_topic(rows, ev.Redactor(KEY), {}, NOW, baseline=True)
        self.assertLessEqual(len(json.dumps(topic, separators=(",", ":"))), ev.TOPIC_BYTES)
        self.assertTrue(topic["trimmed"])
        self.assertEqual(topic["entries"], 400)


class AuthTopicTest(unittest.TestCase):
    def topic(self, rows, keys=None):
        return ev.auth_topic(rows, ev.Redactor(KEY), {"owner", "root"}, keys or {})

    def test_ssh_activity(self):
        t = self.topic([
            row("Accepted password for owner from 100.68.10.7 port 5000 ssh2", tag="sshd"),
            row("Accepted publickey for owner from 100.68.10.7 port 5001 ssh2", tag="sshd-session"),
            row("Failed password for root from 8.8.4.4 port 1 ssh2", tag="sshd"),
            row("Failed password for invalid user admin from 8.8.4.4 port 2 ssh2", tag="sshd"),
            row("Failed password for invalid user hunter2 from 1.0.0.1 port 3 ssh2", tag="sshd"),
            row("User claude from 100.68.10.7 not allowed because not listed in AllowUsers", tag="sshd"),
            row("connection from 9.9.9.9", tag="tarasec-ssh-honeypot"),
        ])
        self.assertEqual(len(t["ssh_logins"]), 2)
        f = t["ssh_failures"]
        self.assertEqual((f["total"], f["attempts_on_unknown_names"], f["distinct_unknown_names"]), (3, 2, 2))
        self.assertEqual(f["local_accounts"][0]["user"], "root")
        self.assertEqual(f["distinct_sources"], 2)
        self.assertNotIn("hunter2", json.dumps(t))
        self.assertNotIn("8.8.4.4", json.dumps(t))
        self.assertEqual(t["ssh_not_allowed"][0]["user"], "<not a local account>")
        self.assertEqual(t["honeypot"], {"events": 1, "distinct_sources": 1})

    def test_sudo_su_and_accounts(self):
        t = self.topic([
            row("    root : PWD=/root ; USER=root ; COMMAND=/usr/sbin/iptables -L -n", tag="sudo"),
            row("    root : PWD=/root ; USER=root ; COMMAND=/usr/sbin/iptables -L -n", tag="sudo"),
            row("   owner : 3 incorrect password attempts ; TTY=pts/0 ; PWD=/home/owner ; USER=root ; "
                "COMMAND=/usr/bin/curl -H token=abc123secret", tag="sudo"),
            row("pam_unix(su:session): session opened for user root(uid=0) by owner(uid=1000)", tag="su"),
            row("new user: name=backdoor, UID=1001, GID=1001, home=/home/backdoor", tag="useradd"),
        ])
        self.assertEqual(t["sudo"][0]["count"], 2)
        bad = t["sudo"][1]
        self.assertEqual(bad["problem"], "3 incorrect password attempts")
        self.assertNotIn("abc123secret", bad["command"])
        self.assertEqual(t["su"][0]["target"], "root")
        self.assertEqual(t["account_changes"][0]["tool"], "useradd")


class AuthorizedKeysTest(unittest.TestCase):
    def test_baseline_then_added_and_removed(self):
        blob1 = base64.b64encode(b"\0\0\0\x0bssh-ed25519" + b"A" * 32).decode()
        blob2 = base64.b64encode(b"\0\0\0\x0bssh-ed25519" + b"B" * 32).decode()
        with tempfile.TemporaryDirectory() as home:
            ssh = Path(home, ".ssh")
            ssh.mkdir()
            keys = ssh / "authorized_keys"
            keys.write_text(f"ssh-ed25519 {blob1} me@example.com\n")
            report, state = ev.authorized_keys([user("owner", home)], None)
            self.assertTrue(report["baseline_run"])
            self.assertEqual(len(report["accounts"][0]["current"]), 1)
            self.assertNotIn("example.com", json.dumps(report))
            keys.write_text(f'from="1.2.3.4" ssh-ed25519 {blob2} new\n')
            report, _ = ev.authorized_keys([user("owner", home)], state)
            account = report["accounts"][0]
            self.assertEqual(len(account["added"]), 1)
            self.assertTrue(account["added"][0]["restricted"])
            self.assertEqual(account["removed"], state["owner"])


class CollectTest(unittest.TestCase):
    def test_window_state_and_report_shape(self):
        calls = []

        def fake_journal(since, until, args):
            calls.append((since, until, args))
            if args == ("-p", "warning"):
                return [row("Failed to start a.service.", priority=3)], False
            return [row("Accepted publickey for root from 10.0.0.2 port 1 ssh2", tag="sshd")], False

        state = {"key": KEY, "last_until": NOW - 600}
        found, new_state = ev.collect(["logs", "auth"], state, now=NOW,
                                      read_journal=fake_journal, users=[user("root")])
        self.assertEqual(calls[0][:2], (NOW - 600, NOW))
        self.assertIn("-t", calls[1][2])
        self.assertEqual(new_state["last_until"], NOW)
        self.assertEqual(found["logs"]["groups"][0]["template"], "Failed to start a.service.")
        self.assertEqual(found["auth"]["ssh_logins"][0]["source"], "10.0.0.2")
        self.assertEqual(found["logs"]["context"]["window"]["seconds"], 600)
        self.assertEqual(state["last_until"], NOW - 600)  # caller saves only after submission

        old = {"key": KEY, "last_until": NOW - 30 * 86400}
        ev.collect(["logs"], old, now=NOW, read_journal=fake_journal, users=[])
        self.assertEqual(calls[-1][0], NOW - ev.MAX_WINDOW)

    def test_state_roundtrip_is_private(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp, "state.json")
            fresh = ev.load_state(path)
            self.assertRegex(fresh["key"], r"^[0-9a-f]{64}$")
            fresh["seen"] = {str(i): i for i in range(ev.MAX_SEEN + 10)}
            ev.save_state(fresh, path)
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            loaded = ev.load_state(path)
            self.assertEqual(len(loaded["seen"]), ev.MAX_SEEN)
            self.assertNotIn("0", loaded["seen"])


class ClientTest(unittest.TestCase):
    def test_topic_parsing(self):
        self.assertEqual(client.parse_topics("logs, auth"), ["logs", "auth"])
        with self.assertRaises(ValueError):
            client.parse_topics("logs,bogus")

    def test_state_saved_only_after_submission(self):
        saved = []

        class Response:
            def __init__(self, body):
                self.body = json.dumps(body).encode()

            def read(self, _):
                return self.body

            def __enter__(self):
                return self

            def __exit__(self, *a):
                return False

        class Opener:
            def open(self, request, timeout):
                if request.full_url.endswith("/reports"):
                    return Response({"report_id": "a" * 32})
                return Response({"results": {"logs": {"status": "done", "summary": "ok"}}})

        out = []
        rc = client.submit_and_poll("https://d.example", "n", "t", {"topics": {"logs": {}}}, 5,
                                    emit=out.append, opener=Opener(), on_submitted=saved.append)
        self.assertEqual((rc, saved), (0, ["a" * 32]))
        self.assertEqual(out[-1]["topic"], "logs")


if __name__ == "__main__":
    unittest.main()
