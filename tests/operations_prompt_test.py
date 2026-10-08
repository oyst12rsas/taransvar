import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
import unittest
from operations_prompt import build_prompt, validate_decision

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

if __name__ == '__main__':unittest.main()
