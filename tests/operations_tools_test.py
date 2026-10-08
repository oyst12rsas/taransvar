import sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).parents[1]/'misc'))
import unittest
from operations_tools import gateway_tools,resolve_tool
from operations_model import decision_schema
from operations_prompt import progress_feedback

class ToolTest(unittest.TestCase):
    def setUp(self):
        self.policy=dict(mode='demo',execute=True,allow_experimental_commands=True)
        self.executable=dict(path='/example',exists=True,regular_file=True,executable_by_root=False,
            file_identity=dict(device=1,inode=2,mtime_ns=3,size=4))
        self.results={'gateway_startup':{'result':{'properties':dict(ActiveState='failed',executable=self.executable)}}}
    def syntax_result(self):
        return {'last_action_result':dict(argv=['/usr/bin/bash','-n','/example'],exit_code=0,
            read_only=True,startup_file_identity=self.executable['file_identity'])}
    def test_known_failure_offers_concrete_read_only_tool(self):
        tools=gateway_tools(self.policy,self.results,{})
        self.assertEqual(list(tools),['gateway_syntax_check'])
        self.assertFalse(tools['gateway_syntax_check']['requires_quiet'])
    def test_mutation_requires_same_file_successful_syntax_result(self):
        tools=gateway_tools(self.policy,self.results,self.syntax_result())
        self.assertEqual(list(tools),['gateway_enable_execution'])
        self.assertTrue(tools['gateway_enable_execution']['requires_quiet'])
        snapshot=self.syntax_result();snapshot['last_action_result']['startup_file_identity']={'mtime_ns':9}
        self.assertEqual(list(gateway_tools(self.policy,self.results,snapshot)),['gateway_syntax_check'])
    def test_production_disabled_or_missing_evidence_has_no_tools(self):
        self.assertEqual(gateway_tools(self.policy,{},{}),{})
        self.assertEqual(gateway_tools(dict(self.policy,mode='production'),self.results,{}),{})
        self.assertEqual(gateway_tools(dict(self.policy,execute=False),self.results,{}),{})
    def test_resolver_ignores_model_argv_and_rejects_unknown_name(self):
        tools=gateway_tools(self.policy,self.results,{})
        value=dict(tool='gateway_syntax_check',reason='Inspect',task_state={},argv=['/sbin/reboot'])
        self.assertEqual(resolve_tool(value,tools)['argv'],['/usr/bin/bash','-n','/example'])
        value['tool']='invented'
        with self.assertRaises(ValueError):resolve_tool(value,tools)
    def test_direct_schema_has_only_current_tool_names(self):
        schema=decision_schema([],[],['gateway_syntax_check'])
        self.assertEqual(schema['properties']['tool']['anyOf'][0]['enum'],['gateway_syntax_check'])
    def test_start_requires_executable_and_prior_syntax_verification(self):
        self.executable['executable_by_root']=True
        self.assertEqual(list(gateway_tools(self.policy,self.results,self.syntax_result())),['gateway_start'])

    def test_authorization_blocker_cannot_override_available_read_only_tool(self):
        decision=dict(action='report',task_state=dict(goal='Inspect',verified=[],pending=[],blockers=[],next_check='Ask'),
            prerequisite=dict(kind='owner_input',detail='Need access authorization',next_check='Ask owner'))
        self.assertIn('already authorized',progress_feedback(decision,self.policy,self.results,{}))

if __name__=='__main__':unittest.main()
