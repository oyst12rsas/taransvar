#!/usr/bin/env python3
"""Pilot model-driven operations worker; executes only locally approved procedures."""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time
import urllib.request
import urllib.parse
import urllib.error
from operations_diagnostics import CATALOG, diagnose
from operations_actions import validate_command, pressure, validate_resource, perform_resource, read_only_command
from operations_prompt import build_prompt, validate_decision, progress_feedback, completed_check_feedback
from operations_tools import gateway_tools, resolve_tool


def save(path, value):
    result = value.get('last_action_result')
    if result:
        history = value.setdefault('action_history', [])
        fingerprint = hashlib.sha256(json.dumps(result, sort_keys=True).encode()).hexdigest()
        if value.get('last_result_fingerprint') != fingerprint:
            entry = dict(result, recorded_at=time.time(), boot_id=value.get('boot_id'))
            if 'output' in entry:
                entry['output'] = entry['output'][:2000]
            history.append(entry)
            del history[:-12]
            value['last_result_fingerprint'] = fingerprint
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_suffix('.tmp')
    with temporary.open('w') as stream:
        json.dump(value, stream)
        stream.flush()
        os.fsync(stream.fileno())
    os.chmod(temporary, 0o600)
    temporary.replace(path)


def trusted(path):
    path = Path(path)
    for item in [path, *path.parents]:
        stat = item.stat()
        if stat.st_uid != 0 or stat.st_mode & 0o022:
            raise ValueError('Policy/procedure path must be root owned and not group/world writable')
    return path


def quiet(state, sample, now, period):
    """All relevant traffic counts; observer must explicitly exclude reporting only."""
    stamp = sample.get('checked_at')
    valid = (isinstance(stamp, (int, float)) and not isinstance(stamp, bool)
             and 0 <= now - stamp <= 90 and sample.get('complete') is True
             and isinstance(sample.get('active_demo'), bool)
             and isinstance(sample.get('meaningful_traffic'), bool))
    idle = valid and not sample['active_demo'] and not sample['meaningful_traffic']
    previous = state.get('last_activity_check', 0)
    if now - previous > 90 or now < previous:
        state.pop('quiet_since', None)
    state['last_activity_check'] = now
    if not idle:
        state.pop('quiet_since', None)
        return False
    # A separate continuous observer avoids tying coverage to model-call timing.
    duration = sample.get('quiet_for_seconds')
    if isinstance(duration, (int, float)) and not isinstance(duration, bool):
        return duration >= period
    state.setdefault('quiet_since', now)
    return now - state['quiet_since'] >= period


def eligible(entry, policy, system):
    evidence = entry.get('validation', {})
    return (policy.get('mode') == 'demo' and policy.get('execute') is True
            and evidence.get('readiness') == 'deployment_tested'
            and evidence.get('platform') == system
            and evidence.get('commit') == policy.get('target_commit')
            and len(evidence.get('commit', '')) == 40
            and all(c in '0123456789abcdef' for c in evidence.get('commit', ''))
            and bool(evidence.get('tested_at')) and bool(evidence.get('evidence')))


def run(argv, timeout=60):
    # Drain stdout continuously while retaining a bounded prefix. No disk spool.
    import signal
    import threading
    captured = bytearray()
    proc = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                            start_new_session=True)
    def drain():
        while True:
            chunk = proc.stdout.read(4096)
            if not chunk:
                break
            captured.extend(chunk[:max(0, 16000 - len(captured))])
    reader = threading.Thread(target=drain, daemon=True)
    reader.start()
    try:
        code = proc.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        os.killpg(proc.pid, signal.SIGKILL)
        proc.wait()
        code = 124
    reader.join(timeout=1)
    if reader.is_alive():
        # A detached descendant may retain the pipe after its parent exits.
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        reader.join(timeout=1)
    if not reader.is_alive():
        proc.stdout.close()
    return {'exit_code': code, 'output': bytes(captured).decode('utf-8', errors='replace')}


