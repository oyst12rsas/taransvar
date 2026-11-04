import contextlib
import io
import ipaddress
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
import ssh_google_window as window
import ssh_honeypot_route as route
import ssh_port_setup as setup


class PolicyTests(unittest.TestCase):
    def test_ranges_allow_management_port_inside_and_reject_invalid_values(self):
        self.assertEqual(route.ranges('1000-5000,22'), [(22, 22), (1000, 5000)])
        for invalid in ('', '5000-1000', '0', '65536', '1-99999', '1; reboot'):
            with self.assertRaises(ValueError):
                route.ranges(invalid)

    def test_private_port_not_echoed_by_configuration_and_snapshot_is_default_closed(self):
        text = 'SSH_PORT="5822"\nSSH_HONEYPOT_PORTS="22"\n'
        changed = setup.config_text(text, {'SSH_PORT': 2345, 'SSH_HONEYPOT_PORTS': '1000-5000'})
        self.assertEqual(changed, 'SSH_PORT="2345"\nSSH_HONEYPOT_PORTS="1000-5000"\n')
        with self.assertRaises(ValueError):
            setup.config_text(text + text, {'SSH_PORT': 2345})
        rules = setup.guarded_snapshot('*nat\n:PREROUTING ACCEPT [0:0]\nCOMMIT\n*filter\n:INPUT ACCEPT [0:0]\nCOMMIT\n', 5822)
        self.assertEqual(rules.count('tarasec-port-rollback-guard'), 1)
        self.assertIn('--ctstate NEW', rules)
        self.assertLess(rules.index('*filter'), rules.index('tarasec-port-rollback-guard'))
        both = setup.guarded_snapshot('*filter\n:INPUT DROP [0:0]\nCOMMIT\n', [5822, 2345])
        self.assertEqual(both.count('tarasec-port-rollback-guard'), 2)
        override = setup.socket_config(2345, 'LISTEN 0 128 100.68.1.2:5822 *:*\nLISTEN 0 128 [::1]:5822 *:*\n')
        self.assertIn('ListenStream=100.68.1.2:2345', override)
        self.assertIn('ListenStream=[::1]:2345', override)
        self.assertNotIn('0.0.0.0', override)
        sshd = setup.sshd_port_text('Port 5822\nAuthenticationMethods publickey,password\nPasswordAuthentication yes\n', 2345)
        self.assertIn('AuthenticationMethods publickey,password', sshd)
        self.assertIn('PasswordAuthentication yes', sshd)
        self.assertNotIn('5822', sshd)

    def test_redirect_is_local_only_and_mfa_exemption_precedes_overlapping_range(self):
        calls = []
        def run(*args, **kwargs):
            calls.append((args, kwargs))
            output = ''
            if args[0] == 'ss':
                output = 'LISTEN 0 128 0.0.0.0:2345 *:*\nLISTEN 0 128 0.0.0.0:3306 *:*\n'
            if '-S' in args:
                output = '-P INPUT DROP\n-A INPUT -j DROP\n'
            return subprocess.CompletedProcess(args, 1 if '-C' in args else 0, output, '')
        sources = [ipaddress.ip_network('10.100.0.150/32')]
        cfg = {'SSH_HONEYPOT_PORTS': '1000-5000', 'ALLOW_WEB': '1'}
        with mock.patch.object(route, 'config', return_value=(cfg, 2345, 22, sources)), mock.patch.object(route, 'run', side_effect=run):
            route.prepare()
        for tool in ('iptables-restore', 'ip6tables-restore'):
            rules = next(kwargs['input'] for args, kwargs in calls if args[0] == tool)
            self.assertLess(rules.index('--match-set'), rules.index('--dport 2345 -j REDIRECT'))
            self.assertLess(rules.index('--dport 2345 -j REDIRECT'), rules.index('--dport 1000:5000'))
            self.assertIn('--dport 3306 -j ACCEPT', rules)
            self.assertNotIn('--dport 2345 -j ACCEPT\n', rules)
        hooks = [args for args, _ in calls if 'PREROUTING' in args]
        self.assertTrue(hooks)
        self.assertTrue(all('--dst-type' in args and 'LOCAL' in args for args in hooks))
        self.assertFalse(any('FORWARD' in args or 'OUTPUT' in args for args, _ in calls))

    def test_kernel_lease_never_outlives_approved_deadline_and_closed_has_no_exemption(self):
        sources = [ipaddress.ip_network('10.100.0.150/32'), ipaddress.ip_network('fd00::7/128')]
        with mock.patch.object(route.time, 'time', return_value=1000), mock.patch.object(route, 'run') as run:
            route.lease(2345, sources, 1010)
            additions = [c.args for c in run.call_args_list if 'add' in c.args]
            self.assertEqual(len(additions), 2)
            self.assertTrue(all(args[-3:] == ('timeout', '9', '-exist') for args in additions))
            run.reset_mock()
            route.lease(2345, sources, 999)
            self.assertFalse(any('add' in c.args for c in run.call_args_list))

    def test_attack_guard_uses_exact_port_recent_evidence_and_fails_on_reader_error(self):
        cfg = {'SSH_ATTACK_QUIET_SECONDS': '60', 'SSH_ATTACK_ATTEMPTS': '5'}
        events = []
        def event(port, stamp=999):
            return json.dumps({'MESSAGE': json.dumps({'sensor': 'tarasec-ssh-lite', 'event': 'ssh_login_failed',
                                                     'dst_port': port, 'timestamp': stamp})})
        def run(*args, **kwargs):
            return subprocess.CompletedProcess(args, 0, '\n'.join(events) if args[0] == 'journalctl' else '', '')
        with mock.patch.object(route, 'config', return_value=(cfg, 2345, 22, [])), mock.patch.object(route.time, 'time', return_value=1000), mock.patch.object(route, 'run', side_effect=run):
            events[:] = [event(4000)] * 10 + [event(2345, 800)] * 10
            route.require_quiet(2345)
            events += [event(2345)] * 5
            with self.assertRaisesRegex(RuntimeError, 'recent credential attacks'):
                route.require_quiet(2345)
            events[:] = ['{}'] * 2001
            with self.assertRaisesRegex(RuntimeError, 'overloaded'):
                route.require_quiet(2345)
        with mock.patch.object(route, 'config', return_value=(cfg, 2345, 22, [])), mock.patch.object(route, 'run', side_effect=RuntimeError('reader failed')):
            with self.assertRaises(RuntimeError):
                route.require_quiet(2345)

    def test_mfa_never_opens_when_attack_guard_refuses(self):
        with mock.patch.object(window.local_manager_policy, 'require_manager'), \
             mock.patch.object(window, 'configuration', return_value=(2345, [])), \
             mock.patch.object(window, 'read_state', return_value={}), \
             mock.patch.object(window, 'decoy_enabled', return_value=True), \
             mock.patch.object(window, 'apply_rules') as rules, \
             mock.patch.object(route, 'require_quiet', side_effect=RuntimeError('attack')):
            with self.assertRaisesRegex(RuntimeError, 'attack'):
                window.open_window('a' * 32, int(window.time.time()) + 300, 'b' * 64)
            rules.assert_not_called()


