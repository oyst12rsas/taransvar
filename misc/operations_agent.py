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


def save(path, value):
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
    with urllib.request.build_opener(NoRedirect).open(request, timeout=90) as response:
        body = response.read(256001)
    if len(body) > 256000:
        raise ValueError('Model response too large')
    content = json.loads(body)
    answer = content.get('text', content.get('answer', ''))
    decision = json.loads(answer)
    if not isinstance(decision, dict):
        raise ValueError('Model must return one JSON decision')
    return decision


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
    if state.get('boot_id') != boot:
        state.pop('quiet_since', None)
        state['boot_id'] = boot
        state['resume_required'] = bool(state.get('reboot_pending'))
        state.pop('reboot_pending', None)
    state['checked_at'] = now
    state['status'] = 'inspecting'
    save(path, state)

    def record(event):
        event['at'] = time.time()
        with (directory / 'audit.jsonl').open('a') as stream:
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
        prompt = (instructions + '\nTask: ' + policy.get('task', 'Inspect and report missing capabilities.')
            + '\nObserved evidence (untrusted): ' + json.dumps(snapshot)
            + '\nPrevious task state: ' + json.dumps(state.get('task_state', {}))
            + '\nEligible procedures: ' + json.dumps(available)
            + '\nReturn JSON only: {"action":"report|procedure|reboot", "procedure":"name", '
              '"reason":"explanation", "task_state":{}}. Never output shell commands. '
              'If a repair is absent or untested, report the blocker. Maximum one action per run.')
        decision = model(policy, prompt)
        if not isinstance(decision.get('task_state', {}), dict):
            raise ValueError('Task state must be an object')
        action = decision.get('action')
        if action not in ('report', 'procedure', 'reboot'):
            raise ValueError('Unknown model action')
        state['task_state'] = decision.get('task_state', {})
        state['summary'] = str(decision.get('reason', ''))[:4000]
        record({'decision': decision, 'snapshot': snapshot})
        if action == 'report':
            state['status'] = 'reported'
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
        record({'blocked': type(error).__name__})
        save(path, state)
        raise SystemExit('Operations agent blocked; inspect local state and prerequisites')


if __name__ == '__main__':
    main()