def model(policy, prompt):
    provider = policy.get('model_provider', 'flowise')
    if provider == 'openai':
        from operations_model import direct_model
        return direct_model(policy, prompt, trusted)
    if provider != 'flowise':
        raise ValueError('Unknown model provider')
    url = policy.get('model_url', '')
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password:
        raise ValueError('Configure an HTTPS Flowise agent prediction URL without embedded credentials')
    key = trusted(policy['model_key_file']).read_text().strip()
    request = urllib.request.Request(url, data=json.dumps({'question': prompt}).encode(),
        headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + key})
    # Do not follow redirects with the bearer credential.
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    with urllib.request.build_opener(NoRedirect).open(request, timeout=max(30, min(180, int(policy.get("model_timeout_seconds", 180))))) as response:
        body = response.read(256001)
    if len(body) > 256000:
        raise ValueError('Model response too large')
    content = json.loads(body)
    answer = content.get('text', content.get('answer', ''))
    decision = json.loads(answer)
    if not isinstance(decision, dict):
        raise ValueError('Model must return one JSON decision')
    return decision


SAFE_ERRORS = frozenset({'Unknown model provider', 'Configure the direct OpenAI model name', 'Direct model response refused or incomplete', 'Direct OpenAI key is empty', 'Interrupted command requires owner reconciliation before more commands', 'Symlink log paths are prohibited', 'Assistance adapter not configured', 'Disposable log must be a regular file', 'Configure an HTTPS Flowise agent prediction URL without embedded credentials', 'Policy/procedure path must be root owned and not group/world writable', 'Experimental commands require explicit demo authorization', 'Invalid service name', 'Use the separately governed reboot action', 'Invalid or repeated diagnostic request', 'Resource action requires enabled owner policy and measured pressure', 'Service is not designated nonessential', 'Approved procedure changed', 'Disposable logs must be explicit paths under /var/log', 'Command must be a bounded argv array with an absolute executable', 'Log is not explicitly disposable', 'Log changed type', 'Task state must be an object', 'Model must return one JSON decision', 'Procedure lacks matching deployment-test evidence', 'Daily reboot limit reached', 'Unknown model action', 'Command requires reason, expected result and recovery plan', 'Local policy prohibits autonomous mutation', 'Unknown resource operation', 'Reboot disabled by local owner policy', 'Model response too large'})

def safe_error(error):
    if type(error) is ValueError and str(error) in SAFE_ERRORS:
        return str(error)
    if isinstance(error, json.JSONDecodeError):
        return "Model or local JSON could not be parsed; inspect format without sharing credentials"
    if isinstance(error, urllib.error.HTTPError):
        return "Model endpoint returned HTTP " + str(error.code)
    return "Unclassified " + type(error).__name__ + "; inspect local prerequisites"


def recent_diagnostics(state, now, boot):
    """Reuse bounded recent observations only until a command/action or reboot intervenes."""
    if (state.get('boot_id') != boot or state.get('status') not in
            ('reported', 'model_stalled', 'deferred_activity_or_unknown')):
        return {}
    results = state.get('diagnostics', {})
    if not isinstance(results, dict):
        return {}
    return {name: result for name, result in results.items()
            if name in CATALOG and isinstance(result, dict)
            and isinstance(result.get('checked_at'), (int, float))
            and not isinstance(result['checked_at'], bool)
            and 0 <= now - result['checked_at'] <= 300}


def remaining_diagnostics(results, round_number):
    return {name: description for name, description in CATALOG.items()
            if name not in results} if round_number < 3 else {}


