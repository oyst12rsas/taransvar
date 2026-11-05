"""Verify the hotspot's real baseline block after early owner enrollment."""
from pathlib import Path
import subprocess
import tempfile
import unittest


class EnrollmentBaseline(unittest.TestCase):
    def run_baseline(self, initial):
        source = (Path(__file__).resolve().parents[1] / 'hotspot/distro/install.sh').read_text()
        block = source.split('# The firewall and app demo-discovery endpoint', 1)[1].split('\n', 1)[1]
        block = block.split('bash "$REPO_ROOT/misc/install_agent_approvals.sh" --sync', 1)[0]
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / 'policy.conf'
            if initial is not None:
                path.write_text(initial)
            subprocess.run(['bash', '-e', '-c', block.replace('/etc/tarasecfw.conf', str(path))],
                           check=True, capture_output=True, text=True)
            return path.read_text()

    def test_new_install_with_early_nickname_still_gets_firewall_baseline(self):
        nickname = 'AGENT_PUBLIC_NICKNAME="Silver Meadow"\n'
        result = self.run_baseline(nickname)
        self.assertIn(nickname, result)
        self.assertEqual(result.count('AGENT_PUBLIC_NICKNAME='), 1)
        self.assertIn('ALLOW_WEB=1', result)
        self.assertIn('IS_GATEWAY=0', result)

    def test_existing_policy_is_preserved(self):
        policy = 'AGENT_PUBLIC_NICKNAME="Owner name"\nALLOW_SSH=0\n'
        self.assertEqual(self.run_baseline(policy), policy)

    def test_missing_or_empty_policy_gets_baseline(self):
        for initial in (None, '', '# Owner registration\n'):
            with self.subTest(initial=initial):
                self.assertIn('ALLOW_WEB=1', self.run_baseline(initial))


if __name__ == '__main__':
    unittest.main()
