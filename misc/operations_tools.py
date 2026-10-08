"""Evidence-backed gateway tools. Fixed commands, existing owner/quiet guards."""


def _gateway_tools(policy, results, snapshot):
    if (policy.get('mode')!='demo' or policy.get('execute') is not True
        or policy.get('allow_experimental_commands') is not True):
        return {}
    properties=results.get('gateway_startup',{}).get('result',{}).get('properties',{})
    executable=properties.get('executable',{})
    path=executable.get('path')
    if (properties.get('ActiveState')!='failed' or executable.get('exists') is not True
        or executable.get('regular_file') is not True or not isinstance(path,str) or not path.startswith('/')):
        return {}
    last=snapshot.get('last_action_result') or {}
    syntax_verified=(last.get('argv') in (['/usr/bin/bash','-n',path],['/bin/bash','-n',path])
        and last.get('exit_code')==0 and last.get('read_only') is True
        and isinstance(executable.get('file_identity'),dict) and bool(executable['file_identity'])
        and last.get('startup_file_identity')==executable['file_identity'])
    if not syntax_verified:
        return {'gateway_syntax_check':dict(argv=['/usr/bin/bash','-n',path],requires_quiet=False,
            expected_result='Exit zero establishes shell syntax only; it does not execute the firewall.',
            recovery_plan='Read-only check; inspect reported syntax errors before modifying the script.')}
    if executable.get('executable_by_root') is False:
        return {'gateway_enable_execution':dict(argv=['/bin/chmod','u+x',path],requires_quiet=True,
            expected_result='Startup file gains owner execute permission; verify fresh metadata afterward.',
            recovery_plan='Record original mode before action; restore that mode if permission change needs rollback.')}
    return {'gateway_start':dict(argv=['/usr/bin/systemctl','start','tarasec-gateway.service'],requires_quiet=True,
        expected_result='Service starts; verify service status and forwarding separately before claiming recovery.',
        recovery_plan='Preserve recovery console and current firewall configuration; inspect journal if startup fails.')}


def resolve_tool(decision, catalog):
    name=decision.get('tool')
    if name not in catalog:
        raise ValueError('Tool not currently available')
    tool=catalog[name]
    return dict(action='command',reason=decision['reason'],task_state=decision['task_state'],
        argv=tool['argv'],expected_result=tool['expected_result'],recovery_plan=tool['recovery_plan'])


def gateway_tools(policy, results, snapshot):
    tools = _gateway_tools(policy, results, snapshot)
    if (policy.get('mode') != 'demo' or policy.get('execute') is not True
            or policy.get('allow_experimental_commands') is not True
            or 'retention' not in str(policy.get('task', '')).lower()):
        return tools
    evidence = results.get('logging_policy', {}).get('result', {}).get('journald_effective', {})
    text = evidence.get('output', '')
    explicit_limit = any(line.strip().startswith('SystemMaxUse=') for line in text.splitlines())
    if evidence.get('exit_code') == 0 and not explicit_limit:
        tools['journal_configure_limit'] = dict(
            argv=['/usr/bin/python3', '/usr/local/lib/tarasec-operations/configure_operations_journal.py'],
            requires_quiet=True,
            expected_result='Install 1G journal bound with 2G keep-free, restart journald, display effective config. Verify logging health next run; no explicit vacuum or DB changes.',
            recovery_plan='Remove only the newly created 60-tarasec-operations.conf drop-in and restart journald if needed. Existing differing owner configuration is preserved.')
    return tools
