"""Explicit direct OpenAI transport with strict output schema; never dispatches actions."""
import json
import urllib.request


def decision_schema(diagnostics=None, procedures=None, tools=None):
    def obj(properties):
        return dict(type='object',properties=properties,required=list(properties),additionalProperties=False)
    string={'type':'string'}
    state=obj(dict(goal=string,verified={'type':'array','items':string},
        pending={'type':'array','items':string},blockers={'type':'array','items':string},next_check=string))
    prerequisite=obj(dict(kind={'type':'string','enum':['quiet_time','owner_input','unsupported_capability']},
        detail=string,next_check=string))
    nullable=lambda schema: {'anyOf':[schema,{'type':'null'}]}
    schema = obj(dict(action={'type':'string','enum':['report','diagnostic','procedure','command','resource','reboot','tool']},
        reason=string,task_state=state,tool=nullable(string),argv=nullable({'type':'array','items':string}),
        expected_result=nullable(string),recovery_plan=nullable(string),diagnostic=nullable(string),
        procedure=nullable(string),operation=nullable(string),target=nullable(string),
        prerequisite=nullable(prerequisite)))
    for action, choices in (('diagnostic', diagnostics), ('procedure', procedures), ('tool', tools)):
        if choices is not None:
            if choices:
                schema['properties'][action] = nullable({'type':'string','enum':list(choices)})
            else:
                schema['properties']['action']['enum'].remove(action)
                schema['properties'][action] = {'type':'null'}
    return schema


def direct_payload(policy,prompt):
    name=policy.get('openai_model')
    if not isinstance(name,str) or not name.strip():
        raise ValueError('Configure the direct OpenAI model name')
    return {'model':name,'messages':[{'role':'user','content':prompt}],
        'response_format':{'type':'json_schema','json_schema':{
            'name':'tarasec_operations_decision','strict':True,'schema':decision_schema(policy.get('_remaining_diagnostics'), policy.get('_eligible_procedures'), policy.get('_available_tools'))}}}


def parse_direct_response(body):
    content=json.loads(body)
    choice=content['choices'][0]
    if choice.get('finish_reason')!='stop' or choice['message'].get('refusal'):
        raise ValueError('Direct model response refused or incomplete')
    decision=json.loads(choice['message']['content'])
    if not isinstance(decision,dict):raise ValueError('Model must return one JSON decision')
    return {key:value for key,value in decision.items() if value is not None}


def direct_model(policy,prompt,trusted):
    payload=direct_payload(policy,prompt)
    key=trusted(policy['openai_key_file']).read_text().strip()
    if not key:raise ValueError('Direct OpenAI key is empty')
    request=urllib.request.Request('https://api.openai.com/v1/chat/completions',
        data=json.dumps(payload).encode(),headers={'Content-Type':'application/json','Authorization':'Bearer '+key})
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,*args,**kwargs):return None
    with urllib.request.build_opener(NoRedirect).open(request,timeout=90) as response:
        body=response.read(256001)
    if len(body)>256000:raise ValueError('Model response too large')
    return parse_direct_response(body)
