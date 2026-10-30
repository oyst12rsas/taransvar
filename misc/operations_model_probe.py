#!/usr/bin/env python3
"""Authenticated synthetic trace. Never dispatches any returned action."""
import json
import sys
import time
import urllib.error
# Import the updated diagnostic classifier before selecting the deployed runtime.
from operations_model_errors import read_model_http_error
if '--deployed-runtime' in sys.argv:
    sys.path.insert(0, '/usr/local/lib/tarasec-operations')
from operations_agent import model, trusted, diagnostic_feedback
from operations_prompt import build_prompt, validate_decision, progress_feedback
from operations_tools import gateway_tools, resolve_tool


def connection_check():
    """Return only bounded diagnostic labels, never model text or credentials."""
    stage = 'configuration'
    try:
        policy = json.loads(trusted('/etc/tarasec/operations-agent.json').read_text())
        policy = dict(policy, mode='inspect', task='Connection test only. Return a valid inspect decision; no action will execute.')
        manual = trusted('/usr/local/lib/tarasec-operations/AI_OPERATIONS_MANUAL.md').read_text()
        stage = 'model_request'
        decision = model(policy, build_prompt(manual, policy,
            {'integration_test_only': True}, {}, {}, {}, {}))
        stage = 'response_validation'
        if isinstance(decision, dict) and 'action' not in decision and (
                'event_severity' in decision or 'recommended_action' in decision):
            return dict(ok=False, stage=stage, code='gateway_assessment_response',
                message='Flowise returned a gateway security assessment, not an operations decision. Use an operations-capable chatflow with the existing provider.')
        validate_decision(decision)
        return dict(ok=True, stage=stage, code='verified',
            message='Model connection and operations decision protocol verified; no action executed.')
    except urllib.error.HTTPError as error:
        return dict(ok=False, stage=stage, **read_model_http_error(error))
    except (TimeoutError, urllib.error.URLError):
        return dict(ok=False, stage=stage, code='model_unreachable',
            message='Model endpoint timed out or could not be reached; inspect routing, TLS and the model service.')
    except json.JSONDecodeError:
        return dict(ok=False, stage=stage, code='invalid_json',
            message='Configuration or model response could not be parsed as JSON. If the deployed worker rejects Markdown-wrapped JSON, the response parser needs updating.')
    except (OSError, KeyError):
        return dict(ok=False, stage=stage, code='configuration_unavailable',
            message='A required policy, credential, manual or model response field is unavailable. Inspect the local configuration without sharing keys.')
    except ValueError as error:
        known = {'Decision must contain a supported action', 'Decision requires a nonempty reason',
            'task_state must be an object', 'task_state verified, pending and blockers must be lists of strings',
            'task_state goal and next_check must be strings', 'Command requires argv array',
            'Command requires expected_result and recovery_plan', 'Nested command must be an object',
            'Conflicting command layouts', 'Policy/procedure path must be root owned and not group/world writable',
            'Configure an HTTPS Flowise agent prediction URL without embedded credentials',
            'Configure the direct OpenAI model name', 'Model must return one JSON decision', 'Unknown model provider'}
        message = str(error) if str(error) in known else 'Model configuration or operations response validation failed; details withheld.'
        return dict(ok=False, stage=stage, code='response_schema_error' if stage=='response_validation' else 'configuration_or_response_error',message=message)
    except Exception:
        return dict(ok=False, stage=stage, code='probe_error',
            message='Unexpected failure in the connection test; inspect the deployed probe and runtime versions.')


