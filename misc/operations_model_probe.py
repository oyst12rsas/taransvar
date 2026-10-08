#!/usr/bin/env python3
"""Authenticated synthetic trace. Never dispatches any returned action."""
import json
from operations_agent import model, trusted
from operations_prompt import build_prompt, validate_decision


def main():
    policy = json.loads(trusted('/etc/tarasec/operations-agent.json').read_text())
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

if __name__ == '__main__': main()
