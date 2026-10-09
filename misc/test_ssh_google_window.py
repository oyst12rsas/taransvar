import ipaddress
import unittest
from unittest.mock import patch
import ssh_google_window as w

class Windows(unittest.TestCase):
    def test_tcp_reset_requires_explicit_tcp_in_each_chain(self):
        calls = []
        def command(*args, **kwargs):
            calls.append(args)
            if "--reject-with" in args and "tcp-reset" in args:
                if "-p" not in args or args[args.index("-p") + 1] != "tcp":
                    raise RuntimeError("TCP reset requires TCP protocol")
            return "-C" not in args
        with patch.object(w, "command", side_effect=command):
            w.apply_rules(5822, [], 0)
        rejects = [a for a in calls if "-A" in a and w.CHAIN in a and "REJECT" in a]
        self.assertEqual({a[0] for a in rejects}, {"iptables", "ip6tables"})

    def test_expired_and_long_windows_refused_without_firewall_mutation(self):
        with patch.object(w, "configuration", return_value=(5822, [])), patch.object(w.time, "time", return_value=1000), patch.object(w, "apply_rules") as apply:
            for until in (999, 1000, 1901):
                with self.assertRaises(ValueError):
                    w.open_window("a"*32, until)
            apply.assert_not_called()

    def test_timer_failure_never_opens(self):
        with patch.object(w, "configuration", return_value=(5822, [])), patch.object(w.time, "time", return_value=1000), patch.object(w, "read_state", return_value={}), patch.object(w, "apply_rules") as apply, patch.object(w, "command", side_effect=RuntimeError("timer failed")), patch.object(w, "write_state") as write:
            with self.assertRaises(RuntimeError):
                w.open_window("a"*32, 1300)
            apply.assert_called_once_with(5822, [], 0)
            write.assert_not_called()

    def test_open_orders_timer_before_opening(self):
        events = []
        with patch.object(w, "configuration", return_value=(5822, [])), patch.object(w.time, "time", return_value=1000), patch.object(w, "read_state", return_value={}), patch.object(w, "apply_rules", side_effect=lambda *a: events.append(("rules", a[-1]))), patch.object(w, "command", side_effect=lambda *a, **k: events.append(("timer", 0))), patch.object(w, "write_state", side_effect=lambda s: events.append(("state", s["until"]))):
            w.open_window("a"*32, 1300)
        self.assertEqual(events, [("rules", 0), ("timer", 0), ("state", 1300), ("rules", 1300)])

    def test_retry_does_not_reschedule_or_extend(self):
        with patch.object(w, "configuration", return_value=(5822, [])), patch.object(w.time, "time", return_value=1000), patch.object(w, "read_state", return_value={"id": "a"*32, "until": 1300}), patch.object(w, "reconcile") as reconcile, patch.object(w, "command") as command:
            w.open_window("a"*32, 1300)
            reconcile.assert_called_once()
            command.assert_not_called()
            with self.assertRaises(ValueError):
                w.open_window("a"*32, 1400)

    def test_old_timer_does_not_close_new_window(self):
        with patch.object(w, "configuration", return_value=(5822, [])), patch.object(w, "read_state", return_value={"id": "b"*32, "until": 1300}), patch.object(w, "write_state") as write, patch.object(w, "apply_rules") as apply:
            w.close_window("a"*32)
            write.assert_not_called()
            apply.assert_not_called()

    def test_close_survives_policy_opt_out(self):
        with patch.object(w, "configuration", return_value=(5822, [])) as cfg, patch.object(w, "read_state", return_value={"id": "a"*32, "port": 5822}), patch.object(w, "write_state") as write, patch.object(w, "apply_rules") as apply:
            w.close_window("a"*32)
            cfg.assert_not_called()
            write.assert_called_once_with({"id": "a"*32, "until": 0, "port": 5822})
            apply.assert_called_once_with(5822, [], 0)

    def test_both_families_guarded_before_rebuild_and_no_forward_mutation(self):
        calls = []
        def command(*args, **kwargs):
            calls.append(args)
            return "-C" not in args
        with patch.object(w, "command", side_effect=command), patch.object(w.time, "time", return_value=1000):
            w.apply_rules(5822, [ipaddress.ip_network("100.68.10.7/32")], 1300)
        guards = [i for i,a in enumerate(calls) if "-I" in a and w.COMMENT in a]
        rebuild = [i for i,a in enumerate(calls) if "-F" in a]
        self.assertEqual(len(guards), 2)
        self.assertLess(max(guards), min(rebuild))
        self.assertFalse(any("FORWARD" in a or "NAT" in a for a in calls))
        accepts = [a for a in calls if "RETURN" in a]
        self.assertEqual(len(accepts), 1)
        self.assertEqual(accepts[0][0], "iptables")

    def test_reboot_missing_state_is_closed(self):
        with patch.object(w, "configuration", return_value=(5822, [])), patch.object(w, "read_state", return_value={}), patch.object(w, "apply_rules") as apply:
            w.reconcile()
            apply.assert_called_once_with(5822, [], 0)

    def test_unrestricted_and_empty_allowlists_refused(self):
        def settings(path):
            return {"SSH_GOOGLE_REOPEN_ENABLED": "yes"} if path == w.POLICY else {"SSH_PORT": "5822", "SSH_ALLOWED_SOURCES": source}
        for source in ("", "0.0.0.0/0", "::/0"):
            with patch.object(w, "settings", side_effect=settings):
                with self.assertRaises(ValueError):
                    w.configuration()

if __name__ == "__main__":
    unittest.main()

