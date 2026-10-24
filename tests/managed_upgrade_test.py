import copy
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import types
import unittest
from unittest.mock import Mock, patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'misc'))
import managed_upgrade as m

class ManagedTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.root=Path(self.tmp.name);(self.root/'misc').mkdir()
        (self.root/'misc/test.txt').write_text('content')
        self.policy=dict(roles=['node'],admin_user='admin',excluded_features=[])
        self.release=dict(version=8,managed_tasks={name:'requires_manual_installation' for name in m.CORE})
        self.release['managed_tasks']['runtime']='ready'
        self.state=dict(roles=['node'],features={},history=[])
    def write_release(self): (self.root/'release.json').write_text(json.dumps(self.release))
    def test_task_states_and_test_machine_restriction(self):
        self.release['managed_tasks'].update(operations='being_tested',tarakernel='requires_local_testing')
        fleet,skipped=m.feature_plan(self.policy,self.release,'fleet')
        self.assertEqual(fleet,['runtime']);self.assertIn(dict(component='operations',state='being_tested'),skipped)
        test,_=m.feature_plan(self.policy,self.release,'canary')
        self.assertIn('operations',test);self.assertNotIn('tarakernel',test)
    def test_unknown_tasks_cannot_become_commands(self):
        self.release['managed_tasks']['run-arbitrary-command']='ready'
        selected,skipped=m.feature_plan(self.policy,self.release,'canary')
        self.assertNotIn('run-arbitrary-command',selected)
        self.assertIn(dict(component='run-arbitrary-command',state='requires_manual_installation'),skipped)
        self.release['managed_tasks']['runtime']='unknown'
        with self.assertRaises(ValueError): m.feature_plan(self.policy,self.release)
    def fake_standard(self):
        u=types.ModuleType('standard_upgrade')
        u.load_state=lambda:copy.deepcopy(self.state)
        u.save=lambda state:self.state.update(copy.deepcopy(state))
        return u
    def execute(self,health,apply):
        self.write_release();u=self.fake_standard()
        with patch.dict(sys.modules,standard_upgrade=u),patch.object(m,'STATE',self.root/'state.json'),patch.object(m,'INSTALL',self.root/'not-installed'),patch.object(m,'record'),patch.object(m,'healthy',side_effect=health),patch.object(m,'apply_component',side_effect=apply):
            return m.converge(self.policy,'a'*40,1,self.root,'canary')
    def test_missing_ready_task_installs_once_then_working_task_is_left_alone(self):
        applied=[];installed=[False]
        def health(name,*args): return name=='runtime' and installed[0]
        def apply(name,*args): applied.append(name);installed[0]=True
        result=self.execute(health,apply)
        self.assertEqual(result['status'],'verified');self.assertEqual(applied,['runtime'])
        self.assertTrue(result['withheld_tasks'])
        self.execute(health,apply)
        self.assertEqual(applied,['runtime'])
    def test_failed_check_stops_remaining_tasks_and_records_revision(self):
        self.release['managed_tasks']['taralink']='ready'
        apply=Mock()
        result=self.execute(lambda *args:False,apply)
        self.assertEqual(result['status'],'failed');self.assertEqual(result['revision'],'a'*40)
        self.assertEqual(apply.call_count,1)
    def test_missing_prerequisite_never_prompts_or_pretends_success(self):
        def apply(*args): raise m.Prerequisite('One-time setup needed')
        with patch('builtins.input',side_effect=AssertionError('No terminal prompts')):
            result=self.execute(lambda *args:False,apply)
        self.assertEqual(result['status'],'pending');self.assertTrue(result['pending'])
    def test_role_assignment_cannot_change_during_upgrade(self):
        self.state['roles']=['gateway'];self.write_release()
        with patch.dict(sys.modules,standard_upgrade=self.fake_standard()):
            with self.assertRaisesRegex(ValueError,'Assigned roles changed'):
                m.converge(self.policy,'a'*40,1,self.root)
    def test_fingerprint_ignores_unrelated_component_changes(self):
        original=m.fingerprint(self.root,'runtime')
        (self.root/'unrelated.txt').write_text('unrelated')
        self.assertEqual(m.fingerprint(self.root,'runtime'),original)
        (self.root/'misc/test.txt').write_text('changed')
        self.assertNotEqual(m.fingerprint(self.root,'runtime'),original)
    def test_scheduler_notice_preserves_an_existing_failed_candidate(self):
        with patch.object(m,'STATE',self.root/'private/state.json'):
            m.record(dict(status='failed',revision='a'*40,generation=1))
            m.scheduler_notice('waiting','Paused')
            result=json.loads(m.STATE.read_text())['managed_upgrade']
            self.assertEqual(result['status'],'failed');self.assertEqual(result['revision'],'a'*40)
            self.assertEqual(result['scheduler']['status'],'waiting')
    def test_post_deployment_health_failure_is_latched(self):
        result=dict(status='verified',revision='a'*40,generation=1)
        state=self.root/'state.json';state.write_text(json.dumps({'managed_upgrade':result}))
        target=dict(revision='a'*40,generation=1,features=['runtime'])
        def save(value): state.write_text(json.dumps({'managed_upgrade':value}))
        with patch.dict(sys.modules,standard_upgrade=self.fake_standard()),patch.object(m,'STATE',state),patch.object(m,'private_json',return_value=target),patch.object(m,'healthy',return_value=False),patch.object(m,'record',side_effect=save):
            self.assertEqual(m.check_health(self.policy),1)
            self.assertEqual(json.loads(state.read_text())['managed_upgrade']['status'],'failed')
            self.assertEqual(m.check_health(self.policy),0)
            self.assertEqual(json.loads(state.read_text())['managed_upgrade']['status'],'failed')

    def test_enrolled_upgrade_has_no_role_or_component_prompts(self):
        import standard_upgrade as u
        policy=self.root/'managed.json';policy.write_text('{}')
        state=dict(roles=['node'],features={},history=[])
        with patch.object(m,'POLICY',policy),patch.object(u,'load_state',return_value=state),patch.object(u,'read_mode',return_value='demo'),patch.object(u,'existing_google_setup',return_value=True),patch.object(u,'detect_roles',side_effect=AssertionError('Assigned roles are immutable')),patch('builtins.input',side_effect=AssertionError('No recurring questions')),patch.object(u.subprocess,'run',return_value=Mock(returncode=0)) as execute:
            self.assertEqual(u.main(['upgrade']),0)
            execute.assert_called_once_with(['python3','/usr/local/lib/tarasec-upgrade/managed_upgrade.py','daily'])
            with self.assertRaises(SystemExit):u.main(['upgrade','--roles','gateway'])
    def test_daily_enrollment_reuses_the_existing_role_assignment(self):
        import standard_upgrade as u
        state=dict(roles=['gateway'],features={},history=[])
        with patch.object(m,'POLICY',self.root/'not-enrolled'),patch.object(m,'enroll') as enroll,patch.object(u,'load_state',return_value=state),patch.object(u,'save'),patch.object(u,'read_mode',return_value='demo'),patch.object(u,'existing_google_setup',return_value=True),patch.object(u.os,'geteuid',return_value=0),patch('builtins.input',side_effect=AssertionError('No recurring questions')):
            self.assertEqual(u.main(['upgrade','--enable-daily']),0)
            self.assertEqual(enroll.call_args.args[1],['gateway'])

    def test_archive_rejects_unrelated_source_revision(self):
        with patch.object(m,'git',side_effect=RuntimeError('Not an ancestor')):
            with self.assertRaises(RuntimeError):m.export_release(self.policy,'b'*40,self.root)
        with self.assertRaises(ValueError):m.export_release(self.policy,'not a commit',self.root)
    def test_pause_after_download_prevents_execution(self):
        first=dict(allowed=True,target_revision='a'*40,generation=1,cohort='canary')
        git=[Mock(stdout='https://github.com/oyst12rsas/taransvar.git'),Mock(),Mock(stdout='a'*40),Mock(stdout='{"version":8}')]
        with patch.object(m,'STATE',self.root/'missing.json'),patch.object(m,'git',side_effect=git),patch.object(m,'gate',side_effect=[first,dict(allowed=False,reason='Paused')]),patch.object(m,'quiet',return_value=True),patch.object(m,'export_release'),patch.object(m,'scheduler_notice'),patch.object(m,'run') as execute:
            self.assertEqual(m.daily(self.policy),0)
            execute.assert_not_called()

    def test_gate_wait_does_not_execute_release_or_overwrite_test_receipt(self):
        with patch.object(m,'STATE',self.root/'none.json'),patch.object(m,'git',side_effect=[Mock(stdout='https://github.com/oyst12rsas/taransvar.git'),Mock(),Mock(stdout='a'*40),Mock(stdout='{"version":8}')]),patch.object(m,'gate',return_value=dict(allowed=False,reason='Paused')),patch.object(m,'scheduler_notice') as notice,patch.object(m,'export_release') as export:
            self.assertEqual(m.daily(self.policy),0);notice.assert_called_once_with('waiting','Paused');export.assert_not_called()

if __name__=='__main__':unittest.main()
