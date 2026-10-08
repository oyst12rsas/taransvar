import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
import unittest
from operations_prompt import build_prompt, validate_decision, progress_feedback

class PromptTest(unittest.TestCase):
    def decision(self):
        return dict(action='command',reason='Requesting syntax check',argv=['/usr/bin/bash','-n','/example'],
            expected_result='syntax valid',recovery_plan='read only',task_state=dict(goal='test',verified=[],pending=[],blockers=[],next_check='read result'))
    def test_demo_prompt_preserves_evidence_and_empty_registry_permission(self):
        text=build_prompt('Reference only',dict(mode='demo',execute=True,allow_experimental_commands=True),
            {},{'gateway_startup':dict(marker='AUDI_TRACE_API_02',mode='0o664')},{},{},{})
        self.assertIn('command action is ENABLED',text)
        self.assertIn('AUDI_TRACE_API_02',text)
        self.assertIn('0o664',text)
        self.assertIn('Eligible procedures: {}',text)
    def test_production_cannot_receive_demo_permission(self):
        text=build_prompt('',dict(mode='production',execute=True,allow_experimental_commands=True),{},{},{},{},{})
        self.assertIn('command action is DISABLED',text)
    def test_boolean_verified_is_rejected(self):
        value=self.decision();value['task_state']['verified']=False
        with self.assertRaises(ValueError):validate_decision(value)
    def test_correct_schema_is_accepted(self):
        validate_decision(self.decision())
    def test_command_requires_recovery(self):
        value=self.decision();value.pop('recovery_plan')
        with self.assertRaises(ValueError):validate_decision(value)

    def test_nested_command_is_normalized_without_mutating_input(self):
        value=self.decision()
        nested={field:value.pop(field) for field in ('argv','expected_result','recovery_plan')}
        value['command']=nested
        result=validate_decision(value)
        self.assertEqual(result['argv'],nested['argv'])
        self.assertNotIn('command',result)
        self.assertIn('command',value)
    def test_conflicting_command_fields_rejected(self):
        value=self.decision();value['command']={'argv':['/bin/false']}
        with self.assertRaises(ValueError):validate_decision(value)

class ProgressTest(unittest.TestCase):
    def setUp(self):
        self.policy=dict(mode='demo',execute=True,allow_experimental_commands=True)
        self.evidence={'gateway_startup':{'result':{'properties':{
            'ActiveState':'failed','executable':{'exists':True,'executable_by_root':False}}}}}
        self.report=dict(action='report',reason='Gateway failure blocks progress',task_state=dict(
            goal='Inspect',verified=[],pending=[],blockers=['Gateway failed'],next_check=''))
    def test_empty_failure_report_is_challenged(self):
        self.assertIsNotNone(progress_feedback(self.report,self.policy,self.evidence))
    def test_concrete_prerequisite_with_next_check_is_accepted(self):
        self.report['task_state']['blockers']=['Quiet-time collector not configured']
        self.report['task_state']['next_check']='Configure and validate the activity collector before mutations'
        self.report['prerequisite']=dict(kind='quiet_time',detail='Activity collector not configured',
            next_check='Configure and validate collector before mutations')
        self.assertIsNone(progress_feedback(self.report,self.policy,self.evidence))
    def test_audi_vague_next_check_is_rejected_even_with_pending(self):
        self.report['task_state']['next_check']='After diagnosing the log sizes.'
        self.report['task_state']['pending']=['Investigate gateway']
        self.assertIsNotNone(progress_feedback(self.report,self.policy,self.evidence))
    def test_incomplete_or_unknown_prerequisite_is_rejected(self):
        for prerequisite in ({'kind':'quiet_time'},dict(kind='failed_gateway',detail='failed',next_check='Investigate')):
            self.report['prerequisite']=prerequisite
            self.assertIsNotNone(progress_feedback(self.report,self.policy,self.evidence))
    def test_production_and_inspection_reports_are_accepted(self):
        for field,value in [('mode','production'),('execute',False),('allow_experimental_commands',False)]:
            policy=dict(self.policy);policy[field]=value
            self.assertIsNone(progress_feedback(self.report,policy,self.evidence))
    def test_no_failure_or_missing_evidence_does_not_trigger(self):
        self.assertIsNone(progress_feedback(self.report,self.policy,{}))
        self.evidence['gateway_startup']['result']['properties']['ActiveState']='active'
        self.assertIsNone(progress_feedback(self.report,self.policy,self.evidence))

if __name__ == '__main__':unittest.main()
