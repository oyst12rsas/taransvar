#!/usr/bin/env python3
"""Read-only minute telemetry. Local/control-plane traffic is never client activity."""
import json
import re
import subprocess
import time
from pathlib import Path


def run(args):
    return subprocess.check_output(args, text=True, timeout=8, stderr=subprocess.DEVNULL)


def routed_flows(text, local):
    flows = {}
    for line in text.splitlines():
        src = re.findall(r'\bsrc=(\S+)', line)
        dst = re.findall(r'\bdst=(\S+)', line)
        packets = re.findall(r'\bpackets=(\d+)', line)
        if len(src) < 2 or len(dst) < 2:
            continue
        # Both original endpoints must be remote; INPUT and OUTPUT are excluded.
        if src[0] in local or dst[0] in local:
            continue
        if not packets:
            raise ValueError('Conntrack accounting unavailable')
        # Reply endpoints cover NAT; source stays the original client address.
        key = line.split()[0] + ' ' + ' '.join(re.findall(
            r'\b(?:src|dst|sport|dport|zone)=[^ ]+', line))
        flows[key] = (src[0], sum(map(int, packets)))
    return flows


def ssh_sessions(text):
    sessions = []
    for line in text.splitlines():
        match = re.match(r'\s*(\d+)\s+sshd(?:-session)?: ([^\s@]+)@([^\s]+)\s*$', line)
        if match and match[2] not in ('[priv]', '[net]', '[accepted]'):
            sessions.append({'pid': int(match[1]), 'user': match[2], 'terminal': match[3]})
    return sessions


def observe(previous, flows, local, now, boot):
    continuous = (previous.get('boot') == boot and 0 < now - previous.get('at', 0) <= 130)
    active = {ip: stamp for ip, stamp in previous.get('active', {}).items()
              if continuous and 0 <= now - stamp < 300 and ip not in local}
    if continuous:
        old = previous.get('flows', {})
        for key, (ip, count) in flows.items():
            if count > old.get(key, [ip, 0])[1]:
                active[ip] = now
    return (len(active) if continuous else None,
            {'boot': boot, 'at': now, 'flows': flows, 'active': active})


def main():
    now = int(time.time())
    result = {'checkedAt': now, 'clients': None, 'ssh': None}
    try:
        result['ssh'] = ssh_sessions(run(['/bin/ps', '-eo', 'pid=,args=']))
    except (OSError, subprocess.SubprocessError):
        pass
    try:
        addresses = json.loads(run(['/usr/sbin/ip', '-j', 'address', 'show']))
        local = {a['local'] for dev in addresses for a in dev.get('addr_info', [])}
        flows = routed_flows(run(['/usr/sbin/conntrack', '-L', '-o', 'extended']), local)
        boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
        path = Path('/run/tarasec-unit-activity.json')
        try:
            previous = json.loads(path.read_text())
        except (OSError, ValueError):
            previous = {}
        count, state = observe(previous, flows, local, now, boot)
        result['clients'] = count
        temporary = path.with_suffix('.tmp')
        temporary.write_text(json.dumps(state))
        temporary.chmod(0o600)
        temporary.replace(path)
    except (OSError, ValueError, KeyError, subprocess.SubprocessError):
        pass
    print(json.dumps(result))


if __name__ == '__main__':
    main()
