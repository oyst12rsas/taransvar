"""Evidence-backed gateway tools. Fixed commands, existing owner/quiet guards."""


def gateway_tools(policy, results, snapshot):
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
