#!/usr/bin/env python3
"""Opt-in local-destination SSH decoys with kernel-expiring MFA exemptions."""
import ipaddress
import json
from pathlib import Path
import re
import shutil
import subprocess
import time

CONF = '/etc/tarasecfw.conf'
CHAIN = 'TARASEC_SSH_DECOY'
SETS = {4: 'tarasec_ssh_real4', 6: 'tarasec_ssh_real6'}


def run(*args, check=True, input=None):
    result = subprocess.run(args, input=input, text=True, capture_output=True, timeout=20)
    if check and result.returncode:
        raise RuntimeError('SSH decoy policy command failed; inspect locally with administrator access')
    return result


def on(value):
    return str(value).lower() in ('yes', 'on', 'true', '1')


def ranges(value):
    result = []
    for part in re.split(r'[,\s]+', value.strip()):
        if not part:
            continue
        match = re.fullmatch(r'([0-9]{1,5})(?:-([0-9]{1,5}))?', part)
        if not match:
            raise ValueError('Use comma-separated ports or ascending port ranges')
        lo, hi = int(match[1]), int(match[2] or match[1])
        if not 1 <= lo <= hi <= 65535:
            raise ValueError('Ports must be between 1 and 65535')
        result.append((lo, hi))
    if not result or len(result) > 64:
        raise ValueError('Configure between one and 64 honeypot port/range entries')
    return sorted(set(result))


def config():
    import ssh_google_window as window
    cfg = window.settings(CONF)
    if not on(cfg.get('SSH_CLOSED_HONEYPOT', 'no')):
        return None
    if not on(cfg.get('SSH_HONEYPOT', 'off')) or cfg.get('ALLOW_SSH', '1') != '1':
        raise ValueError('Closed-port decoys require the honeypot and admin SSH policies enabled')
    if not on(window.settings(window.POLICY).get('SSH_GOOGLE_REOPEN_ENABLED', 'no')):
        raise ValueError('Closed-port decoys require the owner-enabled MFA gate')
    port, sources = window.configuration()
    backend = int(cfg.get('SSH_HONEYPOT_PORT', '22'))
    if not 1 <= backend <= 65535 or backend == port:
        raise ValueError('The real SSH and honeypot backend listeners must differ')
    return cfg, port, backend, sources


def mariadb_ports():
    ports = {3306}
    server = shutil.which('mariadbd') or shutil.which('mysqld')
    if server:
        defaults = run(server, '--print-defaults').stdout
        ports.update(int(port) for port in re.findall(r'(?:^|\s)--port(?:=|\s+)([0-9]{1,5})(?=\s|$)', defaults)
                     if 1 <= int(port) <= 65535)
    return ports


def excluded_ports(cfg, port, backend):
    # Protect other live TCP services, plus explicitly reserved service ports.
    # Refreshing the policy discovers newly started listeners as well.
    database_ports = mariadb_ports()
    excluded = {backend} | database_ports
    for field in ('TCP_PORTS', 'SSH_HONEYPOT_EXCLUDE_PORTS'):
        text = cfg.get(field, '')
        if text.strip():
            for lo, hi in ranges(text):
                if hi - lo > 1024:
                    raise ValueError('Reserved service ranges must be small and explicit')
                excluded.update(range(lo, hi + 1))
    if cfg.get('ALLOW_WEB', '1') == '1':
        excluded.update((80, 443))
    for line in run('ss', '-H', '-ltn').stdout.splitlines():
        fields = line.split()
        if len(fields) >= 4:
            try:
                excluded.add(int(fields[3].rsplit(':', 1)[1]))
            except ValueError:
                pass
    excluded.discard(port)
    return sorted(excluded | database_ports)


