import unittest
from unit_activity import routed_flows, ssh_sessions, observe


class ActivityTests(unittest.TestCase):
    def test_heartbeat_and_local_services_excluded(self):
        report = 'tcp 6 100 src=10.0.0.1 dst=100.68.126.0 sport=3 dport=80 packets=500 src=100.68.126.0 dst=10.0.0.1 packets=400'
        inbound = report.replace('src=10.0.0.1 dst=100.68.126.0', 'src=10.0.0.9 dst=10.0.0.1')
        self.assertEqual(routed_flows(report+'\n'+inbound, {'10.0.0.1'}), {})

    def test_forwarded_nat_and_stable_key(self):
        flow = 'tcp 6 100 src=10.0.0.9 dst=8.8.8.8 sport=20 dport=443 packets=10 src=8.8.8.8 dst=10.0.0.1 sport=443 dport=20 packets=12'
        first = routed_flows(flow, {'10.0.0.1'})
        later = routed_flows(flow.replace('6 100', '6 80'), {'10.0.0.1'})
        self.assertEqual(first, later)
        self.assertEqual(list(first.values()), [('10.0.0.9', 22)])

    def test_missing_accounting_unknown(self):
        with self.assertRaises(ValueError):
            routed_flows('tcp src=10.0.0.9 dst=8.8.8.8 src=8.8.8.8 dst=10.0.0.1', {'10.0.0.1'})

    def test_idle_baseline_counter_change_expiry_and_gap(self):
        flow = {'tuple': ('10.0.0.9', 22)}
        count, state = observe({}, flow, set(), 100, 'boot')
        self.assertIsNone(count)
        count, state = observe(state, flow, set(), 160, 'boot')
        self.assertEqual(count, 0)  # Open but idle connection.
        count, state = observe(state, {'tuple': ('10.0.0.9', 23)}, set(), 220, 'boot')
        self.assertEqual(count, 1)
        for now in (280, 340, 400, 460, 520):
            count, state = observe(state, {'tuple': ('10.0.0.9', 23)}, set(), now, 'boot')
        self.assertEqual(count, 0)
        self.assertIsNone(observe(state, flow, set(), 700, 'boot')[0])
        self.assertIsNone(observe(state, flow, set(), 580, 'reboot')[0])

    def test_authenticated_ssh_only(self):
        self.assertEqual(ssh_sessions('1 sshd: alice@pts/0\n2 sshd: alice [priv]\n3 sshd: unknown [net]\n4 sshd-session: bob@notty'), [
            {'pid': 1, 'user': 'alice', 'terminal': 'pts/0'},
            {'pid': 4, 'user': 'bob', 'terminal': 'notty'}])


if __name__ == '__main__':
    unittest.main()
