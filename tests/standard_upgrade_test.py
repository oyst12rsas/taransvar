#!/usr/bin/env python3
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch, Mock

spec = importlib.util.spec_from_file_location('upgrade', Path(__file__).resolve().parents[1] / 'misc/standard_upgrade.py')
u = importlib.util.module_from_spec(spec)
spec.loader.exec_module(u)


class UpgradeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.state = patch.object(u, 'STATE', self.root / 'private/state.json')
        self.mode = patch.object(u, 'MODE', self.root / 'deployment-mode')
        self.state.start(); self.mode.start()

    def tearDown(self):
        self.mode.stop(); self.state.stop(); self.tmp.cleanup()

    def test_mode_is_explicit(self):
        self.assertEqual(u.read_mode(), 'unconfigured')
        u.MODE.write_text('production\n')
        self.assertEqual(u.read_mode(), 'production')
        u.MODE.write_text('invalid')
        with self.assertRaises(ValueError): u.read_mode()

    def test_choice_defaults_to_postpone_and_retries_invalid(self):
        with patch('builtins.input', side_effect=['bogus', '']):
            self.assertEqual(u.choice('Apply'), 'no')
        with patch('builtins.input', return_value='never'):
            self.assertEqual(u.choice('Apply'), 'never')

    def test_backups_retain_original_and_credentials(self):
        source = self.root / 'configuration'
        source.write_text('original')
        dest = self.root / 'backup'
        u.backup(source, dest)
        source.write_text('changed')
        u.backup(source, dest)
        self.assertEqual((dest / str(source).lstrip('/')).read_text(), 'original')
        src, dst = self.root / 'src', self.root / 'dst'
        src.mkdir(); dst.mkdir()
        (src / 'dbfunc.php').write_text('repo credentials')
        (dst / 'dbfunc.php').write_text('local credentials')
        (src / 'controller.php').write_text('new')
        u.copy_tree(src, dst, ('dbfunc.php',))
        self.assertEqual((dst / 'dbfunc.php').read_text(), 'local credentials')
        self.assertEqual((dst / 'controller.php').read_text(), 'new')

    def test_plan_does_not_write_or_execute(self):
        with patch.object(u, 'run') as run, patch('sys.stdout', new=io.StringIO()):
            self.assertEqual(u.main(['upgrade', '--plan', '--roles', 'node']), 0)
        run.assert_not_called()
        self.assertFalse(u.STATE.exists())
        self.assertFalse(u.MODE.exists())

    def test_private_state_and_history(self):
        state = {'features': {'google': {'choice': 'never'}}, 'history': [{'mode': 'demo'}]}
        u.save(state)
        self.assertEqual(u.load_state(), state)
        self.assertEqual(u.STATE.stat().st_mode & 0o777, 0o600)

    def test_symlink_deployment_refused(self):
        src, dst = self.root / 'src', self.root / 'dst'
        src.mkdir(); dst.mkdir()
        (src / 'file').write_text('new')
        sensitive = self.root / 'sensitive'; sensitive.write_text('keep')
        (dst / 'file').symlink_to(sensitive)
        with self.assertRaises(ValueError): u.copy_tree(src, dst)
        self.assertEqual(sensitive.read_text(), 'keep')

    def test_database_dump_failure_prevents_migration(self):
        error = u.subprocess.CalledProcessError(1, 'mariadb-dump')
        with patch.object(u.shutil, 'which', return_value='mariadb-dump'), patch.object(u, 'run', side_effect=error) as run:
            with self.assertRaises(u.subprocess.CalledProcessError):
                u.apply_feature('database', self.root / 'backup')
        self.assertEqual(run.call_count, 1)
        self.assertEqual(run.call_args.args[0][0], 'mariadb-dump')

    def test_production_refuses_active_services(self):
        with patch.object(u, 'settings', return_value={}), patch.object(u.subprocess, 'run', return_value=Mock(returncode=0)):
            with self.assertRaisesRegex(ValueError, 'Release active demo'):
                u.production_preflight()

    def test_production_refuses_active_demo_db_rows(self):
        def execute(args, **kwargs):
            return Mock(stdout='demoSshSession\ninternalInfections\n' if args[-1] == 'SHOW TABLES' else '1\n')
        with patch.object(u, 'settings', return_value={}), patch.object(u.subprocess, 'run', return_value=Mock(returncode=1)), patch.object(u, 'run', side_effect=execute):
            with self.assertRaisesRegex(ValueError, 'Finish/reset active sessions'):
                u.production_preflight(['database'])

    def test_never_is_skipped_and_failure_stops_following_features(self):
        u.MODE.write_text('demo\n')
        u.save({'features': {'google': {'choice': 'never', 'status': 'declined'}}, 'history': []})
        features = (('google', 'Google', {'node'}), ('runtime', 'Runtime', {'node'}), ('ssh-key', 'Key', {'node'}))
        with patch.object(u, 'FEATURES', features), patch.object(u, 'COMMAND', self.root / 'tarasec'), patch.object(u.sys.stdin, 'isatty', return_value=True), patch('builtins.input', side_effect=['yes', 'yes']), patch.object(u, 'apply_feature', side_effect=ValueError('prerequisite missing')) as apply, patch('sys.stdout', new=io.StringIO()):
            self.assertEqual(u.main(['upgrade', '--roles', 'node']), 1)
        self.assertEqual(apply.call_count, 1)
        self.assertEqual(apply.call_args.args[0], 'runtime')
        state = u.load_state()
        self.assertEqual(state['features']['runtime']['status'], 'failed')
        self.assertEqual(state['features']['google']['choice'], 'never')
        self.assertNotIn('ssh-key', state['features'])

    def test_review_declined_offers_feature_again(self):
        u.MODE.write_text('demo\n')
        u.save({'features': {'google': {'choice': 'never'}}, 'history': []})
        with patch.object(u, 'FEATURES', (('google', 'Google', {'node'}),)), patch.object(u, 'COMMAND', self.root / 'tarasec'), patch.object(u.sys.stdin, 'isatty', return_value=True), patch('builtins.input', side_effect=['yes', 'yes']), patch.object(u, 'apply_feature') as apply, patch('sys.stdout', new=io.StringIO()):
            self.assertEqual(u.main(['upgrade', '--roles', 'node', '--review-declined']), 0)
        apply.assert_called_once()
        self.assertEqual(u.load_state()['features']['google']['status'], 'completed')

    def test_shell_settings(self):
        p = self.root / 'config'
        p.write_text('SSH_PORT="5822"\nSSH_FAILSAFE=on\nSSH_HONEYPOT_DEMO_PORT=0\n')
        self.assertEqual(u.settings(p)['SSH_PORT'], '5822')
        u.set_setting(p, 'SSH_PORT', '6000')
        self.assertEqual(u.settings(p)['SSH_PORT'], '6000')
        self.assertEqual(p.read_text().count('SSH_PORT='), 1)

    def test_all_demo_entrypoints_guard_before_dependencies(self):
        root = Path(__file__).resolve().parents[1]
        for p in (root / 'html/script').glob('appDemo*.php'):
            text = p.read_text()
            self.assertIn('tarasecRequireDemo();', text, str(p))
            self.assertLess(text.index('tarasecRequireDemo();'), text.find("include '../dbfunc.php'") if "include '../dbfunc.php'" in text else len(text))


if __name__ == '__main__': unittest.main()
