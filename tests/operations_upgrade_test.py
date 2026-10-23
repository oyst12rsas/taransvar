import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
import json
import tempfile
import unittest
from unittest.mock import Mock, patch
import operations_upgrade as upgrade
from upgrade_release import deployment_status, automatic_features, upgrade_risk, demo_automatic


class UpgradeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.notice = self.root / 'notice.json'
        self.software = self.root / 'software.json'
        self.mode = self.root / 'mode'
        self.mode.write_text('demo\n')
        self.notice.write_text(json.dumps({'checked_at':10000, 'catalog_status':'ok', 'pending':[
            {'id':'fix', 'severity':'security', 'minimum_version':2, 'note':'Compatibility/security fix'}]}))
        self.software.write_text(json.dumps({'automatic_enabled':True}))
        self.state = {}
        self.policy = {'mode':'demo','quiet_probe':'/probe'}
        self.run = Mock(side_effect=[{'exit_code':0,'output':'{}'}, {'exit_code':0,'output':'ok'}])
        self.quiet = Mock(return_value=True)
        self.saved, self.audit = Mock(), Mock()
        self.patches = [patch.object(upgrade, 'NOTICE', self.notice), patch.object(upgrade, 'SOFTWARE', self.software), patch.object(upgrade, 'MODE', self.mode),
                        patch.object(upgrade, 'session_activity', return_value={'blockers':[]})]
        for p in self.patches: p.start()

    def tearDown(self):
        for p in self.patches: p.stop()
        self.tmp.cleanup()

    def maintain(self):
        return upgrade.maintain(self.state, self.policy, 10000, self.run, lambda p: Path(p), self.quiet, self.saved, self.audit)

    def test_advisory_cannot_supply_command_or_target(self):
        data = json.loads(self.notice.read_text()); data['pending'][0].update(argv=['evil'], source_url='evil')
        self.notice.write_text(json.dumps(data))
        self.assertTrue(self.maintain())
        self.assertEqual(self.run.call_args.args[0], ['/usr/local/sbin/tarasec', 'upgrade', '--automatic'])
        self.assertEqual(self.state['upgrade']['status'], 'verification_pending')
        self.assertNotEqual(self.state['issues']['upgrade:fix']['status'], 'solved')

    def test_stale_or_invalid_receipt_cannot_execute_or_clear(self):
        self.notice.write_text(json.dumps({'checked_at':1,'catalog_status':'ok','pending':[]}))
        self.assertFalse(self.maintain()); self.run.assert_not_called()
        self.assertEqual(self.state['upgrade']['status'], 'unknown')

    def test_production_never_runs_automatically_even_with_old_opt_in(self):
        for agent_mode, node_mode in [('production','production'), ('demo','production'), ('production','demo')]:
            with self.subTest(agent_mode=agent_mode, node_mode=node_mode):
                self.policy['mode'] = agent_mode
                self.mode.write_text(node_mode)
                self.assertFalse(self.maintain()); self.run.assert_not_called()
                self.assertEqual(self.state['upgrade']['status'], 'awaiting_confirmation')

    def test_demo_mode_is_authorization_even_when_legacy_opt_in_is_false(self):
        self.software.write_text('{"automatic_enabled":false}')
        self.assertTrue(self.maintain())

    def test_inspect_mode_blocks_automatic_upgrades(self):
        self.policy['mode'] = 'inspect'
        self.assertFalse(self.maintain()); self.run.assert_not_called()

    def test_busy_activity_blocks_execution(self):
        self.quiet.return_value = False
        self.assertFalse(self.maintain()); self.assertEqual(self.run.call_count, 1)
        self.assertIn('quiet', self.state['upgrade']['reason'])

    def test_interactive_session_blocks_execution(self):
        with patch.object(upgrade, 'session_activity', return_value={'blockers':['active']}):
            self.assertFalse(self.maintain()); self.assertEqual(self.run.call_count, 1)

    def test_budget_blocks_repeated_execution(self):
        self.state['upgrade_attempts'] = {'day':0,'count':3}
        self.assertFalse(self.maintain()); self.run.assert_not_called()

    def test_next_fresh_current_report_closes_issue(self):
        self.maintain()
        self.notice.write_text(json.dumps({'checked_at':10000,'catalog_status':'ok','pending':[]}))
        self.assertFalse(self.maintain())
        self.assertEqual(self.state['issues']['upgrade:fix']['status'], 'solved')

    def test_release_requires_every_applicable_component(self):
        release = {'version':3,'components':{'node':['runtime','taralink']}}
        state = {'roles':['node'],'features':{'runtime':{'installed_version':3},'taralink':{'installed_version':2}}}
        self.assertEqual(deployment_status(state, release)['version'], 2)
        state['features']['taralink']['installed_version'] = 0
        self.assertEqual(deployment_status(state, release)['version'], 0)

    def test_demo_includes_new_bounded_features_but_honors_never(self):
        state = {'mode':'demo', 'automatic_enabled':False, 'features':{
            'runtime':{'choice':'no','installed_version':0},
            'gatekeeper':{'choice':'never','installed_version':1},
            'ssh-window':{'choice':'yes','installed_version':1}}}
        self.assertEqual(automatic_features(state, ['runtime','gatekeeper','operations','google','ssh-window']), ['runtime','operations'])
        state['mode'] = 'production'
        state['automatic_enabled'] = True
        with self.assertRaises(ValueError): automatic_features(state, ['runtime'])

    def test_risk_labels_fail_closed_and_cannot_lower_access_risk(self):
        self.assertEqual(upgrade_risk('unclassified'), 'high')
        self.assertFalse(demo_automatic('ssh-window', 'demo', {'risks':{'ssh-window':'none'}}))
        self.assertFalse(demo_automatic('runtime', 'demo', {'risks':{'runtime':'high'}}))
        self.assertFalse(demo_automatic('runtime', 'demo', {'risks':{'runtime':'invalid'}}))
        self.assertTrue(demo_automatic('database', 'demo'))
        self.assertFalse(demo_automatic('runtime', 'production'))

    def test_high_risk_database_blocks_dependents_until_manually_installed(self):
        state = {'mode':'demo','features':{}}
        release = {'version':2,'risks':{'database':'high'}}
        names=['database','runtime','gatekeeper','operations']
        self.assertEqual(automatic_features(state, names, release=release), [])
        state['features']['database']={'installed_version':2}
        self.assertEqual(automatic_features(state, names, release=release), ['runtime','gatekeeper','operations'])


if __name__ == '__main__': unittest.main()
