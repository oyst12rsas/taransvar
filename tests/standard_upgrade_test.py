#!/usr/bin/env python3
import sys
sys.path.insert(0, str(__import__('pathlib').Path(__file__).resolve().parents[1] / 'misc'))
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
        self.uid = patch.object(u.os, 'geteuid', return_value=0)
        self.uid.start()
        self.installed = patch.object(u, 'operations_installed', return_value=True)
        self.installed.start()
        self.state.start(); self.mode.start()

    def tearDown(self):
        self.installed.stop()
        self.uid.stop(); self.mode.stop(); self.state.stop(); self.tmp.cleanup()

    def test_ssh_auth_always_requires_confirmation_and_honors_never(self):
        for mode in ('demo', 'production'):
            for answer in ('no', 'never'):
                u.MODE.write_text(mode+'\n')
                u.save({'features': {}, 'history': []})
                with patch.object(u, 'FEATURES', (('ssh-auth', 'SSH factors', {'node'}),)), patch.object(u, 'COMMAND', self.root/'tarasec'), patch.object(u.sys.stdin, 'isatty', return_value=True), patch.object(u, 'ssh_auth_policy', return_value={'authenticationmethods':'any'}), patch('builtins.input', side_effect=['yes', answer]) as prompt, patch.object(u, 'apply_feature') as apply, patch('sys.stdout', new=io.StringIO()) as output:
                    self.assertEqual(u.main(['upgrade','--roles','node']),0)
                    self.assertIn('WARNING: SSH AuthenticationMethods is any',output.getvalue())
                self.assertEqual(prompt.call_count,2)
                apply.assert_not_called()
                self.assertEqual(u.load_state()['features']['ssh-auth']['choice'],answer)
                if answer == 'never':
                    with patch.object(u, 'FEATURES', (('ssh-auth','SSH factors',{'node'}),)), patch.object(u, 'COMMAND', self.root/'tarasec'), patch.object(u.sys.stdin,'isatty',return_value=True), patch('builtins.input',side_effect=['yes']) as prompt, patch.object(u,'ssh_auth_policy') as check, patch('sys.stdout',new=io.StringIO()):
                        self.assertEqual(u.main(['upgrade','--roles','node']),0)
                    check.assert_not_called()

    def test_ssh_auth_current_skips_offer(self):
        u.MODE.write_text('demo\n')
        with patch.object(u,'FEATURES',(('ssh-auth','SSH factors',{'node'}),)), patch.object(u,'COMMAND',self.root/'tarasec'), patch.object(u.sys.stdin,'isatty',return_value=True), patch.object(u,'ssh_auth_policy',return_value={'authenticationmethods':'publickey,password'}), patch('builtins.input',side_effect=['yes']) as prompt, patch.object(u,'apply_feature') as apply, patch('sys.stdout',new=io.StringIO()):
            self.assertEqual(u.main(['upgrade','--roles','node']),0)
        self.assertEqual(prompt.call_count,1)
        apply.assert_not_called()

    def test_ssh_auth_guarded_apply_and_rollback(self):
        for failure in (False, True):
            target=self.root/'ssh'/('failed.conf' if failure else 'success.conf')
            target.parent.mkdir(exist_ok=True)
            target.write_text('# previous config\n')
            before={'authenticationmethods':'any'}
            after={'authenticationmethods':'publickey,password','pubkeyauthentication':'yes','passwordauthentication':'yes'}
            checks=[before, before if failure else after, after]
            commands=[]
            def runner(argv, **kwargs):
                commands.append([str(x) for x in argv])
                return Mock(stdout='ubuntu P today 0 99999 7 -1' if argv[0]=='passwd' else 'loaded\n')
            with patch.object(u,'SSH_AUTH_TARGET',target), patch.object(u,'run',side_effect=runner), patch.object(u,'ssh_auth_policy',side_effect=checks), patch.object(u.pwd,'getpwnam'), patch('builtins.input',return_value='ubuntu'), patch.object(u,'confirm_verified'), patch('sys.stdout',new=io.StringIO()):
                directory=self.root/('backup-fail' if failure else 'backup-ok')
                if failure:
                    with self.assertRaises(ValueError):u.require_ssh_key_password(directory)
                else:
                    result=u.require_ssh_key_password(directory)
                    self.assertTrue(result['new_login_confirmed'])
            arm=next(i for i,c in enumerate(commands) if c[0]=='systemd-run')
            self.assertIn('--on-active=10m',commands[arm])
            self.assertTrue((directory/str(target).lstrip('/')).exists())
            if failure:
                self.assertTrue(any(c[0]=='/bin/sh' for c in commands))
                self.assertFalse(any(c[:2]==['systemctl','reload'] for c in commands))
            else:
                reload=next(i for i,c in enumerate(commands) if c[:2]==['systemctl','reload'])
                self.assertLess(arm,reload)

    def test_ssh_auth_custom_policy_and_locked_password_never_mutate(self):
        for status, methods in [('ubuntu L today', 'any'), ('ubuntu P today', 'publickey')]:
            with patch('builtins.input',return_value='ubuntu'), patch.object(u.pwd,'getpwnam'), patch.object(u,'run',return_value=Mock(stdout=status)) as run, patch.object(u,'ssh_auth_policy',return_value={'authenticationmethods':methods}), patch.object(u,'atomic') as write:
                with self.assertRaises(ValueError):u.require_ssh_key_password(self.root/'backup')
            self.assertEqual(run.call_count,1)
            write.assert_not_called()

    def test_ssh_auth_policy_uses_actual_connection_context(self):
        with patch.dict(u.os.environ,{'SSH_CONNECTION':'100.68.1.2 1234 100.68.176.110 5822'}), patch.object(u,'run',return_value=Mock(stdout='authenticationmethods any\n')) as run:
            self.assertEqual(u.ssh_auth_policy('ubuntu')['authenticationmethods'],'any')
        self.assertIn('user=ubuntu',run.call_args.args[0][-1])
        self.assertIn('addr=100.68.1.2',run.call_args.args[0][-1])
        self.assertIn('lport=5822',run.call_args.args[0][-1])

    def test_activity_setup_starts_only_with_verified_demo_feed(self):
        config=self.root/'etc/tarasec/operations-activity.json'
        config.parent.mkdir(parents=True)
        with patch.object(u,'run') as run, patch('sys.stdout',new=io.StringIO()):
            self.assertEqual(u.ensure_operations_activity(self.root),'needs_configuration')
        run.assert_not_called()
        config.write_text('{"demo_url":"https://example.test/feed","demo_collector":"/collector"}')
        for complete, expected in [(False,'feed_unavailable'),(True,'active')]:
            with patch('operations_agent.trusted',return_value=Path('/collector')), patch.object(u,'run',return_value=Mock(stdout=u.json.dumps(dict(complete=complete,active_demo=False,checked_at=u.time.time())))) as run, patch('sys.stdout',new=io.StringIO()):
                self.assertEqual(u.ensure_operations_activity(self.root),expected)
            services=[c.args[0] for c in run.call_args_list if c.args[0][0]=='systemctl']
            self.assertEqual(bool(services),complete)

    def test_activity_repair_is_separate_from_full_upgrade(self):
        u.MODE.write_text('demo\n')
        with patch.object(u.sys.stdin,'isatty',return_value=True), patch.object(u,'run') as run, patch.object(u,'backup'), patch.object(u,'ensure_operations_activity',return_value='active') as configure, patch.object(u,'apply_feature') as apply, patch('builtins.input',side_effect=AssertionError('No full-upgrade prompts')), patch('sys.stdout',new=io.StringIO()):
            self.assertEqual(u.main(['upgrade','--configure-activity','--roles','node']),0)
        configure.assert_called_once_with(interactive=True)
        apply.assert_not_called()
        run.assert_called_once_with(['bash',u.REPO/'misc/install_operations_agent.sh'])

    def test_mode_is_explicit(self):
        self.assertEqual(u.read_mode(), 'unconfigured')
        u.MODE.write_text('production\n')
        self.assertEqual(u.read_mode(), 'production')
        u.MODE.write_text('invalid')
        with self.assertRaises(ValueError): u.read_mode()

    def test_current_ssh_window_is_checked_without_mutations(self):
        config=self.root/'etc';config.mkdir()
        (config/'tarasec-server-manager.conf').write_text('SSH_GOOGLE_REOPEN_ENABLED=yes\n')
        (config/'tarasecfw.conf').write_text('SSH_PORT=5822\n')
        (config/'tarasec').mkdir();(config/'tarasec/agent-enrollment.json').write_text('{}')
        installed=self.root/'usr/local/lib/tarasec';installed.mkdir(parents=True)
        for name in ('ssh_google_window.py','local_manager_policy.py','agent_approval_worker.py','ssh_approval_poll.py'):
            (installed/name).write_bytes((u.REPO/'misc'/name).read_bytes())
        with patch.object(u,'run') as run:
            self.assertTrue(u.ssh_window_current(self.root))
        self.assertEqual(run.call_count,4)
        self.assertTrue(all('-C' in c.args[0] or 'is-active' in c.args[0] for c in run.call_args_list))
        with patch.object(u,'run',side_effect=u.subprocess.CalledProcessError(1,['iptables'])):
            self.assertFalse(u.ssh_window_current(self.root))
        (installed/'ssh_google_window.py').write_text('outdated')
        with patch.object(u,'run') as run:
            self.assertFalse(u.ssh_window_current(self.root))
        run.assert_not_called()

    def test_current_ssh_window_skips_prompt_in_both_modes(self):
        for mode in ('demo','production'):
            u.MODE.write_text(mode+'\n')
            with patch.object(u,'ssh_window_current',return_value=True), patch.object(u,'FEATURES',(('ssh-window','SSH window',{'node'}),)), patch.object(u,'COMMAND',self.root/'tarasec'), patch.object(u.sys.stdin,'isatty',return_value=True), patch('builtins.input',side_effect=['yes']) as prompt, patch.object(u,'apply_feature') as apply, patch('sys.stdout',new=io.StringIO()):
                self.assertEqual(u.main(['upgrade','--roles','node']),0)
            self.assertEqual(prompt.call_count,1)
            apply.assert_not_called()

    def test_new_agent_requires_installation_confirmation_in_demo(self):
        u.MODE.write_text('demo\n')
        with patch.object(u, 'operations_installed', return_value=False), patch.object(u, 'FEATURES', (('operations', 'AI agent', {'node'}),)), patch.object(u, 'COMMAND', self.root/'tarasec'), patch.object(u.sys.stdin, 'isatty', return_value=True), patch('builtins.input', side_effect=['yes','no']) as prompt, patch.object(u, 'apply_feature') as apply, patch('sys.stdout', new=io.StringIO()):
            self.assertEqual(u.main(['upgrade','--roles','node']),0)
        self.assertEqual(prompt.call_count,2)
        apply.assert_not_called()

    def test_agent_activation_uses_selected_mode_only_after_successful_probe(self):
        path = self.root/'etc/tarasec/operations-agent.json'
        path.parent.mkdir(parents=True)
        original = '{"mode":"inspect","model_url":"https://example.test/agent","quiet_seconds":300}'
        for mode in ('demo','production'):
            u.MODE.write_text(mode+'\n')
            path.write_text(original)
            with patch.object(u,'configure_operations_model',return_value=True), patch.object(u.subprocess,'run',return_value=Mock(returncode=1)), patch.object(u,'run') as run:
                result=u.activate_operations(self.root/'backup',root=self.root)
            self.assertTrue(result['pending_configuration'])
            self.assertEqual(path.read_text(),original)
            run.assert_not_called()
            with patch.object(u,'configure_operations_model',return_value=True), patch.object(u.subprocess,'run',return_value=Mock(returncode=0)), patch.object(u,'run') as run:
                result=u.activate_operations(self.root/'backup',root=self.root)
            self.assertEqual(result['activation'],'active')
            self.assertEqual(u.json.loads(path.read_text())['mode'],mode)
            self.assertEqual(u.json.loads(path.read_text())['quiet_seconds'],300)
            run.assert_any_call(['systemctl','enable','--now','tarasec-operations-agent.timer'])

    def test_missing_model_configuration_is_pending_not_completed(self):
        path=self.root/'etc/tarasec/operations-agent.json'
        path.parent.mkdir(parents=True);path.write_text('{"mode":"inspect","model_url":""}')
        u.MODE.write_text('demo\n')
        with patch.object(u.sys.stdin,'isatty',return_value=False), patch.object(u.subprocess,'run') as run:
            result=u.activate_operations(self.root/'backup',root=self.root)
        run.assert_not_called()
        record={}
        u.record_feature_result(record,'operations',result,5,'a'*40)
        self.assertEqual(record['status'],'partial')

    def test_guided_connection_saves_private_settings_and_retries_url(self):
        path=self.root/'etc/tarasec/operations-agent.json'
        path.parent.mkdir(parents=True);path.write_text('{"mode":"inspect"}')
        policy={'mode':'inspect'}
        with patch.object(u.sys.stdin,'isatty',return_value=True),patch('builtins.input',side_effect=['flowise','invalid','https://example.test/prediction/agent']),patch.object(u.getpass,'getpass',return_value='private-test-key'),patch('sys.stdout',new=io.StringIO()) as output:
            self.assertTrue(u.configure_operations_model(policy,path,self.root/'backup',root=self.root))
        self.assertNotIn('private-test-key',output.getvalue())
        key=Path(policy['model_key_file'])
        self.assertEqual(key.stat().st_mode & 0o777,0o600)
        self.assertEqual(path.stat().st_mode & 0o777,0o600)
        self.assertEqual(policy['mode'],'inspect')
        with patch('builtins.input',side_effect=AssertionError('Must reuse configured connection')):
            self.assertTrue(u.configure_operations_model(policy,path,self.root/'backup',root=self.root))

    def test_blank_selects_central_system_and_none_postpones(self):
        path=self.root/'policy.json'
        for answers,called in [([''],True),(['flowise',''],True),(['none'],False),(['flowise','none'],False)]:
            with patch.object(u.sys.stdin,'isatty',return_value=True),patch('builtins.input',side_effect=answers),patch.object(u,'enroll_operations_model',return_value=False) as enroll,patch.object(u.getpass,'getpass') as secret:
                self.assertFalse(u.configure_operations_model({},path,self.root/'backup',root=self.root))
            self.assertEqual(enroll.called,called)
            secret.assert_not_called()

    def test_approval_wait_uses_five_minute_deadline(self):
        with patch.object(u.time,'monotonic',side_effect=[0,0,300]),patch.object(u.time,'sleep') as sleep,patch.object(u.urllib.request,'build_opener') as opener,patch('sys.stdout',new=io.StringIO()) as output:
            answer=u.wait_operations_approval(Mock(),{'state':'pending'})
        self.assertEqual(answer['state'],'pending')
        sleep.assert_called_once_with(5)
        opener.assert_not_called()
        self.assertIn('5 minutes',output.getvalue())

    def test_ctrl_c_postpones_only_the_approval_wait(self):
        with patch.object(u.time,'monotonic',return_value=0),patch.object(u.time,'sleep',side_effect=KeyboardInterrupt),patch('sys.stdout',new=io.StringIO()) as output:
            answer=u.wait_operations_approval(Mock(),{'state':'pending'})
        self.assertFalse(answer['approved'])
        self.assertIn('Continuing remaining upgrades',output.getvalue())

    def test_approval_received_during_wait_returns_immediately(self):
        import contextlib
        response=Mock();response.read.return_value=b'{"approved":true,"model_ready":true}'
        opener=Mock();opener.open.return_value=contextlib.nullcontext(response)
        with patch.object(u.time,'monotonic',side_effect=[0,0,5]),patch.object(u.time,'sleep'),patch.object(u.urllib.request,'build_opener',return_value=opener):
            answer=u.wait_operations_approval(Mock(),{'state':'pending'})
        self.assertTrue(answer['approved'])
        self.assertEqual(opener.open.call_count,1)

    def test_central_enrollment_installs_only_node_identity_after_approval(self):
        import contextlib
        config=self.root/'etc/tarasec';config.mkdir(parents=True)
        receipt=config/'agent-enrollment.json'
        receipt.write_text('{"approval_url":"https://tarasec.org/ops/agent/api.php","nickname":"Test node"}')
        token=config/'agent-node.token';token.write_text('a'*64);token.chmod(0o600);receipt.chmod(0o600)
        path=config/'operations-agent.json';path.write_text('{}')
        u.MODE.write_text('demo\n')
        for approved in (False,True):
            response=Mock();response.read.return_value=u.json.dumps({'approved':approved,'model_ready':True}).encode()
            opener=Mock();opener.open.return_value=contextlib.nullcontext(response)
            with patch('enroll_google_manager.private_read',side_effect=lambda p:p.read_text()), patch('enroll_google_manager.urllib.request.build_opener',return_value=opener):
                policy={}
                self.assertEqual(u.enroll_operations_model(policy,path,self.root/'backup',root=self.root),approved)
            if approved:
                self.assertEqual(policy['model_connection'],'tarasec')
                self.assertEqual(policy['model_key_file'],str(token))
                self.assertTrue(policy['model_url'].endswith('action=operations_model'))
            else:self.assertEqual(path.read_text(),'{}')

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
        def execute(argv, **kwargs):
            if 'stdout' in kwargs:
                kwargs['stdout'].write(b'incomplete backup')
                raise error
            return Mock(stdout='99' if 'taransvar' in argv else '1024')
        with patch.object(u.shutil, 'which', side_effect=lambda name:name), patch.object(u.shutil,'disk_usage',return_value=Mock(free=1024**3)), patch.object(u, 'run', side_effect=execute) as run:
            with self.assertRaises(u.subprocess.CalledProcessError):
                u.apply_feature('database', self.root / 'backup')
        self.assertEqual(run.call_count, 3)
        self.assertEqual(run.call_args.args[0][0], 'mariadb-dump')
        self.assertFalse((self.root/'backup/taransvar.sql.partial').exists())
        self.assertFalse((self.root/'backup/taransvar.sql').exists())

    def test_current_schema_needs_no_dump_or_space(self):
        with patch.object(u.shutil,'which',side_effect=lambda name:name),patch.object(u,'run',return_value=Mock(stdout='100')) as run,patch.object(u.shutil,'disk_usage') as disk:
            result=u.apply_feature('database',self.root/'backup')
        self.assertEqual(result['migration'],'not_needed')
        self.assertEqual(run.call_count,1)
        disk.assert_not_called()

    def test_database_space_check_precedes_dump_and_migration(self):
        with patch.object(u.shutil,'which',side_effect=lambda name:name),patch.object(u,'run',side_effect=[Mock(stdout='99'),Mock(stdout='1000000')]) as run,patch.object(u.shutil,'disk_usage',return_value=Mock(free=100)):
            with self.assertRaisesRegex(ValueError,'Insufficient space'):
                u.apply_feature('database',self.root/'backup')
        self.assertEqual(run.call_count,2)
        self.assertFalse((self.root/'backup/taransvar.sql.partial').exists())

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

    def test_production_rejects_automatic_even_with_legacy_opt_in(self):
        u.MODE.write_text('production\n')
        u.save({'mode':'production','roles':['node'],'automatic_enabled':True,'features':{},'history':[]})
        with patch.object(u, 'detect_roles', side_effect=AssertionError('Saved roles must not inspect the host')), patch.object(u, 'apply_feature') as apply, patch('sys.stdout', new=io.StringIO()):
            with self.assertRaisesRegex(ValueError, 'confirmation for every upgrade'):
                u.main(['upgrade', '--automatic'])
        apply.assert_not_called()

    def test_demo_medium_update_needs_no_feature_prompt(self):
        u.MODE.write_text('demo\n')
        with patch.object(u, 'FEATURES', (('operations', 'Operations', {'node'}),)), patch.object(u, 'COMMAND', self.root / 'tarasec'), patch.object(u.sys.stdin, 'isatty', return_value=True), patch('builtins.input', side_effect=['yes']) as prompt, patch.object(u, 'apply_feature') as apply, patch('sys.stdout', new=io.StringIO()):
            self.assertEqual(u.main(['upgrade', '--roles', 'node']), 0)
        self.assertEqual(prompt.call_count, 1)  # Roles only, no upgrade confirmation.
        apply.assert_called_once()
        self.assertEqual(u.load_state()['features']['operations']['approval'], 'demo_policy')

    def test_production_asks_again_on_every_run(self):
        u.MODE.write_text('production\n')
        features = (('operations', 'Operations', {'node'}),)
        for attempt in range(2):
            with patch.object(u, 'FEATURES', features), patch.object(u, 'COMMAND', self.root / 'tarasec'), patch.object(u.sys.stdin, 'isatty', return_value=True), patch('builtins.input', side_effect=['yes', 'yes']) as prompt, patch.object(u, 'apply_feature') as apply, patch('sys.stdout', new=io.StringIO()):
                self.assertEqual(u.main(['upgrade', '--roles', 'node']), 0)
            self.assertEqual(prompt.call_count, 2)
            apply.assert_called_once()
            self.assertEqual(u.load_state()['features']['operations']['approval'], 'user_confirmation')

    def test_automatic_demo_applies_new_bounded_component_without_opt_in(self):
        u.MODE.write_text('demo\n')
        u.save({'mode':'demo','roles':['node'],'automatic_enabled':False,'features':{},'history':[]})
        with patch.object(u, 'FEATURES', (('operations', 'Operations', {'node'}),)), patch.object(u, 'apply_feature') as apply, patch('builtins.input', side_effect=AssertionError('No unattended prompts')), patch('sys.stdout', new=io.StringIO()):
            self.assertEqual(u.main(['upgrade', '--automatic']), 0)
        apply.assert_called_once()
        self.assertEqual(u.load_state()['features']['operations']['approval'], 'demo_policy')

    def test_legacy_gatekeeper_config_is_detected_without_node_receipt(self):
        config = self.root / 'etc/tarasec/gatekeeper-google.php'
        config.parent.mkdir(parents=True)
        config.write_text('<?php return [];')
        self.assertTrue(u.existing_google_setup(self.root))
        self.assertFalse((config.parent / 'agent-enrollment.json').exists())

    def test_existing_google_refresh_in_demo_has_no_upgrade_prompt(self):
        u.MODE.write_text('demo\n')
        with patch.object(u, 'existing_google_setup', return_value=True), patch.object(u, 'FEATURES', (('google', 'Google', {'node'}),)), patch.object(u, 'COMMAND', self.root / 'tarasec'), patch.object(u.sys.stdin, 'isatty', return_value=True), patch('builtins.input', side_effect=['yes']) as prompt, patch.object(u, 'apply_feature') as apply, patch('sys.stdout', new=io.StringIO()):
            self.assertEqual(u.main(['upgrade', '--roles', 'node']), 0)
        self.assertEqual(prompt.call_count, 1)
        self.assertNotIn('allow_enrollment', apply.call_args.kwargs)
        self.assertEqual(u.load_state()['features']['google']['risk'], 'medium')

    def test_existing_google_in_production_still_requires_confirmation(self):
        u.MODE.write_text('production\n')
        with patch.object(u, 'existing_google_setup', return_value=True), patch.object(u, 'FEATURES', (('google', 'Google', {'node'}),)), patch.object(u, 'COMMAND', self.root / 'tarasec'), patch.object(u.sys.stdin, 'isatty', return_value=True), patch('builtins.input', side_effect=['yes','no']) as prompt, patch.object(u, 'apply_feature') as apply, patch('sys.stdout', new=io.StringIO()):
            self.assertEqual(u.main(['upgrade', '--roles', 'node']), 0)
        self.assertEqual(prompt.call_count, 2)
        apply.assert_not_called()

    def test_legacy_gatekeeper_only_refresh_does_not_enroll_worker(self):
        config = self.root / 'etc/tarasec/gatekeeper-google.php'
        config.parent.mkdir(parents=True); config.write_text('unchanged credentials')
        web = self.root / 'var/www/html/gatekeeper'; web.mkdir(parents=True)
        common = web.parent / 'script/serviceDiscoveryCommon.php'
        common.parent.mkdir(); common.write_text('<?php')
        with patch.object(u, 'run') as run, patch('sys.stdout', new=io.StringIO()):
            u.refresh_google(self.root / 'backup', root=self.root)
        self.assertEqual(config.read_text(), 'unchanged credentials')
        self.assertEqual(run.call_count, 3)
        self.assertTrue(all(call.args[0][0] == 'php' for call in run.call_args_list))
        self.assertTrue((web / 'google_callback.php').exists())

    def test_existing_node_identity_refresh_uses_update_only(self):
        token = self.root / 'etc/tarasec/agent-node.token'
        token.parent.mkdir(parents=True); token.write_text('existing private identity')
        (token.parent / 'access.conf').write_text('MANAGER_EMAIL_SHA256=existing')
        (self.root / 'etc/tarasec-server-manager.conf').write_text('SSH_GOOGLE_REOPEN_ENABLED=no')
        (self.root / 'etc/tarasecfw.conf').write_text('AGENT_PUBLIC_NICKNAME=ExistingNode')
        with patch.object(u, 'run') as run, patch('sys.stdout', new=io.StringIO()):
            u.refresh_google(self.root / 'backup', root=self.root)
        run.assert_called_once_with(['bash', u.REPO / 'misc/install_agent_approvals.sh', '--no-enrollment', '--update-only'])
        self.assertEqual(token.read_text(), 'existing private identity')

    def test_incomplete_optional_worker_does_not_abort_google_refresh(self):
        config = self.root / 'etc/tarasec/gatekeeper-google.php'
        config.parent.mkdir(parents=True); config.write_text('existing client configuration')
        (config.parent / 'agent-node.token').write_text('existing private token')
        web = self.root / 'var/www/html/gatekeeper'; web.mkdir(parents=True)
        common = web.parent / 'script/serviceDiscoveryCommon.php'
        common.parent.mkdir(); common.write_text('<?php')
        with patch.object(u, 'run') as run, patch('sys.stdout', new=io.StringIO()):
            result = u.refresh_google(self.root / 'backup', self.root)
        self.assertEqual(result['gatekeeper_google'], 'updated')
        self.assertEqual(result['approval_worker'], 'needs_configuration')
        self.assertIn('/etc/tarasec/access.conf', result['missing_policy'])
        self.assertTrue(all(call.args[0][0] == 'php' for call in run.call_args_list))
        self.assertEqual(config.read_text(), 'existing client configuration')
        self.assertFalse((config.parent / 'access.conf').exists())
        record = {}
        u.record_feature_result(record, 'google', result, 4, 'a' * 40)
        self.assertEqual(record['status'], 'partial')
        self.assertEqual(record['installed_version'], 0)
        u.record_feature_result(record, 'google', {'approval_worker':'updated'}, 4, 'a' * 40)
        self.assertEqual(record['status'], 'completed')
        self.assertNotIn('pending_configuration', record['details'])

    def test_partial_google_result_does_not_stop_later_upgrade(self):
        u.MODE.write_text('demo\n')
        features=(('google','Google',{'node'}),('operations','Operations',{'node'}))
        partial={'pending_configuration':True,'approval_worker':'needs_configuration'}
        with patch.object(u,'existing_google_setup',return_value=True), patch.object(u,'FEATURES',features), patch.object(u,'COMMAND',self.root/'tarasec'), patch.object(u.sys.stdin,'isatty',return_value=True), patch('builtins.input',side_effect=['yes']), patch.object(u,'apply_feature',side_effect=[partial,None]) as apply, patch('sys.stdout',new=io.StringIO()):
            self.assertEqual(u.main(['upgrade','--roles','node']),0)
        self.assertEqual(apply.call_count,2)
        self.assertEqual(u.load_state()['features']['google']['status'],'partial')
        self.assertEqual(u.load_state()['features']['operations']['status'],'completed')

    def test_unattended_google_cannot_fall_back_to_new_enrollment(self):
        with patch.object(u, 'existing_google_setup', return_value=False), patch.object(u, 'backup'), patch.object(u, 'run') as run:
            with self.assertRaisesRegex(ValueError, 'explicit confirmation'):
                u.apply_feature('google', self.root / 'backup')
        run.assert_not_called()

    def test_public_key_can_be_pasted_or_read_from_server_file(self):
        key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGZha2Vmb3JwYXJzZXJ0ZXN0b25seQ== admin@acer'
        ssh = self.root / '.ssh'; ssh.mkdir()
        path = ssh / 'id_ed25519.pub'; path.write_text(key + '\n')
        self.assertEqual(u.public_key_input(key, self.root), key)
        self.assertEqual(u.public_key_input(str(path), self.root), key)
        self.assertEqual(u.public_key_input('~/.ssh/id_ed25519.pub', self.root), key)
        with self.assertRaisesRegex(ValueError, 'ON|on this server'):
            u.public_key_input('/missing/administrator.pub', self.root)
        private = ssh / 'id_ed25519'; private.write_text('-----BEGIN OPENSSH PRIVATE KEY-----\nsecret\n')
        with self.assertRaisesRegex(ValueError, 'PUBLIC'):
            u.public_key_input(str(private), self.root)

    def test_ssh_window_guidance_shows_local_nickname_and_approval_page(self):
        etc = self.root / 'etc'; etc.mkdir()
        (etc / 'tarasecfw.conf').write_text('AGENT_PUBLIC_NICKNAME="Silver Cedar"\n')
        (etc / 'tarasec-server-manager.conf').write_text('AGENT_APPROVAL_URL=https://approvals.example.org/ops/agent/api.php\n')
        output = io.StringIO()
        with patch('sys.stdout', new=output): u.feature_guidance('ssh-window', self.root)
        text = output.getvalue()
        self.assertIn('Silver Cedar', text)
        self.assertIn('https://approvals.example.org/ops/agent/', text)
        self.assertIn('Google sign-in alone does not open SSH', text)
        self.assertIn('console', text)

    def test_verification_typo_retries_without_accepting_misspelling(self):
        help_callback=Mock()
        with patch('builtins.input',side_effect=['VERIDIED','?','verified']) as prompt, patch('sys.stdout',new=io.StringIO()):
            u.confirm_verified('Confirm checks',help_callback)
        self.assertEqual(prompt.call_count,3)
        help_callback.assert_called_once()
        with patch('builtins.input',return_value='no'):
            with self.assertRaises(u.FeaturePostponed): u.confirm_verified('Confirm checks',Mock())

    def test_key_help_invalid_account_and_missing_file_can_be_retried(self):
        account=Mock(pw_dir=str(self.root),pw_uid=0,pw_gid=0)
        with patch('builtins.input',side_effect=['wrong-user','ubuntu','?','/missing/key.pub','']) as prompt, patch.object(u.pwd,'getpwnam',side_effect=[KeyError('wrong-user'),account]), patch.object(u,'run') as run, patch('sys.stdout',new=io.StringIO()) as output:
            with self.assertRaises(u.FeaturePostponed): u.install_key(self.root/'backup')
        self.assertIn('ADMINISTRATOR COMPUTER',output.getvalue())
        self.assertIn('does not exist',output.getvalue())
        self.assertIn('not found on this server',output.getvalue())
        self.assertEqual(prompt.call_count,5)
        run.assert_not_called()
        self.assertFalse((self.root/'.ssh/authorized_keys').exists())

    def test_postponing_verification_continues_later_features(self):
        u.MODE.write_text('demo\n')
        features=(('ssh-window','SSH window',{'node'}),('operations','Operations',{'node'}))
        with patch.object(u,'FEATURES',features),patch.object(u,'COMMAND',self.root/'tarasec'),patch.object(u.sys.stdin,'isatty',return_value=True),patch('builtins.input',side_effect=['yes','yes']),patch.object(u,'apply_feature',side_effect=[u.FeaturePostponed('Not confirmed'),None]) as apply,patch('sys.stdout',new=io.StringIO()):
            self.assertEqual(u.main(['upgrade','--roles','node']),0)
        self.assertEqual(apply.call_count,2)
        self.assertEqual(u.load_state()['features']['ssh-window']['status'],'postponed')
        self.assertEqual(u.load_state()['features']['operations']['status'],'completed')

    def test_mode_typo_can_be_corrected(self):
        with patch('builtins.input',side_effect=['demoo','demo']),patch('sys.stdout',new=io.StringIO()):
            self.assertEqual(u.choose_mode(),'demo')

    def test_existing_honeypot_uses_refresh_only_without_confirmation_or_policy_write(self):
        with patch.object(u,'settings',return_value={'SSH_PORT':'5822'}),patch.object(u,'existing_honeypot_setup',return_value=True),patch('builtins.input',side_effect=AssertionError('Refresh must not prompt for migration')),patch.object(u,'run') as run,patch.object(u,'set_setting') as write,patch('sys.stdout',new=io.StringIO()):
            result=u.apply_feature('honeypot',self.root/'backup')
        run.assert_called_once_with(['bash',u.REPO/'misc/setup_ssh_honeypot.sh','--refresh-only'])
        write.assert_not_called()
        self.assertEqual(result['administrator_ssh'],'unchanged')

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
            if 'declare(strict_types=1);' in text:
                self.assertLess(text.index('declare(strict_types=1);'), text.index('tarasecRequireDemo();'))
            self.assertLess(text.index('tarasecRequireDemo();'), text.find("include '../dbfunc.php'") if "include '../dbfunc.php'" in text else len(text))


if __name__ == '__main__': unittest.main()
