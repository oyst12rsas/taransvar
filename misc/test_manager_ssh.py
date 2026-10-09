#!/usr/bin/env python3
import unittest
from unittest import mock
import manager_ssh as gate

BARRIER = '-A INPUT -i lo -j ACCEPT\n-A INPUT -p tcp -m tcp --dport 5822 -j REJECT --reject-with tcp-reset\n'
# iptables -S adds '-m tcp' even when the original command did not specify it.
CONFIG = {'SSH_PORT': '5822', 'ALLOW_SSH': '0', 'SSH_MANAGER_TIMED_OPEN': 'on'}

class TimedSshTest(unittest.TestCase):
    def test_closed_barrier_position(self):
        result = gate.policy(BARRIER, '100.68.1.2', 5822, 1000)
        self.assertEqual(result['state'], 'closed')
        self.assertEqual(result['position'], 2)
        unrelated_interface = '-A INPUT -i wlan0 -p tcp -m tcp --dport 8080 -j ACCEPT\n'
        self.assertEqual(gate.policy(unrelated_interface+BARRIER, '100.68.1.2', 5822, 1000)['state'], 'closed')

    def test_scoped_open_and_exact_expiry(self):
        rules = '-A INPUT -s 100.68.1.2/32 -p tcp -m tcp --dport 5822 -m time --datestop 1970-01-01T00:21:39 -m comment --comment "tarasec-manager-ssh:100.68.1.2:1300" -j ACCEPT\n' + BARRIER
        self.assertEqual(gate.policy(rules, '100.68.1.2', 5822, 1299)['state'], 'open')
        self.assertEqual(gate.policy(rules, '100.68.1.2', 5822, 1300)['state'], 'closed')
        self.assertEqual(gate.policy(rules, '100.68.1.3', 5822, 1000)['state'], 'closed')

    def test_unfamiliar_policy_cannot_be_bypassed(self):
        for rule in ('-A INPUT -j NETBIRD-ACL-INPUT', '-A INPUT -s 100.68.1.2/32 -j DROP', '-A INPUT -i wt0 -j DROP', '-A INPUT -p tcp --dport 5800:5900 -j ACCEPT', '-A INPUT ! -s 100.68.1.3 -j REJECT'):
            self.assertEqual(gate.policy(rule+'\n'+BARRIER, '100.68.1.2', 5822, 1000)['state'], 'unknown')

    def test_existing_source_recovery_access(self):
        rules = '-A INPUT -s 100.68.1.2/32 -p tcp -m tcp --dport 5822 -j ACCEPT\n'+BARRIER
        self.assertEqual(gate.policy(rules, '100.68.1.2', 5822, 1000)['state'], 'open')

    def test_only_supported_durations(self):
        for duration in (0, 1, 6, 30, 900, None):
            with self.assertRaises(ValueError): gate.execute('open', '100.68.1.2', duration)
        with self.assertRaises(ValueError): gate.execute('open', '::1', 5)

    def test_open_kernel_expiry_and_source(self):
        for minutes in (5,10,15):
            calls = []
            expiry = 1000 + minutes*60
            def run(*args):
                calls.append(args)
                if args[0].endswith('/ss'): return 'LISTEN users:(("sshd",pid=1,fd=3))'
                if '-I' in args: return ''
                if any('-I' in c for c in calls):
                    return f'-A INPUT -s 100.68.1.2/32 -p tcp --dport 5822 -m time --datestop 1970-01-01T00:30:00 -m comment --comment {gate.COMMENT}100.68.1.2:{expiry} -j ACCEPT\n'+BARRIER
                return BARRIER
            with mock.patch.object(gate, 'configuration', return_value=CONFIG), mock.patch.object(gate.time,'time',return_value=1000), mock.patch.object(gate,'run',side_effect=run):
                result = gate.execute('open','100.68.1.2',minutes)
            self.assertEqual(result['expiresAt'], expiry)
            self.assertFalse(result['canOpen'], 'Already open access cannot be reopened')
            inserted = next(c for c in calls if '-I' in c)
            self.assertIn('--datestop', inserted)
            self.assertEqual(inserted[inserted.index('-s')+1], '100.68.1.2')

    def test_no_mutation_without_owner_optin_listener_or_allowed_source(self):
        for cfg, listener in (({**CONFIG,'SSH_MANAGER_TIMED_OPEN':'off'}, 'sshd'), (CONFIG,''), ({**CONFIG,'SSH_ALLOWED_SOURCES':'100.68.1.3/32'},'sshd'), ({**CONFIG,'ALLOW_SSH':'1'},'sshd')):
            calls=[]
            def run(*args):
                calls.append(args)
                return listener if args[0].endswith('/ss') else BARRIER
            with mock.patch.object(gate,'configuration',return_value=cfg), mock.patch.object(gate,'run',side_effect=run):
                with self.assertRaises(ValueError): gate.execute('open','100.68.1.2',5)
            self.assertFalse(any('-I' in c for c in calls))

if __name__ == '__main__': unittest.main()
