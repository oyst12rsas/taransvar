#!/usr/bin/env python3
import ipaddress
import tempfile
import time
import unittest
from pathlib import Path
from unittest import mock

import ssh_google_window as window
import agent_approval_worker as worker


class GoogleWindowTests(unittest.TestCase):
    def setUp(self):
        self.auth = mock.patch.object(window.local_manager_policy, 'require_manager')
        self.auth.start()
        self.addCleanup(self.auth.stop)

    def test_configuration_opt_in_and_explicit_sources(self):
        with tempfile.TemporaryDirectory() as folder:
            cfg, policy = Path(folder)/'fw', Path(folder)/'policy'
            cfg.write_text('SSH_PORT=4810\nSSH_ALLOWED_SOURCES=100.68.10.7/32,2001:db8::7/128\n')
            policy.write_text('SSH_GOOGLE_REOPEN_ENABLED=no\n')
            with mock.patch.object(window, 'CONF', str(cfg)), mock.patch.object(window, 'POLICY', str(policy)):
                with self.assertRaises(RuntimeError): window.configuration()
                policy.write_text('SSH_GOOGLE_REOPEN_ENABLED=yes\n')
                port, sources = window.configuration()
                self.assertEqual(port, 4810)
                self.assertEqual([s.version for s in sources], [4, 6])
                for source in ('', '0.0.0.0/0', '::/0', 'not-an-ip'):
                    cfg.write_text('SSH_PORT=4810\nSSH_ALLOWED_SOURCES='+source+'\n')
                    with self.assertRaises(ValueError): window.configuration()

    def test_closed_gate_has_no_source_or_recovery_exemptions(self):
        calls = []
        def command(*args, check=True):
            calls.append(args)
            return '-C' not in args
        with mock.patch.object(window, 'command', side_effect=command):
            window.apply_rules(4810, [ipaddress.ip_network('100.68.10.7/32')], 0)
        self.assertFalse(any('RETURN' in args for args in calls))
        for tool in ('iptables', 'ip6tables'):
            self.assertTrue(any(args[:6] == (tool, '-w', '5', '-I', 'INPUT', '1') and window.CHAIN in args for args in calls))
            self.assertTrue(any(window.CHAIN in args and 'REJECT' in args for args in calls))
        self.assertFalse(any('FORWARD' in args or 'nat' in args or '-P' in args for args in calls))

    def test_timer_before_allowance_and_state_is_private(self):
        events = []
        now = int(time.time())
        with tempfile.TemporaryDirectory() as folder, \
             mock.patch.object(window, 'STATE', str(Path(folder)/'state')), \
             mock.patch.object(window, 'configuration', return_value=(4810, [])), \
             mock.patch.object(window, 'command', side_effect=lambda *args: events.append('timer')), \
             mock.patch.object(window, 'apply_rules', side_effect=lambda p,s,u: events.append('open' if u else 'closed')):
            window.open_window('a'*32, now+300)
            self.assertEqual(events, ['closed', 'timer', 'open'])
            self.assertEqual(Path(window.STATE).stat().st_mode & 0o777, 0o600)
            with mock.patch.object(window, 'reconcile') as reconcile:
                window.open_window('a'*32, now+300)
                reconcile.assert_called_once()
            with self.assertRaises(ValueError): window.open_window('a'*32, now+301)
            before = window.read_state()
            window.close_window('b'*32)
            self.assertEqual(window.read_state(), before)
            window.close_window('a'*32)
            self.assertEqual(window.read_state()['until'], 0)

    def test_failed_timer_never_opens(self):
        with mock.patch.object(window, 'configuration', return_value=(4810, [])), \
             mock.patch.object(window, 'read_state', return_value={}), \
             mock.patch.object(window, 'apply_rules') as rules, \
             mock.patch.object(window, 'command', side_effect=RuntimeError('timer unavailable')):
            with self.assertRaises(RuntimeError): window.open_window('a'*32, int(time.time())+300)
            rules.assert_called_once_with(4810, [], 0)

    def test_invalid_deadlines_and_disabled_worker_never_execute(self):
        with mock.patch.object(window, 'configuration', return_value=(4810, [])):
            for value in (True, '123', int(time.time())-1, int(time.time())+901):
                with self.assertRaises(ValueError): window.open_window('a'*32, value)
        with mock.patch.object(worker, 'enabled', return_value=False), mock.patch.object(worker, 'run') as run:
            with self.assertRaises(RuntimeError): worker.execute_approved_job({'operation':'open_ssh_temporarily'})
            run.assert_not_called()

    def test_worker_uses_approved_deadline_and_rejects_unknown_jobs(self):
        job = {'operation':'open_ssh_temporarily', 'id':'a'*32, 'open_until':int(time.time())+300, 'approved_operator_sha256':'c'*64}
        with mock.patch.object(worker, 'enabled', return_value=True), \
             mock.patch.object(worker, 'run', return_value=mock.Mock(returncode=0)) as run, \
             mock.patch.object(worker, 'audit'):
            self.assertTrue(worker.execute_approved_job(job))
            self.assertEqual(run.call_args.args[-2:], (str(job['open_until']), job.get('approved_operator_sha256')))
            for value in ('123', None, True):
                with self.assertRaises(ValueError): worker.execute_approved_job(dict(job,open_until=value))
            with self.assertRaises(RuntimeError): worker.execute_approved_job({'operation':'arbitrary-command'})



class ExplicitClosureTests(unittest.TestCase):
    def test_closure_revokes_saved_window_and_rejects_old_opening(self):
        state={'id':'a'*32,'until':2000,'port':5822}
        def save(value): state.clear();state.update(value)
        with mock.patch.object(window.local_manager_policy,'require_manager'), \
             mock.patch.object(window,'configuration',return_value=(5822,[])), \
             mock.patch.object(window,'read_state',side_effect=lambda:dict(state)), \
             mock.patch.object(window,'write_state',side_effect=save), \
             mock.patch.object(window,'apply_rules') as rules, \
             mock.patch.object(window.time,'time',return_value=1000):
            window.lock_now('b'*32,'c'*64)
            self.assertEqual(state['until'],0)
            self.assertEqual(state['closed_at'],1000)
            rules.assert_called_with(5822,[],0)
            for approved_at in (None,999,1000):
                with self.assertRaises(ValueError):window.open_window('a'*32,1200,'c'*64,approved_at)
            with mock.patch.object(window,'command'):
                window.open_window('d'*32,1200,'c'*64,1001)
            self.assertEqual(state['closed_at'],1000)


if __name__ == '__main__':
    unittest.main()