def prepare():
    """Build decoy NAT without flushing any host policy or touching forwarding."""
    value = config()
    if value is None:
        return
    cfg, port, backend, sources = value
    decoys = ranges(cfg.get('SSH_HONEYPOT_PORTS', str(backend)))
    excluded = excluded_ports(cfg, port, backend)
    for tool, version in [('iptables', 4), ('ip6tables', 6)]:
        name = SETS[version]
        run('ipset', 'create', name, 'hash:net,port', 'family', 'inet' if version == 4 else 'inet6',
            'timeout', '900', '-exist')
        run(tool, '-w', '5', '-t', 'nat', '-N', CHAIN, check=False)
        # Restore commits this chain as a transaction. Other tables/chains stay intact.
        lines = ['*nat', '-F ' + CHAIN,
                 f'-A {CHAIN} -p tcp --dport {port} -m set --match-set {name} src,dst -j ACCEPT',
                 f'-A {CHAIN} -p tcp --dport {port} -j REDIRECT --to-ports {backend}']
        for reserved in excluded:
            lines.append(f'-A {CHAIN} -p tcp --dport {reserved} -j ACCEPT')
        for lo, hi in decoys:
            spec = str(lo) if lo == hi else f'{lo}:{hi}'
            lines.append(f'-A {CHAIN} -p tcp --dport {spec} -j REDIRECT --to-ports {backend}')
        lines += ['COMMIT', '']
        run(tool + '-restore', '--noflush', input='\n'.join(lines))
        hook = ['-p', 'tcp', '-m', 'addrtype', '--dst-type', 'LOCAL', '-j', CHAIN]
        if run(tool, '-w', '5', '-t', 'nat', '-C', 'PREROUTING', *hook, check=False).returncode:
            run(tool, '-w', '5', '-t', 'nat', '-I', 'PREROUTING', '1', *hook)
        # Backend acceptance does not open real SSH: distinct listeners are mandatory.
        accept = ['-p', 'tcp', '--dport', str(backend), '-j', 'ACCEPT']
        if run(tool, '-w', '5', '-C', 'INPUT', *accept, check=False).returncode:
            run(tool, '-w', '5', '-I', 'INPUT', '1', *accept)
        # Preserve preceding owner ACLs; add real-SSH acceptance before a terminal
        # unconditional DROP/REJECT. The MFA gate is subsequently inserted first.
        listing = run(tool, '-w', '5', '-S', 'INPUT').stdout.splitlines()
        rules = [line for line in listing if line.startswith('-A INPUT ')]
        position = next((i + 1 for i, line in enumerate(rules)
                         if line in ('-A INPUT -j DROP', '-A INPUT -j REJECT')), len(rules) + 1)
        for source in sources:
            if source.version != version:
                continue
            rule = ['-p', 'tcp', '--dport', str(port), '-s', str(source), '-j', 'ACCEPT']
            if run(tool, '-w', '5', '-C', 'INPUT', *rule, check=False).returncode:
                run(tool, '-w', '5', '-I', 'INPUT', str(position), *rule)


def lease(port, sources, until):
    """A NAT exemption alone never bypasses the filter-table MFA gate."""
    for version, name in SETS.items():
        staging = name + '_next'
        run('ipset', 'create', staging, 'hash:net,port', 'family', 'inet' if version == 4 else 'inet6',
            'timeout', '900', '-exist')
        run('ipset', 'flush', staging)
        for source in sources:
            # Recompute for each entry, leaving a second for command scheduling.
            # Updating an existing window never gives its set a fresh 900s lease.
            timeout = max(0, min(899, int(until - time.time()) - 1))
            if timeout and source.version == version:
                run('ipset', 'add', staging, str(source) + ',tcp:' + str(port),
                    'timeout', str(timeout), '-exist')
        run('ipset', 'swap', staging, name)
        run('ipset', 'flush', staging)


def require_quiet(port):
    """Block opening after repeated credentials on this exact decoy port."""
    value = config()
    if value is None:
        return
    cfg = value[0]
    quiet = int(cfg.get('SSH_ATTACK_QUIET_SECONDS', '60'))
    threshold = int(cfg.get('SSH_ATTACK_ATTEMPTS', '5'))
    if not 30 <= quiet <= 600 or not 2 <= threshold <= 100:
        raise ValueError('Invalid SSH attack threshold or quiet interval')
    run('systemctl', 'is-active', '--quiet', 'tarasec-ssh-honeypot.service')
    result = run('journalctl', '-u', 'tarasec-ssh-honeypot.service', '--since', f'-{quiet} seconds',
                 '-n', '2001', '-o', 'json', '--no-pager')
    records = result.stdout.splitlines()
    if len(records) > 2000:
        raise RuntimeError('SSH opening refused: honeypot evidence is overloaded; retry after a quiet period')
    count = 0
    now = time.time()
    for line in records:
        try:
            entry = json.loads(line)
            event = json.loads(entry.get('MESSAGE', ''))
            stamp = float(event.get('timestamp', 0))
            if (event.get('sensor') == 'tarasec-ssh-lite' and event.get('dst_port') == port
                    and event.get('event') in ('ssh_session_connect', 'ssh_login_failed', 'ssh_login_success')
                    and now - quiet <= stamp <= now + 2):
                count += 1
        except (ValueError, TypeError, AttributeError):
            continue
    if count >= threshold:
        raise RuntimeError('SSH opening refused: recent credential attacks on this port; retry after a quiet period')
