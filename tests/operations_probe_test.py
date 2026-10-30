import sys
import json
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
import unittest
from unittest.mock import patch, Mock
import io
from contextlib import redirect_stdout
from operations_model_probe import progression_probe, connection_check

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

    def test_completed_diagnostic_gets_worker_feedback_then_command(self):
        repeat=dict(action='diagnostic',diagnostic='gateway_startup',reason='Investigate',
            task_state=dict(goal='Inspect',verified=[],pending=[],blockers=[],next_check='Revisit'))
        with patch('operations_model_probe.trusted',return_value=Mock(read_text=lambda:'')), \
             patch('operations_model_probe.model',side_effect=[repeat,self.valid()]) as model, \
             redirect_stdout(io.StringIO()) as output:
            progression_probe(dict(mode='demo',execute=True,allow_experimental_commands=True),quiet_verified=True)
        self.assertEqual(model.call_count,2)
        self.assertIn('Diagnostic already completed',model.call_args.args[1])
        self.assertIn('PASS:',output.getvalue())

class ConnectionCheckTest(unittest.TestCase):
    def run_check(self, result=None, error=None):
        def read(path): return Mock(read_text=lambda:'{}' if str(path).endswith('.json') else 'manual')
        with patch('operations_model_probe.trusted',side_effect=read),patch('operations_model_probe.model',return_value=result,side_effect=error):
            return connection_check()

    def test_gateway_assessment_is_identified_without_returning_model_text(self):
        result=self.run_check({'event_severity':3,'recommended_action':'watch','summary':'private-response'})
        self.assertEqual(result['code'],'gateway_assessment_response')
        self.assertNotIn('private-response',json.dumps(result))

    def test_valid_operations_response_passes_without_dispatching_it(self):
        result=self.run_check(dict(action='report',reason='private-reason',task_state=dict(goal='check',verified=[],pending=[],blockers=[],next_check='done')))
        self.assertTrue(result['ok']);self.assertNotIn('private-reason',json.dumps(result))

    def test_invalid_json_and_unknown_schema_are_distinct(self):
        import json
        bad=self.run_check(error=json.JSONDecodeError('private-message','private-body',0))
        self.assertEqual(bad['code'],'invalid_json')
        schema=self.run_check({'action':'private-action'})
        self.assertEqual(schema['code'],'response_schema_error')
        self.assertNotIn('private-action',json.dumps(schema))

    def test_http_credentials_and_error_details_are_not_printed(self):
        import urllib.error
        result=self.run_check(error=urllib.error.HTTPError('https://private.invalid/token',401,'private-secret',{},io.BytesIO(b'private-body')))
        self.assertEqual(result['code'],'authentication_failed');self.assertEqual(result['http_status'],401)
        self.assertNotIn('private-secret',json.dumps(result));self.assertNotIn('private-body',json.dumps(result))

    def test_wrapped_provider_quota_reaches_the_probe_without_raw_details(self):
        import urllib.error
        error=urllib.error.HTTPError('https://private.invalid/token',500,'private-secret',{},io.BytesIO(b'{"message":"429 You have no credits remaining. private-secret"}'))
        result=self.run_check(error=error)
        self.assertEqual(result['code'],'provider_quota_exhausted')
        self.assertEqual(result['http_status'],500)
        self.assertIn('credits/quota exhausted',result['message'])
        self.assertNotIn('private-secret',json.dumps(result))

    def test_rate_limit_is_not_reported_as_exhausted_credits(self):
        import urllib.error
        error=urllib.error.HTTPError('https://private.invalid',429,'private',{},io.BytesIO(b'{"error":{"code":"rate_limit_exceeded"}}'))
        result=self.run_check(error=error)
        self.assertEqual(result['code'],'rate_limited')

    def test_timeout_and_arbitrary_failure_are_safe(self):
        result=self.run_check(error=TimeoutError('private-endpoint'))
        self.assertEqual(result['code'],'model_unreachable')
        result=self.run_check(error=RuntimeError('private-secret'))
        self.assertEqual(result['code'],'probe_error')
        self.assertNotIn('private-secret',json.dumps(result))


if __name__ == '__main__':unittest.main()