def main():
    if '--connection-check' in sys.argv:
        result = connection_check()
        print(json.dumps(result) if '--json' in sys.argv else result['message'])
        if not result['ok']: raise SystemExit(1)
        return
    policy = json.loads(trusted('/etc/tarasec/operations-agent.json').read_text())
    if '--direct' in sys.argv:
        policy = json.loads(trusted('/etc/tarasec/operations-direct.json').read_text())
        if policy.get('model_provider') != 'openai':
            raise SystemExit('Direct probe requires explicitly configured OpenAI provider')
    if '--progression' in sys.argv:
        progression_probe(policy, quiet_verified="--quiet-verified" in sys.argv)
        return
    policy['task'] = ('Integration trace only; nothing will execute. Request a read-only syntax check '
        'of /home/audi/taransvar/misc/firewall.sh using /usr/bin/bash -n. '
        'Include AUDI_TRACE_API_02 and mode=0o664 in reason. Do not claim it ran.')
    evidence = {'gateway_startup': {'result': {'ExecMainStatus': '203', 'executable': {
        'path': '/home/audi/taransvar/misc/firewall.sh', 'exists': True,
        'executable_by_root': False, 'mode': '0o664'}}}}
    manual = trusted('/usr/local/lib/tarasec-operations/AI_OPERATIONS_MANUAL.md').read_text()
    decision = validate_decision(model(policy, build_prompt(manual, policy,
        {'integration_test_only': True}, evidence, {}, {}, {})))
    passed = (decision['action'] == 'command'
        and decision.get('argv') == ['/usr/bin/bash', '-n', '/home/audi/taransvar/misc/firewall.sh']
        and 'AUDI_TRACE_API_02' in decision['reason'] and '0o664' in decision['reason'])
    print('PASS: policy, synthetic evidence and command schema preserved.' if passed
          else 'FAIL: model did not preserve expected policy/evidence/action.')
    print('No returned action was executed.')
    print(json.dumps(decision, indent=2))
    if not passed:
        raise SystemExit(1)

def progression_probe(policy, quiet_verified=False):
    # Synthetic evidence only; no run/dispatch function is called.
    policy['task'] = ('Inspect Audi, investigate its failed gateway and select the next useful '
        'permitted check. Select the decision using the supplied synthetic observations as the '
        'fixture evidence. The probe never executes actions; this does not change Demo mode '
        'into inspection-only policy. Do not claim these observations describe live Audi.')
    manual = trusted('/usr/local/lib/tarasec-operations/AI_OPERATIONS_MANUAL.md').read_text()
    evidence = {'gateway_startup': {'result': {'properties': {
        'ActiveState': 'failed', 'Result': 'exit-code', 'ExecMainStatus': '203',
        'executable': {'path': '/home/audi/taransvar/misc/firewall.sh', 'exists': True,
            'executable_by_root': False, 'mode': '0o664', 'regular_file': True}}}}}
    previous = dict(goal='Inspect Audi', verified=[], pending=[],
        blockers=['tarasec-gateway.service failed; further progress blocked'], next_check='')
    snapshot = {'integration_test_only': True, 'activity': (
        dict(checked_at=time.time(), complete=True, active_demo=False, meaningful_traffic=False,
            quiet_for_seconds=max(600, int(policy.get('quiet_seconds', 300))))
        if quiet_verified else dict(complete=False, reason='Activity collector not configured'))}
    passed = False
    for attempt in range(4):
        decision = model(dict(policy, _remaining_diagnostics=[], _eligible_procedures=[],
            _available_tools=list(gateway_tools(policy,evidence,snapshot))), build_prompt(manual, policy,
            snapshot, evidence, previous, {}, {}))
        try:
            decision = validate_decision(decision)
            if decision['action'] == 'tool':
                decision = resolve_tool(decision, gateway_tools(policy,evidence,snapshot))
                decision = validate_decision(decision)
        except ValueError as error:
            snapshot['worker_feedback'] = str(error)
            print('Schema correction requested: ' + str(error))
            continue
        if decision['action'] == 'diagnostic':
            feedback = diagnostic_feedback(decision.get('diagnostic'), evidence, attempt)
            if not feedback:
                feedback = 'No diagnostics are advertised in this probe. Use the supplied completed results and choose a permitted action or concrete prerequisite.'
        else:
            feedback = progress_feedback(decision, policy, evidence, snapshot)
        if not feedback:
            # No diagnostics advertised. Accept a command or concrete prerequisite report.
            passed = (decision['action'] == 'command' or (decision['action'] == 'report'
                and not quiet_verified and isinstance(decision.get('prerequisite'), dict)))
            break
        snapshot['worker_feedback'] = feedback
    print('PASS: model advanced the synthetic task or identified a next prerequisite.' if passed
          else 'FAIL: model did not advance the synthetic task.')
    print('No returned action was executed.')
    print(json.dumps(decision, indent=2))
    if not passed:
        raise SystemExit(1)

if __name__ == '__main__': main()
