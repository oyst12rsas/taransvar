import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
import unittest
from unittest.mock import patch, Mock
import io
from contextlib import redirect_stdout
from operations_model_probe import progression_probe

class ProbeTest(unittest.TestCase):
    def valid(self):
        return dict(action='command',reason='Read-only syntax check',argv=['/usr/bin/bash','-n','/example'],
            expected_result='Valid syntax',recovery_plan='Read only',task_state=dict(
                goal='Inspect',verified=[],pending=[],blockers=[],next_check='Read result'))
    def test_schema_error_retries_with_feedback_then_passes(self):
        invalid=self.valid();invalid['task_state']['next_check']=None
        with patch('operations_model_probe.trusted',return_value=Mock(read_text=lambda:'')), \
             patch('operations_model_probe.model',side_effect=[invalid,self.valid()]) as model, \
             redirect_stdout(io.StringIO()) as output:
            progression_probe(dict(mode='demo',execute=True,allow_experimental_commands=True))
        self.assertEqual(model.call_count,2)
        self.assertIn('task_state goal and next_check must be strings',model.call_args.args[1])
        self.assertIn('PASS:',output.getvalue())
    def test_exhausted_schema_retries_print_failure_without_traceback(self):
        invalid=self.valid();invalid['task_state']['goal']=False
        with patch('operations_model_probe.trusted',return_value=Mock(read_text=lambda:'')), \
             patch('operations_model_probe.model',return_value=invalid) as model, \
             redirect_stdout(io.StringIO()) as output:
            with self.assertRaises(SystemExit) as error:
                progression_probe(dict(mode='demo',execute=True,allow_experimental_commands=True))
        self.assertEqual(error.exception.code,1)
        self.assertEqual(model.call_count,4)
        self.assertIn('FAIL:',output.getvalue())
        self.assertIn('"goal": false',output.getvalue())

    def test_quiet_fixture_does_not_accept_prerequisite_report(self):
        report=dict(action='report',reason='Need collector',prerequisite=dict(kind='quiet_time',
            detail='Collector missing',next_check='Configure collector'),task_state=dict(
            goal='Inspect',verified=[],pending=[],blockers=[],next_check='Configure collector'))
        with patch('operations_model_probe.trusted',return_value=Mock(read_text=lambda:'')), \
             patch('operations_model_probe.model',return_value=report), redirect_stdout(io.StringIO()):
            with self.assertRaises(SystemExit):
                progression_probe(dict(mode='demo',execute=True,allow_experimental_commands=True),quiet_verified=True)

if __name__ == '__main__':unittest.main()
