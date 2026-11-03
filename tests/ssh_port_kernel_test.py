"""Run ONLY inside a disposable network namespace: real NAT, ipset expiry and TCP."""
import ipaddress
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import threading
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
import ssh_google_window as window
import ssh_honeypot_route as route


def run(*args):
    return subprocess.run(args, text=True, capture_output=True, check=True).stdout


run('ip', 'link', 'set', 'lo', 'up')
for ip in ('10.100.0.1', '100.68.10.7', '100.68.10.9'):
    run('ip', 'addr', 'add', ip + '/32', 'dev', 'lo')
for ip in ('fd00::1', 'fd00::7', 'fd00::9'):
    run('ip', '-6', 'addr', 'add', ip + '/128', 'dev', 'lo', 'nodad')
for tool in ('iptables', 'ip6tables'):
    run(tool, '-P', 'INPUT', 'DROP')
    run(tool, '-A', 'INPUT', '-i', 'lo', '-j', 'ACCEPT')
    run(tool, '-A', 'INPUT', '-m', 'conntrack', '--ctstate', 'ESTABLISHED,RELATED', '-j', 'ACCEPT')


def server(family, host, port, greeting):
    listener = socket.socket(family)
    listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    if family == socket.AF_INET6:
        listener.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
    listener.bind((host, port))
    listener.listen()
    def echo(connection):
        with connection:
            connection.sendall(greeting)
            while True:
                value = connection.recv(64)
                if not value:
                    return
                connection.sendall(value)
    def accept():
        while True:
            connection, _ = listener.accept()
            threading.Thread(target=echo, args=(connection,), daemon=True).start()
    threading.Thread(target=accept, daemon=True).start()
    return listener


listeners = []
for family, host in ((socket.AF_INET, '10.100.0.1'), (socket.AF_INET6, 'fd00::1')):
    for port, greeting in ((2345, b'REAL'), (2222, b'HONEYPOT'), (3306, b'OTHER')):
        bind = ('0.0.0.0' if family == socket.AF_INET else '::') if port == 2222 else host
        listeners.append(server(family, bind, port, greeting))


def connect(port, family=socket.AF_INET, allowed=True):
    source = ('100.68.10.7' if allowed else '100.68.10.9') if family == socket.AF_INET else ('fd00::7' if allowed else 'fd00::9')
    host = '10.100.0.1' if family == socket.AF_INET else 'fd00::1'
    client = socket.socket(family)
    client.settimeout(2)
    client.bind((source, 0))
    client.connect((host, port))
    return client, client.recv(32)


with tempfile.TemporaryDirectory() as directory:
    cfg = Path(directory) / 'firewall'
    policy = Path(directory) / 'policy'
    cfg.write_text('SSH_PORT=2345\nSSH_ALLOWED_SOURCES=100.68.10.7/32,fd00::7/128\n'
                   'SSH_HONEYPOT=on\nSSH_HONEYPOT_PORT=2222\nSSH_HONEYPOT_PORTS=1000-5000\nSSH_CLOSED_HONEYPOT=yes\n')
    policy.write_text('SSH_GOOGLE_REOPEN_ENABLED=yes\n')
    window.CONF = route.CONF = str(cfg)
    window.POLICY = str(policy)
    sources = [ipaddress.ip_network('100.68.10.7/32'), ipaddress.ip_network('fd00::7/128')]
    forwards = {tool: run(tool, '-S', 'FORWARD') for tool in ('iptables', 'ip6tables')}
    window.apply_rules(2345, sources, 0)
    for tool in ('iptables', 'ip6tables'):
        # Local test sockets do not traverse PREROUTING. Feed the SAME chain from
        # OUTPUT only in this isolated fixture; production creates no OUTPUT hook.
        run(tool, '-t', 'nat', '-A', 'OUTPUT', '-p', 'tcp', '-m', 'addrtype', '--dst-type', 'LOCAL', '-j', route.CHAIN)
    for family in (socket.AF_INET, socket.AF_INET6):
        for port in (1000, 2345, 5000):
            client, greeting = connect(port, family)
            assert greeting == b'HONEYPOT', (family, port, greeting)
            client.close()
        client, greeting = connect(3306, family)
        assert greeting == b'OTHER', 'Existing service was hijacked by the decoy range'
        client.close()
    window.apply_rules(2345, sources, time.time() + 4)
    held = []
    for family in (socket.AF_INET, socket.AF_INET6):
        client, greeting = connect(2345, family)
        assert greeting == b'REAL', (family, greeting)
        held.append(client)
        client, greeting = connect(2345, family, False)
        assert greeting == b'HONEYPOT', 'An unapproved source reached real SSH'
        client.close()
    # Deliberately do NOT call close/reconcile. Kernel timeout alone must remove
    # the route to real SSH for NEW connections if the worker/timer stops.
    time.sleep(5)
    for family in (socket.AF_INET, socket.AF_INET6):
        client, greeting = connect(2345, family)
        assert greeting == b'HONEYPOT', 'Expired MFA lease still reached real SSH'
        client.close()
    # Even a local path that bypasses decoy NAT must not bypass kernel expiry.
    for tool, family in (('iptables', socket.AF_INET), ('ip6tables', socket.AF_INET6)):
        hook = ['-p', 'tcp', '-m', 'addrtype', '--dst-type', 'LOCAL', '-j', route.CHAIN]
        run(tool, '-t', 'nat', '-D', 'OUTPUT', *hook)
        try:
            client, greeting = connect(2345, family)
        except OSError:
            pass
        else:
            client.close()
            raise AssertionError('Local connection bypassed the expired filter lease')
        run(tool, '-t', 'nat', '-A', 'OUTPUT', *hook)
    for client in held:
        client.sendall(b'survives-expiry')
        assert client.recv(64) == b'survives-expiry', 'Established real SSH session was interrupted'
        client.close()
    for tool in ('iptables', 'ip6tables'):
        assert run(tool, '-S', 'FORWARD') == forwards[tool], 'Forwarding policy changed'
print('PASS: overlapping range, closed/open/expiry, exact allowed sources, IPv4/IPv6, other services and established sessions')