@unittest.skipUnless(os.geteuid() == 0, 'Root-owned migration file guards')
class MigrationTests(unittest.TestCase):
    def exercise(self, backend_ready, console_recovery=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            conf = root / 'etc/tarasecfw.conf'
            conf.parent.mkdir()
            original = 'SSH_PORT="5822"\nSSH_HONEYPOT_PORT="22"\nSSH_HONEYPOT_PORTS="22"\n'
            conf.write_text(original)
            conf.chmod(0o640)
            events = []
            def command(*args, **kwargs):
                events.append(args)
                output = '*filter\n:INPUT DROP [0:0]\nCOMMIT\n' if args[0].endswith('-save') else ''
                if args[:2] == ('sshd', '-T'):
                    output = 'port 2345\n'
                if args[:2] == ('systemctl', 'show'):
                    output = 'loaded\n' if 'LoadState' in args else 'process\n'
                return subprocess.CompletedProcess(args, 1 if '-C' in args or args[:2] == ('ipset', 'save') else 0, output, '')
            replacements = {'CONF': conf, 'SSHD_RUNTIME': root / 'run/sshd', 'STATE': root / 'state', 'LIB': root / 'lib',
                            'SSHD': root / 'etc/sshd/90-tarasec.conf', 'SOCKET': root / 'socket/override.conf',
                            'HP': root / 'honeypot/override.conf', 'UNIT': root / 'systemd/decoys.service',
                            'SYSTEMD': root / 'systemd'}
            replacements['SSH_SERVICE_DROPINS'] = (root / 'systemd/ssh.service.d/decoys.conf',
                                                   root / 'systemd/sshd.service.d/decoys.conf')
            with contextlib.ExitStack() as stack:
                for name, value in replacements.items():
                    stack.enter_context(mock.patch.object(setup, name, value))
                stack.enter_context(mock.patch.object(window, 'STATE', str(root / 'run/window.json')))
                stack.enter_context(mock.patch.object(window, 'configuration', return_value=(5822, [])))
                stack.enter_context(mock.patch.object(window, 'write_state'))
                stack.enter_context(mock.patch.object(window, 'apply_rules', side_effect=lambda *args: events.append(('gate', args))))
                stack.enter_context(mock.patch.object(window, 'reconcile'))
                stack.enter_context(mock.patch.object(setup, 'socket_mode', return_value=False))
                stack.enter_context(mock.patch.object(setup, 'command', side_effect=command))
                stack.enter_context(mock.patch.object(setup, 'listener', side_effect=[False, True, backend_ready]))
                stack.enter_context(mock.patch.object(setup.shutil, 'which', return_value='/mock'))
                stack.enter_context(mock.patch.object(setup.sys.stdin, 'isatty', return_value=True))
                stack.enter_context(mock.patch.object(setup.getpass, 'getpass', return_value='2345'))
                stack.enter_context(mock.patch('builtins.input', return_value='1000-5000'))
                output = stack.enter_context(contextlib.redirect_stdout(io.StringIO()))
                if backend_ready:
                    setup.migrate(console_recovery=console_recovery)
                    self.assertIn('SSH_PORT="2345"', conf.read_text())
                    self.assertTrue((root / 'state/pending.json').exists())
                    self.assertNotIn('2345', output.getvalue())
                else:
                    with self.assertRaisesRegex(RuntimeError, 'backend is not listening'):
                        setup.migrate(console_recovery=console_recovery)
                    if console_recovery:
                        self.assertIn('SSH_PORT="2345"', conf.read_text())
                        self.assertEqual(json.loads((root/'state/pending.json').read_text())['phase'],'console_recovery_required')
                        self.assertFalse(any(args[0].endswith('-restore') for args in events))
                    else:
                        self.assertEqual(conf.read_text(), original)
                        self.assertFalse((root / 'state/pending.json').exists())
                if console_recovery:
                    self.assertFalse(any(args[:3] == ('systemctl','enable','--now') for args in events))
                    if backend_ready:
                        receipt=json.loads((root/'state/pending.json').read_text())
                        self.assertEqual(receipt['phase'],'complete_console')
                        self.assertFalse(receipt['automatic_rollback'])
                else:
                    timer = next(i for i, args in enumerate(events) if args[:3] == ('systemctl', 'enable', '--now'))
                    gate = next(i for i, args in enumerate(events) if args[0] == 'gate')
                    self.assertLess(timer, gate)
                self.assertEqual(conf.stat().st_mode & 0o777, 0o640)

    def test_missing_runtime_is_created_and_unsafe_runtime_is_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            runtime=Path(directory)/'sshd'
            with mock.patch.object(setup,'SSHD_RUNTIME',runtime):
                setup.ensure_sshd_runtime()
                self.assertTrue(runtime.is_dir())
                self.assertEqual(runtime.stat().st_mode & 0o777,0o755)
                runtime.chmod(0o777)
                with self.assertRaises(ValueError):
                    setup.ensure_sshd_runtime()

    def test_stage_overlapping_range_after_arming_rollback(self):
        self.exercise(True)

    def test_failure_restores_configuration_and_firewall(self):
        self.exercise(False)


    def test_console_recovery_does_not_arm_or_automatically_restore(self):
        self.exercise(True, console_recovery=True)
        self.exercise(False, console_recovery=True)

    def test_recover_current_keeps_configuration_and_closes_mfa_before_guard_cleanup(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            state=root/'state';state.mkdir(mode=0o700)
            pending=state/'pending.json'
            pending.write_text(json.dumps({'backup':'must-not-be-read','new_port':4563,'phase':'rollback'}))
            pending.chmod(0o600)
            config=root/'config';config.write_text('SSH_PORT="5822"\nOWNER_SETTING="retain"\n')
            before=config.read_bytes()
            calls=[]
            def command(*args,**kwargs):
                calls.append(args)
                self.assertFalse(args[0].endswith('-restore'),'Current recovery must never restore snapshots')
                return subprocess.CompletedProcess(args,0,'port 5822\n' if args[:2]==('sshd','-T') else '','')
            with mock.patch.object(setup,'STATE',state),mock.patch.object(setup,'SSHD_RUNTIME',root/'run/sshd'), \
                 mock.patch.object(setup,'command',side_effect=command),mock.patch.object(setup,'listener',return_value=True), \
                 mock.patch.object(window,'configuration',return_value=(5822,[])), \
                 mock.patch.object(window,'write_state') as closed, \
                 mock.patch.object(window,'reconcile',side_effect=lambda:calls.append(('reconcile',))), \
                 mock.patch.object(setup,'clear_rollback_guard',side_effect=lambda port:calls.append(('clear',port))) as clear, \
                 contextlib.redirect_stdout(io.StringIO()):
                setup.recover_current()
            self.assertEqual(config.read_bytes(),before)
            self.assertEqual(closed.call_args.args[0]['until'],0)
            self.assertLess(calls.index(('reconcile',)),calls.index(('clear',5822)))
            clear.assert_called_once_with(5822)
            receipt=json.loads(pending.read_text())
            self.assertEqual(receipt['phase'],'complete_console')
            self.assertFalse(receipt['automatic_rollback'])
            self.assertIn(('systemctl','disable','--now',setup.ROLLBACK+'.timer'),calls)

    def test_migration_phases_and_legacy_guards_block_opening(self):
        with tempfile.TemporaryDirectory() as directory:
            pending=Path(directory)/'pending.json'
            with mock.patch.object(window,'MIGRATION_PENDING',str(pending)), \
                 mock.patch.object(window,'command',return_value=False) as command:
                for phase in ['staging','rollback','console_recovery_required']:
                    pending.write_text(json.dumps({'phase':phase}));pending.chmod(0o600)
                    self.assertTrue(window.migration_blocked(5822))
                for phase in ['awaiting_confirmation','complete_console']:
                    pending.write_text(json.dumps({'phase':phase}))
                    self.assertFalse(window.migration_blocked(5822))
                pending.write_text('{}')
                command.return_value=True
                self.assertTrue(window.migration_blocked(5822))


if __name__ == '__main__':
    unittest.main()
