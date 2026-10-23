#!/usr/bin/env python3
"""Exercise the launcher against isolated local Git repositories, without networking."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

LAUNCHER = Path(__file__).resolve().parents[1] / 'tarasec'


@unittest.skipUnless(os.geteuid() == 0, 'Normal upgrade launcher requires sudo')
class PullTests(unittest.TestCase):
    def git(self, *args, cwd=None):
        return subprocess.run(['git', *map(str, args)], cwd=cwd, check=True,
                              capture_output=True, text=True).stdout.strip()

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.origin, self.seed, self.host = (self.root / n for n in ('origin', 'seed', 'host'))
        self.git('init', '--bare', '--initial-branch=main', self.origin)
        self.git('clone', self.origin, self.seed)
        self.git('config', 'user.email', 'test@example.invalid', cwd=self.seed)
        self.git('config', 'user.name', 'Test', cwd=self.seed)
        shutil.copy2(LAUNCHER, self.seed / 'tarasec')
        (self.seed / 'misc').mkdir()
        (self.seed / 'misc/standard_upgrade.py').write_text("import sys\nprint('ENGINE', *sys.argv[1:])\n")
        self.commit('initial')
        self.git('clone', self.origin, self.host)

    def tearDown(self): self.tmp.cleanup()

    def commit(self, message):
        self.git('add', '.', cwd=self.seed)
        self.git('commit', '-m', message, cwd=self.seed)
        self.git('push', 'origin', 'main', cwd=self.seed)

    def launch(self, *args):
        return subprocess.run(['bash', str(self.host / 'tarasec'), 'upgrade', *args],
                              capture_output=True, text=True)

    def test_fetches_new_engine_and_reexecutes(self):
        (self.seed / 'misc/standard_upgrade.py').write_text("import sys\nprint('UPDATED_ENGINE', *sys.argv[1:])\n")
        self.commit('new engine')
        result = self.launch()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('UPDATED_ENGINE upgrade --no-pull', result.stdout)
        self.assertEqual(self.git('rev-parse', 'HEAD', cwd=self.host), self.git('rev-parse', 'HEAD', cwd=self.seed))

    def test_dirty_checkout_is_preserved(self):
        (self.host / 'local-change').write_text('keep')
        result = self.launch()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Local changes', result.stderr)
        self.assertNotIn('ENGINE', result.stdout)
        self.assertEqual((self.host / 'local-change').read_text(), 'keep')

    def test_no_pull_plan_status_and_help_are_offline(self):
        self.git('remote', 'set-url', 'origin', self.root / 'missing', cwd=self.host)
        for arg in ('--no-pull', '--plan', '--status', '--help'):
            with self.subTest(arg=arg):
                result = self.launch(arg)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn('ENGINE', result.stdout)
                self.assertNotIn('Fetching', result.stdout)

    def test_failed_fetch_does_not_run_engine(self):
        self.git('remote', 'set-url', 'origin', self.root / 'missing', cwd=self.host)
        result = self.launch()
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('ENGINE', result.stdout)

    def test_feature_branch_requires_explicit_no_pull(self):
        self.git('switch', '-c', 'feature', cwd=self.host)
        result = self.launch()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('requires main', result.stderr)
        self.assertEqual(self.launch('--no-pull').returncode, 0)

    def test_local_commit_is_not_overwritten(self):
        self.git('config', 'user.email', 'test@example.invalid', cwd=self.host)
        self.git('config', 'user.name', 'Test', cwd=self.host)
        self.git('commit', '--allow-empty', '-m', 'local only', cwd=self.host)
        before = self.git('rev-parse', 'HEAD', cwd=self.host)
        result = self.launch()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('unpublished or divergent', result.stderr)
        self.assertEqual(before, self.git('rev-parse', 'HEAD', cwd=self.host))


if __name__ == '__main__': unittest.main()
