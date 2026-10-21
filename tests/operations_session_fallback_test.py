import json
import os
from pathlib import Path
import stat
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
from operations_reboot import session_activity, session_terminals
from operations_collectors import validate_feed_url


class SessionFallbackTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.group = '/user.slice/session-42.scope'
        for name, data in {
            'proc/uptime': '2000 0',
            'sys/fs/cgroup' + self.group + '/cgroup.procs': '123\n',
            'proc/123/cgroup': '0::' + self.group,
            'proc/123/stat': '123 (bash with spaces) S 1 123 123 34816 0',
        }.items():
            p = self.root / name
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(data)
        fd = self.root / 'proc/123/fd'
        fd.mkdir()
        (fd / '0').symlink_to('/dev/pts/0')
        self.props = 'TTY=\nTimestampMonotonic=100000000\nIdleHint=no\nScope=session-42.scope'

    def runner(self, argv, timeout):
        if argv[:2] == ['loginctl', 'list-sessions']:
            return dict(exit_code=0, output='42 1000 user')
        if argv[:2] == ['loginctl', 'show-session']:
            return dict(exit_code=0, output=self.props)
        if argv[:2] == ['systemctl', 'show']:
            return dict(exit_code=0, output=self.group)
        raise AssertionError(argv)

    def test_missing_logind_tty_uses_verified_scope_terminal(self):
        real_stat = Path.stat
        for atime, expected in ((0, 'idle'), (999, 'recently_active')):
            def metadata(path, *a, **kw):
                if path == self.root / 'dev/pts/0':
                    return SimpleNamespace(st_mode=stat.S_IFCHR, st_rdev=os.makedev(136, 0), st_atime=atime)
                return real_stat(path, *a, **kw)
            with patch.object(Path, 'stat', metadata), patch('operations_reboot.time.time', return_value=1000):
                sample = session_activity(self.runner, {}, self.root)
            self.assertEqual(sample['sessions'][0]['status'], expected)
            self.assertEqual(sample['sessions'][0]['terminals'], ['pts/0'])

    def test_wrong_device_and_unresolved_live_terminal_block(self):
        real_stat = Path.stat
        def metadata(path, *a, **kw):
            if path == self.root / 'dev/pts/0':
                return SimpleNamespace(st_mode=stat.S_IFCHR, st_rdev=os.makedev(136, 1), st_atime=0)
            return real_stat(path, *a, **kw)
        with patch.object(Path, 'stat', metadata):
            sample = session_activity(self.runner, {}, self.root)
        self.assertIn('interactive_session_activity_unverified', sample['blockers'])
        self.assertEqual(sample['sessions'][0]['reason'], 'Controlling terminal unresolved')

    def test_wrong_scope_and_changed_membership_fail_closed(self):
        with self.assertRaises(ValueError):
            session_terminals(self.runner, '42', {'Scope': 'session-99.scope'}, self.root)
        (self.root / 'proc/123/cgroup').write_text('0::/other.scope')
        with self.assertRaises(ValueError):
            session_terminals(self.runner, '42', {'Scope': 'session-42.scope'}, self.root)

    def test_unresolvable_or_disappeared_process_blocks(self):
        (self.root / 'proc/123/stat').write_text('123 (bash) S 1 123 123 0 0')
        with self.assertRaises(ValueError):
            session_terminals(self.runner, '42', {'Scope': 'session-42.scope'}, self.root)
        (self.root / 'proc/123/stat').unlink()
        with self.assertRaises(ValueError):
            session_terminals(self.runner, '42', {'Scope': 'session-42.scope'}, self.root)

    def test_serial_console_and_new_login(self):
        tty = self.root / 'dev/ttyS0'
        tty.parent.mkdir()
        tty.touch()
        os.utime(tty, (0, 0))
        self.props = self.props.replace('TTY=', 'TTY=ttyS0')
        with patch('operations_reboot.time.time', return_value=1000):
            self.assertEqual(session_activity(self.runner, {}, self.root)['sessions'][0]['status'], 'idle')
            self.props = self.props.replace('100000000', '1999000000')
            self.assertEqual(session_activity(self.runner, {}, self.root)['sessions'][0]['status'], 'recently_active')

    def test_loopback_http_is_explicit_and_route_bound(self):
        cfg = dict(demo_url='http://127.0.0.1/script/operationsActivity.php', allow_loopback_http=True)
        run = lambda a, t: dict(exit_code=0, output=json.dumps([dict(dev='lo')]))
        validate_feed_url(cfg, run)
        for change in (dict(allow_loopback_http=False), dict(demo_url='http://example.org/feed'),
                       dict(demo_url='http://100.68.126.0/feed')):
            with self.assertRaises(ValueError):
                validate_feed_url(dict(cfg, **change), run)
        with self.assertRaises(ValueError):
            validate_feed_url(cfg, lambda a, t: dict(exit_code=0, output='[{"dev":"wt0"}]'))


if __name__ == '__main__':
    unittest.main()
