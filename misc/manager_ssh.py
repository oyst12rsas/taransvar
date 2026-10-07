#!/usr/bin/env python3
"""Narrow, owner-enabled IPv4 SSH opening; expiry is enforced by xt_time."""
import datetime
import fcntl
import ipaddress
import json
import os
import shlex
import subprocess
import sys
import time

CONFIG = '/etc/tarasecfw.conf'
COMMENT = 'tarasec-manager-ssh:'


def run(*args):
    return subprocess.check_output(args, text=True, stderr=subprocess.PIPE, timeout=8)


def configuration():
    result = {}
    with open(CONFIG) as source:
        for line in source:
            if '=' in line and not line.lstrip().startswith('#'):
                key, value = line.strip().split('=', 1)
                result[key.strip()] = value.strip().strip('\"\'')
    return result


def option(rule, name, default=None):
    return rule[rule.index(name) + 1] if name in rule else default


def matches_source(rule, address):
    return ipaddress.ip_address(address) in ipaddress.ip_network(option(rule, '-s', '0.0.0.0/0'), strict=False)


def policy(rules, address, port, now):
    """Only report conclusive direct INPUT rules; unfamiliar policy stays unknown."""
    position = 0
    for line in rules.splitlines():
        rule = shlex.split(line)
        if rule[:2] != ['-A', 'INPUT']:
            continue
        position += 1
        if '!' in rule:
            return {'state': 'unknown', 'reason': 'negated_policy_requires_review'}
        if '-p' in rule and option(rule, '-p') != 'tcp':
            continue
        if '--dport' in rule and option(rule, '--dport') != str(port):
            continue
        if '-i' in rule:
            if option(rule, '-i') == 'lo':
                continue
            return {'state': 'unknown', 'reason': 'interface_policy_requires_review'}
        if not matches_source(rule, address):
            continue
        # Canonical iptables output includes the tcp module. Other match modules remain significant.
        if '-m' in rule and option(rule, '-m') == 'tcp':
            at = rule.index('-m')
            rule = rule[:at] + rule[at+2:]
        comment = option(rule, '--comment', '')
        target = option(rule, '-j', '')
        if target == 'LOG':
            continue
        if comment.startswith(COMMENT):
            expiry = int(comment.rsplit(':', 1)[1])
            if expiry <= now:
                continue
            return {'state': 'open', 'expiresAt': expiry, 'remainingSeconds': max(0, expiry - now)}
        # The standard TaraSec SSH barrier is safe to open immediately before it.
        barrier_tokens = {'-A', 'INPUT', '-p', 'tcp', '-s', '--dport', str(port), '-j', 'REJECT', '--reject-with', 'tcp-reset', option(rule, '-s')}
        if target == 'REJECT' and option(rule, '--dport') == str(port) and set(rule).issubset(barrier_tokens):
            return {'state': 'closed', 'position': position}
        simple = {'-A', 'INPUT', '-p', 'tcp', '-s', '-d', '--dport', '-j', 'ACCEPT'}
        values = {option(rule, k) for k in ('-s', '-d', '--dport') if k in rule}
        if target == 'ACCEPT' and set(rule).issubset(simple | values) and '-d' not in rule:
            return {'state': 'open', 'expiresAt': None, 'remainingSeconds': None}
        return {'state': 'unknown', 'reason': 'firewall_policy_requires_review'}
    return {'state': 'unknown', 'reason': 'ssh_barrier_not_found'}


def execute(action, address, minutes=None):
    ipaddress.IPv4Address(address)
    if action not in ('status', 'open') or (action == 'open' and minutes not in (5, 10, 15)):
        raise ValueError('invalid_request')
    cfg = configuration()
    port = int(cfg.get('SSH_PORT', '22'))
    if not 1 <= port <= 65535:
        raise ValueError('invalid_ssh_port')
    enabled = cfg.get('SSH_MANAGER_TIMED_OPEN', 'off').lower() in ('on', 'yes', 'true', '1')
    now = int(time.time())
    rules = run('/usr/sbin/iptables', '-w', '5', '-S', 'INPUT')
    state = policy(rules, address, port, now)
    allowed = cfg.get('SSH_ALLOWED_SOURCES', '')
    permitted = not allowed or any(ipaddress.IPv4Address(address) in ipaddress.ip_network(x.strip(), strict=False)
                                   for x in allowed.split(',') if x.strip())
    listeners = run('/usr/bin/ss', '-4', '-H', '-lntp', f'sport = :{port}')
    listening = 'sshd' in listeners
    supported = listening and enabled and permitted and cfg.get('ALLOW_SSH', '1') == '0' and state['state'] == 'closed'
    if action == 'open':
        if not supported:
            raise ValueError('ssh_open_not_allowed_by_current_policy')
        # Never change persistent ALLOW_SSH or an existing session/recovery rule.
        # UTC stop time is inclusive, so subtract one second from the chosen deadline.
        # Remove only expired rules from this helper; other firewall policy is untouched.
        for line in rules.splitlines():
            rule = shlex.split(line)
            comment = option(rule, '--comment', '')
            if comment.startswith(COMMENT) and int(comment.rsplit(':', 1)[1]) <= now:
                run('/usr/sbin/iptables', '-w', '5', '-D', *rule[1:])
        rules = run('/usr/sbin/iptables', '-w', '5', '-S', 'INPUT')
        state = policy(rules, address, port, now)
        if state['state'] != 'closed':
            raise ValueError('firewall_changed_retry_status')
        expiry = now + minutes * 60
        deadline = datetime.datetime.fromtimestamp(expiry - 1, datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%S')
        run('/usr/sbin/iptables', '-w', '5', '-I', 'INPUT', str(state['position']),
            '-p', 'tcp', '-s', address, '--dport', str(port), '-m', 'time',
            '--datestop', deadline, '-m', 'comment', '--comment', f'{COMMENT}{address}:{expiry}', '-j', 'ACCEPT')
        rules = run('/usr/sbin/iptables', '-w', '5', '-S', 'INPUT')
        state = policy(rules, address, port, now)
        if state['state'] != 'open':
            raise ValueError('ssh_open_not_confirmed')
        print(f'TARASEC_MANAGER_SSH source={address} minutes={minutes} expires={expiry}', file=sys.stderr)
    result = {k: v for k, v in state.items() if k != 'position'}
    result.update(port=port, source=address, listening=listening, canOpen=supported and listening and state['state'] == 'closed', checkedAt=now)
    if not listening:
        result['state'] = 'closed'
        result['reason'] = 'administrative_ssh_not_listening'
    return result


if __name__ == '__main__':
    try:
        if os.geteuid() != 0:
            raise ValueError('root_required')
        os.makedirs('/run/tarasec', mode=0o755, exist_ok=True)
        with open('/run/tarasec/manager-ssh.lock', 'a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            if len(sys.argv) not in (3, 4):
                raise ValueError('invalid_request')
            result = execute(sys.argv[1], sys.argv[2], int(sys.argv[3]) if len(sys.argv) == 4 else None)
        print(json.dumps({'ok': True, 'ssh': result}))
    except (ValueError, OSError, subprocess.SubprocessError) as error:
        print(json.dumps({'ok': False, 'error': str(error)[:200]}))
        sys.exit(1)
