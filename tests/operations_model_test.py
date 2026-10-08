import sys,json
from pathlib import Path
sys.path.insert(0,str(Path(__file__).parents[1]/'misc'))
import unittest
from unittest.mock import patch
from operations_model import decision_schema,direct_payload,parse_direct_response
from operations_agent import model

class DirectTest(unittest.TestCase):
    def test_strict_schema_has_required_fields_and_typed_task_state(self):
        schema=decision_schema()
        self.assertEqual(set(schema['required']),set(schema['properties']))
        self.assertFalse(schema['additionalProperties'])
        self.assertEqual(schema['properties']['task_state']['properties']['verified']['type'],'array')
    def test_same_prompt_and_explicit_model_in_strict_request(self):
        result=direct_payload({'openai_model':'configured-model'},'SAME_PROMPT')
        self.assertEqual(result['messages'][0]['content'],'SAME_PROMPT')
        self.assertEqual(result['model'],'configured-model')
        self.assertTrue(result['response_format']['json_schema']['strict'])
    def test_refusal_and_incomplete_are_not_decisions(self):
        for reason,refusal in (('length',None),('stop','No')):
            with self.assertRaises(ValueError):parse_direct_response(json.dumps({'choices':[{
                'finish_reason':reason,'message':{'refusal':refusal,'content':'{}'}}]}))
    def test_unused_null_fields_removed(self):
        result=parse_direct_response(json.dumps({'choices':[{'finish_reason':'stop','message':{
            'content':json.dumps(dict(action='report',argv=None,reason='done'))}}]}))
        self.assertNotIn('argv',result)
    def test_explicit_provider_dispatch_no_flowise_fallback(self):
        with patch('operations_model.direct_model',return_value={'action':'report'}) as direct:
            self.assertEqual(model({'model_provider':'openai'},'prompt'),{'action':'report'})
            self.assertEqual(direct.call_args.args[:2],({'model_provider':'openai'},'prompt'))
        with self.assertRaises(ValueError):model({'model_provider':'unknown'},'prompt')

    def test_empty_catalog_cannot_generate_diagnostic_or_procedure_action(self):
        schema=decision_schema([],[])
        self.assertNotIn('diagnostic',schema['properties']['action']['enum'])
        self.assertNotIn('procedure',schema['properties']['action']['enum'])
        self.assertEqual(schema['properties']['diagnostic'],{'type':'null'})
    def test_only_remaining_diagnostic_names_are_allowed(self):
        schema=decision_schema(['large_logs'],[])
        self.assertEqual(schema['properties']['diagnostic']['anyOf'][0]['enum'],['large_logs'])
        self.assertNotIn('gateway_startup',json.dumps(schema))

if __name__=='__main__':unittest.main()
