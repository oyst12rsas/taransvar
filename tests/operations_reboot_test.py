import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
from operations_reboot import inspect, maintain, permission, functional_receipt
from operations_agent import quiet


class RebootTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.kernel = os.uname().release
        for name, contents in {'var/run/reboot-required': '', 'boot/vmlinuz-' + self.kernel: '',
                'boot/initrd.img-' + self.kernel: '', 'boot/grub/grub.cfg': ' linux /boot/vmlinuz-' + self.kernel,
                'etc/default/grub': 'GRUB_DEFAULT=0', 'probe': '#!/bin/sh\nexit 0',
                'proc/sys/net/ipv4/ip_forward': '1'}.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(contents)
        (self.root / 'probe').chmod(0o700)
        (self.root / 'lib/modules' / self.kernel / 'build').mkdir(parents=True)
        self.policy = dict(mode='demo', execute=True, allow_reboot=True, quiet_seconds=300,
                           quiet_probe='/quiet', reboot_verification_probe=str(self.root / 'probe'))
        self.calls = []
        self.saved = []
        self.state = {}
        self.fail = None
        self.active = False
        self.events = []

    def runner(self, argv, timeout):
        self.calls.append(argv)
        if argv == self.fail:
            return dict(exit_code=1, output='failure')
        output = ''
        if argv[0] == 'dkms': output = 'tarakernel/1.0, ' + self.kernel + ', x86_64: installed'
        if argv[0] == 'modinfo': output = self.kernel + ' SMP'
        if argv[0] == 'lsmod': output = 'tarakernel 100 0'
        if argv[0] == '/quiet': output = json.dumps(dict(checked_at=1000, complete=True,
            active_demo=self.active, meaningful_traffic=False, quiet_for_seconds=400))
        return dict(exit_code=0, output=output)

    def tick(self, boot='old'):
        with patch('operations_reboot.time.time', return_value=1000):
            return maintain(self.state, self.policy, inspect(self.runner, self.root), boot, 1000,
                self.runner, Path, quiet, lambda: self.saved.append(json.loads(json.dumps(self.state))),
                self.events.append, self.root)

    def test_required_reboot_persists_checkpoint_and_budget_before_dispatch(self):
        self.assertTrue(self.tick())
        self.assertEqual(self.calls[-1], ['systemctl', 'reboot'])
        self.assertTrue(self.saved[0]['reboot_pending'])
        self.assertEqual(self.saved[0]['reboots'], [1000])
        self.assertEqual(self.saved[0]['reboot_checkpoint']['target_kernel'], self.kernel)

    def test_no_marker_never_reboots(self):
        (self.root / 'var/run/reboot-required').unlink()
        self.assertFalse(self.tick())
        self.assertEqual(self.calls, [])

    def test_read_only_modes_and_daily_budget_block(self):
        for changes in (dict(mode='inspect'), dict(mode='unknown')):
            self.policy.update(changes)
            self.assertFalse(self.tick())
            self.assertNotIn(['systemctl', 'reboot'], self.calls)
            self.assertEqual(self.state['reboot']['status'], 'blocked')
        self.policy.update(mode='demo', execute=True, allow_reboot=True)
        self.state['reboots'] = [999]
        self.assertFalse(self.tick())
        self.assertIn('daily_reboot_limit_reached', self.state['reboot']['blockers'])

    def test_demo_and_production_mode_override_legacy_false_flags(self):
        for mode in ('demo', 'production'):
            self.assertIsNone(permission(dict(mode=mode,execute=False,allow_reboot=False), {}, 1000))
        self.policy.update(mode='production',execute=False,allow_reboot=False)
        self.assertTrue(self.tick())
        self.assertEqual(self.calls[-1], ['systemctl', 'reboot'])

    def test_demo_dispatch_ignores_false_true_and_absent_legacy_flags(self):
        for legacy in ({}, dict(execute=False, allow_reboot=False),
                       dict(execute=True, allow_reboot=True)):
            with self.subTest(legacy=legacy):
                self.policy.pop('execute', None)
                self.policy.pop('allow_reboot', None)
                self.policy.update(legacy)
                self.state = {'model_call_times': [999] * 32}
                self.calls.clear()
                self.assertTrue(self.tick())
                self.assertEqual(self.calls.count(['systemctl', 'reboot']), 1)
                self.assertEqual(self.state['status'], 'reboot_requested')

    def test_demo_false_legacy_flag_does_not_bypass_reboot_guards(self):
        self.policy.update(execute=False, allow_reboot=False)
        cases = (
            ('budget', 'daily_reboot_limit_reached'),
            ('interrupted', 'interrupted_command_requires_reconciliation'),
            ('readiness', 'target_initramfs_missing'),
            ('activity', 'activity_or_quiet_time_unverified'),
        )
        initramfs = self.root / ('boot/initrd.img-' + self.kernel)
        for scenario, blocker in cases:
            with self.subTest(scenario=scenario):
                self.state = {}
                self.calls.clear()
                self.active = scenario == 'activity'
                initramfs.write_text('')
                if scenario == 'budget':
                    self.state['reboots'] = [999]
                elif scenario == 'interrupted':
                    self.state['command_pending'] = {'argv': ['/bin/true']}
                elif scenario == 'readiness':
                    initramfs.unlink()
                self.assertFalse(self.tick())
                self.assertIn(blocker, self.state['reboot']['blockers'])
                self.assertNotIn(['systemctl', 'reboot'], self.calls)

    def test_missing_module_headers_initramfs_and_custom_boot_block(self):
        for name in ('boot/initrd.img-' + self.kernel, 'etc/default/grub'):
            (self.root / name).unlink()
        self.fail = ['modinfo', '-k', self.kernel, '-F', 'vermagic', 'tarakernel']
        self.assertFalse(self.tick())
        self.assertIn('target_tarakernel_missing_or_mismatched', self.state['reboot']['blockers'])
        self.assertIn('target_initramfs_missing', self.state['reboot']['blockers'])
        self.assertNotIn(['systemctl', 'reboot'], self.calls)

    def test_active_demo_and_missing_probe_defer(self):
        self.active = True
        self.assertFalse(self.tick())
        self.assertEqual(self.state['reboot']['status'], 'waiting_for_quiet')
        (self.root / 'probe').chmod(0o600)
        self.active = False
        self.assertFalse(self.tick())
        self.assertIn('functional_verification_probe_not_executable', self.state['reboot']['blockers'])

    def test_failed_reboot_consumes_budget_and_clears_checkpoint(self):
        self.fail = ['systemctl', 'reboot']
        self.assertTrue(self.tick())
        self.assertEqual(self.state['status'], 'reboot_failed')
        self.assertNotIn('reboot_checkpoint', self.state)
        self.assertEqual(self.state['reboots'], [1000])
        self.assertFalse(self.tick())

    def test_activity_starting_before_dispatch_prevents_reboot(self):
        original = self.runner
        reads = []
        def changing(argv, timeout):
            if argv[0] == '/quiet':
                reads.append(True)
                if len(reads) == 2:
                    self.active = True
            return original(argv, timeout)
        self.runner = changing
        self.assertFalse(self.tick())
        self.assertIn('activity_changed_before_dispatch', self.state['reboot']['blockers'])
        self.assertNotIn(['systemctl', 'reboot'], self.calls)

    def test_missing_dkms_binary_is_visible_and_blocks(self):
        original = self.runner
        def missing(argv, timeout):
            if argv[0] == 'dkms':
                raise FileNotFoundError('dkms')
            return original(argv, timeout)
        self.runner = missing
        self.assertFalse(self.tick())
        self.assertIn('target_dkms_installation_unverified', self.state['reboot']['blockers'])
        self.assertNotIn(['systemctl', 'reboot'], self.calls)

    def test_session_inspection_failure_blocks_reboot(self):
        self.fail = ['loginctl', 'list-sessions', '--no-legend', '--no-pager']
        self.assertFalse(self.tick())
        self.assertNotIn(['systemctl', 'reboot'], self.calls)
        self.assertIn('interactive_session_activity_unverified', self.state['reboot']['blockers'])

    def logged_in(self, idle=901, started=100):
        tty = self.root / 'dev/pts/0'
        tty.parent.mkdir(parents=True, exist_ok=True)
        tty.touch()
        os.utime(tty, (1000 - idle, 1000 - idle))
        uptime = self.root / 'proc/uptime'
        uptime.write_text('2000 0')
        original = self.runner
        def sessions(argv, timeout):
            result = original(argv, timeout)
            if argv[:2] == ['loginctl', 'list-sessions']:
                result['output'] = '1 1000 ubuntu pts/0\n'
            elif argv[:2] == ['loginctl', 'show-session']:
                result['output'] = 'TTY=pts/0\nTimestampMonotonic=' + str(started * 1000000) + '\n'
            return result
        self.runner = sessions
        return tty

    def test_idle_ssh_session_does_not_block_even_with_exhausted_model_budget(self):
        self.logged_in()
        self.state['model_call_times'] = [999] * 32
        self.assertTrue(self.tick())
        self.assertEqual(self.calls[-1], ['systemctl', 'reboot'])
        self.assertEqual(self.state['reboot']['session_activity']['sessions'][0]['status'], 'idle')

    def test_recent_input_or_new_login_blocks(self):
        for idle, started in ((899, 100), (901, 1900)):
            self.runner = RebootTests.runner.__get__(self)
            self.logged_in(idle, started)
            self.assertFalse(self.tick())
            self.assertIn('interactive_session_recently_active', self.state['reboot']['blockers'])
        self.assertNotIn(['systemctl', 'reboot'], self.calls)

    def test_input_at_dispatch_blocks(self):
        tty = self.logged_in()
        original = self.runner
        reads = []
        def changing(argv, timeout):
            if argv[:2] == ['loginctl', 'list-sessions']:
                reads.append(True)
                if len(reads) == 2:
                    os.utime(tty, (1000, 1000))
            return original(argv, timeout)
        self.runner = changing
        self.assertFalse(self.tick())
        self.assertNotIn(['systemctl', 'reboot'], self.calls)

    def test_missing_tty_activity_and_invalid_threshold_block(self):
        tty = self.logged_in()
        tty.unlink()
        self.assertFalse(self.tick())
        self.assertIn('interactive_session_activity_unverified', self.state['reboot']['blockers'])
        self.policy['reboot_session_idle_seconds'] = True
        self.assertFalse(self.tick())
        self.assertIn('invalid_reboot_session_idle_seconds', self.state['reboot']['blockers'])

    def test_idle_boundary_and_custom_threshold(self):
        self.logged_in(idle=900)
        self.assertTrue(self.tick())
        self.state = {}
        self.policy['reboot_session_idle_seconds'] = 1200
        self.assertFalse(self.tick())

    def test_non_tty_idle_hint_requires_timestamp(self):
        self.logged_in()
        original = self.runner
        def graphical(argv, timeout):
            result = original(argv, timeout)
            if argv[:2] == ['loginctl', 'show-session']:
                result['output'] = ('TTY=\nTimestampMonotonic=100000000\n'
                                    'IdleHint=yes\nIdleSinceHintMonotonic=1000000000\n')
            return result
        self.runner = graphical
        self.assertTrue(self.tick())

    def test_same_boot_does_not_dispatch_twice(self):
        self.tick()
        self.assertTrue(self.tick())
        self.assertEqual(self.calls.count(['systemctl', 'reboot']), 1)
        self.assertEqual(self.state['status'], 'reboot_waiting_for_boot')

    def test_after_boot_requires_functional_probe_services_module_and_forwarding(self):
        self.tick()
        (self.root / 'var/run/reboot-required').unlink()
        self.fail = ['systemctl', 'is-active', '--quiet', 'taralink.service']
        self.tick('new')
        self.assertEqual(self.state['status'], 'reboot_verification_pending')
        self.assertIn('service_not_active:taralink.service', self.state['reboot']['blockers'])
        self.fail = [str(self.root / 'probe')]
        self.tick('new')
        self.assertIn('functional_verification_pending_or_failed', self.state['reboot']['blockers'])
        self.fail = None
        (self.root / 'proc/sys/net/ipv4/ip_forward').write_text('0')
        self.tick('new')
        self.assertEqual(self.state['status'], 'reboot_verification_pending')
        (self.root / 'proc/sys/net/ipv4/ip_forward').write_text('1')
        self.tick('new')
        self.assertEqual(self.state['status'], 'reboot_verified')
        self.assertNotIn('reboot_checkpoint', self.state)
        self.assertEqual(self.calls.count(['systemctl', 'reboot']), 1)

    def test_boot_entry_and_secure_boot_fail_closed(self):
        (self.root / 'boot/grub/grub.cfg').write_text(' linux /boot/vmlinuz-other')
        (self.root / 'sys/firmware/efi').mkdir(parents=True)
        self.assertFalse(self.tick())
        self.assertIn('target_boot_entry_unverified', self.state['reboot']['blockers'])
        self.assertIn('secure_boot_module_acceptance_unverified', self.state['reboot']['blockers'])

    def test_interrupted_command_and_invalid_limit_block(self):
        self.assertEqual(permission(self.policy, {'command_pending': {}}, 1000), None)
        self.assertEqual(permission(self.policy, {'command_pending': {'argv': []}}, 1000),
                         'interrupted_command_requires_reconciliation')
        self.assertEqual(permission(dict(self.policy, max_reboots_per_day=True), {}, 1000), 'invalid_reboot_limit')


class ReceiptTest(unittest.TestCase):
    def test_requires_this_boot_fresh_receipt_and_kernel_link_health(self):
        sample = dict(central_received_at=950, operations_boot_id='new', kernel_running=True, link_running=True)
        self.assertTrue(functional_receipt(sample, 'new', 900, 1000))
        for changes in (dict(central_received_at=899), dict(central_received_at=1001),
                        dict(central_received_at=600), dict(operations_boot_id='old'),
                        dict(kernel_running=False), dict(link_running=False), dict(central_received_at=True)):
            self.assertFalse(functional_receipt(dict(sample, **changes), 'new', 900, 1000))

