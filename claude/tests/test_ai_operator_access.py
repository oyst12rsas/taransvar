"""Tests for claude/ai_operator_access.sh against a fake root (no root needed).

Run: python3 -m unittest discover -s claude/tests
"""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest

HERE = Path(__file__).resolve().parent
SCRIPT = HERE.parent / 'ai_operator_access.sh'
CHECKS = HERE.parent / 'checks.d'

PASSWD = """root:x:0:0:root:/root:/bin/bash
daemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin
claude:x:1000:1000:claude:/home/claude:/bin/bash
"""
GROUP = """root:x:0:
sudo:x:27:claude
claude:x:1000:
"""
SHADOW = """root:!:20000:0:99999:7:::
daemon:*:20000:0:99999:7:::
claude:$6$x$y:20000:0:99999:7:::
"""


class FakeHost(unittest.TestCase):
    def setUp(self):
        self.root = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.root)
        for d in ('etc/sudoers.d', 'etc/cron.d', 'etc/ssh', 'home/claude/.ssh', 'root', 'var/lib'):
            (self.root / d).mkdir(parents=True)
        self.write('etc/passwd', PASSWD)
        self.write('etc/group', GROUP)
        self.write('etc/shadow', SHADOW)
        self.write('etc/sudoers', 'Defaults env_reset\n%sudo ALL=(ALL:ALL) ALL\n@includedir /etc/sudoers.d\n')
        self.write('etc/sudoers.d/README', '# comments only\n')

    def write(self, rel, text):
        path = self.root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)
        return path

    def add_owner(self):
        self.write('etc/passwd', PASSWD + 'owner:x:1001:1001::/home/owner:/bin/bash\n')
        self.write('etc/group', GROUP.replace('sudo:x:27:claude', 'sudo:x:27:claude,owner') + 'owner:x:1001:\n')
        self.write('etc/shadow', SHADOW + 'owner:$6$a$b:20000:0:99999:7:::\n')

    def leave_sudo_group(self):
        """Dry run only prints gpasswd; apply its effect to the fake host."""
        text = (self.root / 'etc/group').read_text()
        self.write('etc/group', text.replace('sudo:x:27:claude,owner', 'sudo:x:27:owner').replace('sudo:x:27:claude', 'sudo:x:27:'))

    def run_script(self, *args, checks=True):
        env = dict(os.environ, AI_ACCESS_ROOT=str(self.root), AI_ACCESS_DRY_RUN='1',
                   AI_ACCESS_CHECKS=str(CHECKS if checks else self.root / 'no-checks'))
        return subprocess.run(['bash', str(SCRIPT), *args], capture_output=True, text=True, env=env)

    @property
    def dropin(self):
        return self.root / 'etc/sudoers.d/90-ai-operator'


class GrantTests(FakeHost):
    def test_refuses_without_separate_owner_account(self):
        result = self.run_script('grant')
        self.assertEqual(result.returncode, 2)
        self.assertIn('no owner account', result.stderr)
        self.assertFalse(self.dropin.exists())

    def test_root_with_password_counts_as_owner(self):
        self.write('etc/shadow', SHADOW.replace('root:!:', 'root:$6$r$s:'))
        self.assertEqual(self.run_script('grant').returncode, 0)

    def test_grant_writes_single_validated_dropin_and_moves_group_access(self):
        self.add_owner()
        result = self.run_script('grant')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('RUN: gpasswd -d claude sudo', result.stdout)
        self.assertIn('claude ALL=(ALL:ALL) NOPASSWD: ALL', self.dropin.read_text())
        self.assertEqual(self.dropin.stat().st_mode & 0o777, 0o440)
        self.assertEqual(subprocess.run(['visudo', '-cqf', str(self.dropin)]).returncode, 0)
        self.assertIn('Owner account(s) that keep root: owner', result.stdout)
        self.assertNotIn('systemd-run', result.stdout)
        self.assertRegex((self.root / 'var/lib/ai-operator/grant.env').read_text(), r'GRANTED_AT=\d+')

    def test_expiry_schedules_revoke(self):
        self.add_owner()
        result = self.run_script('grant', '--hours', '4')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(result.stdout, r'RUN: systemd-run .*--on-active=4h .*ai_operator_access\.sh --user claude revoke')
        self.assertIn('EXPIRES_AT=', (self.root / 'var/lib/ai-operator/grant.env').read_text())

    def test_rejects_bad_hours_and_root_ai_account(self):
        self.add_owner()
        for hours in ('0', '721', '1.5', 'x'):
            self.assertEqual(self.run_script('grant', '--hours', hours).returncode, 2, hours)
        self.assertEqual(self.run_script('--user', 'root', 'grant').returncode, 2)
        self.assertFalse(self.dropin.exists())


