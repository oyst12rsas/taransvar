import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
import ssh_controls_status as s

SSHD_HARDENED = 'port 4563\npubkeyauthentication yes\npasswordauthentication yes\nkbdinteractiveauthentication no\nauthenticationmethods publickey,password\n'
SSHD_DEFAULT = 'port 22\npubkeyauthentication yes\npasswordauthentication yes\nkbdinteractiveauthentication no\nauthenticationmethods any\n'


class ControlsTest(unittest.TestCase):
    def node(self, sshd, fw, manager='', key=True, enrollment=True, manager_ssh=None, iptables_ok=True):
        root = Path(tempfile.mkdtemp())
        (root / 'etc/tarasec').mkdir(parents=True)
        (root / 'etc/tarasecfw.conf').write_text(fw)
        (root / 'etc/tarasec-server-manager.conf').write_text(manager)
        if enrollment: (root / 'etc/tarasec/agent-enrollment.json').write_text('{}')
        if manager_ssh is not None: (root / 'etc/tarasec/manager-ssh.enabled').write_text('')
        (root / 'home/admin/.ssh').mkdir(parents=True)
        (root / 'home/admin/.ssh/authorized_keys').write_text('ssh-ed25519 AAAA admin\n' if key else '# none\n')
        port = 'sport = :4563' if 'port 4563' in sshd else 'sport = :22'
        def run(argv):
            if argv[0] == '/usr/sbin/sshd': return 0, sshd
            if argv[0] == 'ss': return 0, 'LISTEN 0 128 0.0.0.0:4563 users:(("sshd",pid=1))' if port.endswith('4563') else ''
            if argv[0] in ('iptables', 'ip6tables'): return (0 if iptables_ok else 1), ''
            if argv[0] == 'systemctl':
                if 'tarasec-ssh-honeypot.service' in argv: return 0, 'LoadState=loaded\nActiveState=active\nResult=success'
                if manager_ssh is None: return 0, 'LoadState=not-found\nActiveState=inactive\nResult=success'
                return 0, 'LoadState=loaded\nActiveState=inactive\nResult=' + ('success' if manager_ssh else 'exit-code')
            raise AssertionError(argv)
        with patch.object(s, 'run', side_effect=run):
            return s.collect(root)

    def test_fully_hardened_node_reports_nothing_missing(self):
        value = self.node(SSHD_HARDENED, 'SSH_PORT="4563"\nSSH_HONEYPOT="on"\n', 'SSH_GOOGLE_REOPEN_ENABLED=yes\n', manager_ssh=True)
        self.assertEqual(value['missing'], [])

    def test_default_install_lists_every_gap(self):
        value = self.node(SSHD_DEFAULT, 'SSH_PORT="22"\n', key=False, enrollment=False)
        self.assertEqual(set(value['missing']), {'moved_port', 'honeypot', 'key_login', 'no_password_only', 'google_window'})
        self.assertNotIn('manager_ssh', value['controls'], 'Optional manager SSH is only judged when installed')

    def test_failing_manager_worker_and_missing_google_jump_are_reported(self):
        value = self.node(SSHD_HARDENED, 'SSH_PORT="4563"\nSSH_HONEYPOT="on"\n', 'SSH_GOOGLE_REOPEN_ENABLED=yes\n',
                          manager_ssh=False, iptables_ok=False)
        self.assertEqual(set(value['missing']), {'manager_ssh', 'google_window'})
        self.assertIn('manager SSH worker healthy', value['missing_labels'])


if __name__ == '__main__':
    unittest.main()
