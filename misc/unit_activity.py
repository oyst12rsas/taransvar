#!/usr/bin/env python3
"""Read-only minute telemetry. Local/control-plane traffic is never client activity."""
import json
import re
import subprocess
import time
from statistics import median
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


def observe_traffic(previous, counters, now, identity):
    same_interface = previous.get('identity') == identity
    elapsed = now - previous.get('at', 0)
    old_counters = previous.get('counters', [])
    continuous = (same_interface and 45 <= elapsed <= 130 and
                  len(old_counters) == 2 and
                  all(new >= old for new, old in zip(counters, old_counters)))
    baseline = previous.get('baseline') if same_interface else None
    busy = previous.get('busy', False) if same_interface else False
    quiet = previous.get('quiet', 0) if continuous else 0
    samples = previous.get('samples', []) if continuous else []
    rate = None
    if continuous:
        rate = round(60 * (sum(counters) - sum(old_counters)) / elapsed)
        samples = (samples + [rate])[-5:]
        if len(samples) == 5 and (baseline is None or max(samples) < baseline):
            baseline = median(samples)
        if baseline is not None:
            above = rate * 10 >= baseline * 13 if baseline else rate > 0
            if above:
                busy, quiet = True, 0
            elif busy:
                quiet = quiet + 1 if rate * 5 <= baseline * 6 else 0
                if quiet >= 2:
                    busy, quiet = False, 0
    result = {'interface': 'wt0', 'packetsPerMinute': rate, 'baseline': baseline,
              'busy': busy if rate is not None and baseline is not None else None}
    state = {'identity': identity, 'at': now, 'counters': counters,
             'baseline': baseline, 'busy': busy, 'quiet': quiet, 'samples': samples}
    return result, state


def collect_traffic():
    interface = Path('/sys/class/net/wt0')
    boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    ifindex = (interface / 'ifindex').read_text().strip()
    counters = [int((interface / 'statistics' / name).read_text())
                for name in ('rx_packets', 'tx_packets')]
    if (interface / 'ifindex').read_text().strip() != ifindex:
        return None
    path = Path('/run/tarasec-unit-traffic.json')
    try:
        previous = json.loads(path.read_text())
    except (OSError, ValueError):
        previous = {}
    result, state = observe_traffic(previous, counters, time.monotonic(), [boot, ifindex])
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(state))
    temporary.chmod(0o600)
    temporary.replace(path)
    return result


def main():
    now = int(time.time())
    result = {'checkedAt': now, 'clients': None, 'ssh': None, 'traffic': None}
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
    try:
        result['traffic'] = collect_traffic()
    except (OSError, ValueError):
        pass
    print(json.dumps(result))


if __name__ == '__main__':
    main()