class RevokeAndAuditTests(FakeHost):
    def granted(self):
        self.add_owner()
        self.assertEqual(self.run_script('grant').returncode, 0)
        self.leave_sudo_group()

    def test_revoke_removes_dropin_ends_sessions_and_audits_clean(self):
        self.granted()
        result = self.run_script('revoke', checks=False)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse(self.dropin.exists())
        self.assertIn('RUN: loginctl terminate-user claude', result.stdout)
        self.assertIn('no grant drop-in', result.stdout)
        self.assertIn('== 0 FAIL', result.stdout)

    def test_revoke_lock_quarantines_credentials(self):
        self.granted()
        self.write('home/claude/.ssh/authorized_keys', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample ai\n')
        self.write('home/claude/.claude.json', '{}')
        result = self.run_script('revoke', '--lock', checks=False)
        self.assertIn('RUN: usermod -L claude', result.stdout)
        self.assertIn('RUN: usermod -e 1 claude', result.stdout)
        self.assertFalse((self.root / 'home/claude/.claude.json').exists())
        self.assertFalse((self.root / 'home/claude/.ssh/authorized_keys').exists())
        quarantine = list((self.root / 'var/lib/ai-operator').glob('quarantine-*'))
        self.assertEqual(len(quarantine), 1)
        self.assertTrue((quarantine[0] / '.claude.json').exists())

    def test_audit_fails_on_leftover_root_paths(self):
        self.granted()
        self.run_script('revoke', checks=False)
        self.write('etc/sudoers.d/zz-keep', 'claude ALL=(ALL) NOPASSWD:ALL\n')
        self.write('etc/passwd', (self.root / 'etc/passwd').read_text() + 'toor:x:0:0::/root:/bin/bash\n')
        self.write('etc/group', (self.root / 'etc/group').read_text().replace('sudo:x:27:owner', 'sudo:x:27:owner,claude'))
        result = self.run_script('audit', checks=False)
        self.assertEqual(result.returncode, 1)
        self.assertIn("sudo rule for 'claude' outside the drop-in: /etc/sudoers.d/zz-keep", result.stdout)
        self.assertIn("account 'toor' has uid 0", result.stdout)
        self.assertIn("'claude' is in admin group(s) sudo", result.stdout)
        self.assertIn('== 3 FAIL', result.stdout)

    def test_audit_lists_changes_since_grant_and_keys(self):
        self.granted()
        start = int(time.time()) - 5
        old = self.write('etc/cron.d/old', '# before\n')
        os.utime(old, (start - 100, start - 100))
        self.write('etc/cron.d/new-job', '* * * * * root /tmp/x\n')
        self.write('root/.ssh/authorized_keys', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample x\n')
        result = self.run_script('audit', '--since', str(start), checks=False)
        self.assertIn('REVIEW changed: /etc/cron.d/new-job', result.stdout)
        self.assertNotIn('/etc/cron.d/old', result.stdout)
        self.assertIn('SSH key(s) let root log in', result.stdout)
        self.assertIn('grant ACTIVE', result.stdout)

    def test_audit_warns_when_owner_account_missing(self):
        result = self.run_script('audit', checks=False)
        self.assertIn('no owner account', result.stdout)
        self.assertEqual(result.returncode, 1)


class TaraSecCheckTests(FakeHost):
    def setUp(self):
        super().setUp()
        self.add_owner()
        self.write('etc/group', GROUP.replace('claude', '', 1).replace('sudo:x:27:', 'sudo:x:27:owner') + 'owner:x:1001:\n')

    def test_skipped_when_tarasec_absent(self):
        result = self.run_script('audit')
        self.assertIn('TaraSec not installed', result.stdout)

    def test_flags_manager_and_approval_changes_during_grant(self):
        start = int(time.time()) - 5
        self.write('etc/tarasec/access.conf', 'MANAGER_EMAILS=""\nMANAGER_EMAIL_SHA256="%s,%s"\n' % ('a' * 64, 'b' * 64))
        self.write('etc/tarasec-server-manager.conf', 'AGENT_APPROVAL_URL="https://evil.example/ops/agent/api.php"\n')
        self.write('etc/tarasec/agent-node.token', 'c' * 64 + '\n')
        result = self.run_script('audit', '--since', str(start))
        self.assertIn('TaraSec Google managers: 2', result.stdout)
        self.assertIn('manager list changed during the grant', result.stdout)
        self.assertIn('approval service is not tarasec.org: https://evil.example', result.stdout)
        self.assertIn('node credential replaced', result.stdout)


if __name__ == '__main__':
    unittest.main()