def diagnostic_feedback(name, results, round_number):
    if not isinstance(name, str) or name not in CATALOG:
        return 'Unknown diagnostic name; choose an exact name from the remaining catalog.'
    if name in results:
        return 'Diagnostic already completed; use its supplied result. Do not request it again.'
    if round_number >= 3:
        return 'Diagnostic budget exhausted; assess supplied evidence and choose a permitted action or report.'
    return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', default='/etc/tarasec/operations-agent.json')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise SystemExit('Run as root')
    policy = json.loads(trusted(args.config).read_text())
    directory = Path('/var/lib/tarasec-operations')
    directory.mkdir(mode=0o700, exist_ok=True)
    lock = (directory / 'lock').open('w')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        return
    path = directory / 'state.json'
    state = json.loads(path.read_text()) if path.exists() else {}
    now = time.time()
    boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    cached_diagnostics = recent_diagnostics(state, now, boot)
    if state.get('boot_id') != boot:
        state.pop('quiet_since', None)
        state['boot_id'] = boot
        state['resume_required'] = bool(state.get('reboot_pending'))
        state.pop('reboot_pending', None)
    state['checked_at'] = now
    state['status'] = 'inspecting'
    for field in ('error_type', 'error_detail', 'summary', 'diagnostics'):
        state.pop(field, None)
    save(path, state)

    def record(event):
        event['at'] = time.time()
        audit = directory / 'audit.jsonl'
        if audit.exists() and audit.stat().st_size > 2 * 1024 * 1024:
            oldest = directory / 'audit.jsonl.2'
            oldest.unlink(missing_ok=True)
            previous = directory / 'audit.jsonl.1'
            if previous.exists():
                previous.replace(oldest)
            audit.replace(previous)
        with audit.open('a') as stream:
            stream.write(json.dumps(event) + '\n')
            stream.flush()
            os.fsync(stream.fileno())

    try:
        # Bounded, explicit inspection outputs only; never send arbitrary logs/configs.
        snapshot = {
            'disk': run(['df', '-B1', '/']),
            'services': run(['systemctl', 'list-units', '--all', '--no-pager',
                             'tarasec*', 'taralink*', 'worker_*']),
            'log_size_bytes': Path('/var/log/tarasec-crontasks.log').stat().st_size
                if Path('/var/log/tarasec-crontasks.log').exists() else None,
            'resume_after_reboot': state.get('resume_required', False),
        }
        registry = json.loads(trusted(policy['procedures_file']).read_text())
        procedures = registry['procedures']
        system = 'ubuntu-' + dict(line.split('=', 1) for line in Path('/etc/os-release')
            .read_text().splitlines() if '=' in line).get('VERSION_ID', '').strip('"')
        available = {name: entry for name, entry in procedures.items() if eligible(entry, policy, system)}
        instructions = trusted('/usr/local/lib/tarasec-operations/AI_OPERATIONS_MANUAL.md').read_text()
        observed = run([str(trusted(policy['quiet_probe']))], timeout=20)
        snapshot['activity'] = json.loads(observed['output']) if observed['exit_code'] == 0 else {'complete': False}
        snapshot['resource_pressure'] = pressure(policy.get('resource_protection', {}))
        snapshot['last_action_result'] = state.get('last_action_result')
        snapshot['interrupted_command'] = state.get('command_pending')
        snapshot['worker_observation_note'] = (
            'This operations worker is running while collecting the snapshot; its activating '
            'state is expected, not a startup failure. Disk percentage alone does not prove '
            'insufficient headroom; consider available bytes and growth.')
        diagnostic_results = cached_diagnostics
        # Deployment coverage is mandatory evidence, not dependent on model selection.
        deployment = diagnose('deployment_status', run)
        diagnostic_results['deployment_status'] = {'checked_at': time.time(), 'result': deployment}
        state['deployment_findings'] = deployment['findings']
        record({'diagnostic': 'deployment_status', 'result': deployment})
        state['diagnostics'] = diagnostic_results
        save(path, state)
        for round_number in range(4):
            # Diagnostics/model calls can outlive freshness; obtain a new sample
            # for each decision and still recheck independently at dispatch.
            observed = run([str(trusted(policy['quiet_probe']))], timeout=20)
            snapshot['activity'] = json.loads(observed['output']) if observed['exit_code'] == 0 else {'complete': False}
            state['activity'] = snapshot['activity']
            snapshot['action_history'] = state.get('action_history', [])
            snapshot['boot_id'] = state.get('boot_id')
            save(path, state)
            prompt = build_prompt(instructions, policy, snapshot, diagnostic_results,
                state.get('task_state', {}), available,
                remaining_diagnostics(diagnostic_results, round_number))
            request_policy = dict(policy, _remaining_diagnostics=list(
                remaining_diagnostics(diagnostic_results, round_number)),
                _eligible_procedures=list(available),
                _available_tools=list(gateway_tools(policy, diagnostic_results, snapshot)))
            decision = model(request_policy, prompt)
            try:
                decision = validate_decision(decision)
                if decision['action'] == 'tool':
                    record({'tool_selection': decision.get('tool')})
                    decision = resolve_tool(decision, gateway_tools(policy, diagnostic_results, snapshot))
                    decision = validate_decision(decision)
            except ValueError as error:
                # Schema messages are fixed strings, never response text.
                feedback = str(error)
                record({'model_schema_correction': feedback})
                snapshot['worker_feedback'] = feedback
                if round_number < 3:
                    continue
                state['status'] = 'model_stalled'
                state['summary'] = feedback
                save(path, state)
                return
            feedback = completed_check_feedback(decision, diagnostic_results, snapshot) or progress_feedback(decision, policy, diagnostic_results, snapshot)
            if feedback:
                record({'model_progress_correction': feedback})
                snapshot['worker_feedback'] = feedback
                if round_number < 3:
                    continue
                state['status'] = 'model_stalled'
                state['summary'] = feedback
                state['task_state'] = decision['task_state']
                save(path, state)
                return
            if decision.get('action') != 'diagnostic':
                break
            name = decision.get('diagnostic')
            feedback = diagnostic_feedback(name, diagnostic_results, round_number)
            if feedback:
                record({'model_correction': feedback})
                snapshot['worker_feedback'] = feedback
                if round_number < 3:
                    continue
                state['status'] = 'model_stalled'
                state['summary'] = feedback
                save(path, state)
                return
            if not isinstance(decision.get('task_state', {}), dict):
                raise ValueError('Task state must be an object')
            state['task_state'] = decision.get('task_state', {})
            state['status'] = 'diagnosing'
            save(path, state)
            result = diagnose(name, run)
            diagnostic_results[name] = {'checked_at': time.time(), 'result': result}
            state['diagnostics'] = diagnostic_results
            record({'diagnostic': name, 'result': result})
            save(path, state)
        if not isinstance(decision.get('task_state', {}), dict):
            raise ValueError('Task state must be an object')
        action = decision.get('action')
        if action not in ('report', 'procedure', 'command', 'resource', 'reboot'):
            raise ValueError('Unknown model action')
        state['task_state'] = decision.get('task_state', {})
        state['summary'] = str(decision.get('reason', ''))[:4000]
        record({'decision': decision, 'snapshot': snapshot})
        if action == 'report':
            state['status'] = 'reported'
            save(path, state)
            return
        if action == 'resource':
            evidence = pressure(policy.get('resource_protection', {}))
            config = validate_resource(decision, policy, evidence)
            # Emergency resource relief has its own explicit owner permission and
            # does not wait for quiet time while resources are being exhausted.
            record({'resource_start': decision, 'pressure': evidence})
            result = perform_resource(decision, config, run, trusted)
            after = pressure(config)
            state['last_action_result'] = {'action': 'resource', 'operation': decision['operation'],
                'exit_code': result['exit_code'], 'pressure_before': evidence, 'pressure_after': after}
            state['status'] = 'resource_ok' if result['exit_code'] == 0 else 'resource_failed'
            record({'resource_result': result, 'pressure_after': after})
            save(path, state)
            return
        if action == 'command':
            if state.get('command_pending'):
                raise ValueError('Interrupted command requires owner reconciliation before more commands')
            argv = validate_command(decision, policy)
            if read_only_command(argv, diagnostic_results):
                record({'read_only_command_start': argv})
                result = run(argv, 20)
                record({'read_only_command_result': result})
                state['last_action_result'] = {'action': 'command', 'read_only': True,
                    'argv': argv, 'exit_code': result['exit_code'],
                    'startup_file_identity': diagnostic_results.get('gateway_startup', {}).get('result', {}).get('properties', {}).get('executable', {}).get('file_identity'),
                    'expected_result': decision['expected_result']}
                if policy.get('share_command_output') is True:
                    state['last_action_result']['output'] = result['output']
                state['status'] = 'command_executed' if result['exit_code'] == 0 else 'command_failed'
                save(path, state)
                return
        if policy.get('mode') != 'demo' or policy.get('execute') is not True:
            raise ValueError('Local policy prohibits autonomous mutation')
        if action == 'procedure' and decision.get('procedure') not in available:
            raise ValueError('Procedure lacks matching deployment-test evidence')
        observer = trusted(policy['quiet_probe'])
        observed = run([str(observer)], timeout=20)
        sample = json.loads(observed['output']) if observed['exit_code'] == 0 else {}
        idle = quiet(state, sample, time.time(), max(60, int(policy.get('quiet_seconds', 300))))
        state['activity'] = sample
        save(path, state)
        if not idle:
            state['status'] = 'deferred_activity_or_unknown'
            save(path, state)
            return
        if action == 'command':
            # Quiet evidence is checked above immediately before dispatch.
            state['status'] = 'command_running'
            state['command_pending'] = {'argv': argv, 'reason': decision['reason'],
                'expected_result': decision['expected_result'], 'recovery_plan': decision['recovery_plan']}
            save(path, state)
            record({'command_start': state['command_pending']})
            result = run(argv, max(1, min(300, int(policy.get('command_timeout_seconds', 120)))))
            record({'command_result': result})
            state['last_action_result'] = {'action': 'command', 'argv': argv,
                'exit_code': result['exit_code'], 'expected_result': decision['expected_result']}
            if policy.get('share_command_output') is True:
                state['last_action_result']['output'] = result['output']
            state.pop('command_pending', None)
            state['status'] = 'command_executed' if result['exit_code'] == 0 else 'command_failed'
            save(path, state)
            return
        if action == 'procedure':
            entry = available[decision['procedure']]
            executable = trusted(entry['executable'])
            if hashlib.sha256(executable.read_bytes()).hexdigest() != entry['sha256']:
                raise ValueError('Approved procedure changed')
            # Recheck after validation so activity that began meanwhile defers.
            observed = run([str(observer)], timeout=20)
            sample = json.loads(observed['output']) if observed['exit_code'] == 0 else {}
            if not quiet(state, sample, time.time(), max(60, int(policy.get('quiet_seconds', 300)))):
                state['status'] = 'deferred_activity_or_unknown'
                save(path, state)
                return
            record({'starting_procedure': decision['procedure']})
            result = run([str(executable)], min(600, int(entry.get('timeout_seconds', 120))))
            # Keep command output local; it is not submitted to the model or central DB.
            record({'procedure': decision['procedure'], 'result': result})
            state['status'] = 'procedure_ok' if result['exit_code'] == 0 else 'procedure_failed'
            state['task_state']['last_procedure'] = decision['procedure']
            state['task_state']['last_exit_code'] = result['exit_code']
        else:
            if policy.get('allow_reboot') is not True:
                raise ValueError('Reboot disabled by local owner policy')
            recent = [t for t in state.get('reboots', []) if 0 <= now - t < 86400]
            if len(recent) >= max(0, int(policy.get('max_reboots_per_day', 1))):
                raise ValueError('Daily reboot limit reached')
            observed = run([str(observer)], timeout=20)
            sample = json.loads(observed['output']) if observed['exit_code'] == 0 else {}
            if not quiet(state, sample, time.time(), max(60, int(policy.get('quiet_seconds', 300)))):
                state['status'] = 'deferred_activity_or_unknown'
                save(path, state)
                return
            state['reboots'] = recent + [now]
            state['reboot_pending'] = True
            state['status'] = 'reboot_requested'
            save(path, state)
            record({'reboot': True, 'reason': state['summary'], 'task_state': state['task_state']})
            result = run(['systemctl', 'reboot'], timeout=30)
            if result['exit_code'] != 0:
                state.pop('reboot_pending', None)
                state['status'] = 'reboot_failed'
                record({'reboot_result': result})
        save(path, state)
    except Exception as error:
        # Do not log HTTP payloads, auth headers or configuration secrets.
        state['status'] = 'blocked'
        state['error_type'] = type(error).__name__
        state['error_detail'] = safe_error(error)
        state['summary'] = 'Blocked: ' + state['error_detail']
        record({'blocked': type(error).__name__, 'detail': state['error_detail']})
        save(path, state)
        raise SystemExit('Operations agent blocked: ' + state['error_detail'])


if __name__ == '__main__':
    main()
