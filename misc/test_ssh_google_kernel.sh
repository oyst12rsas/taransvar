#!/usr/bin/env bash
# Run only in a disposable network namespace.
set -euo pipefail
ip link set lo up
ip addr add 100.68.10.7/32 dev lo
ip addr add 100.68.10.8/32 dev lo
ip -6 addr add 2001:db8::7/128 dev lo nodad
python3 - "$(dirname "$0")" <<'PY'
import ipaddress, socket, subprocess, sys, threading, time
sys.path.insert(0,sys.argv[1])
import ssh_google_window as window
servers=[]
for family,host in [(socket.AF_INET,'127.0.0.1'),(socket.AF_INET6,'::1')]:
    server=socket.socket(family); server.bind((host,4810)); server.listen(); servers.append(server)
    def accept(server=server):
        while True:
            conn,_=server.accept()
            def echo(conn=conn):
                with conn:
                    while True:
                        data=conn.recv(64)
                        if not data: return
                        conn.sendall(data)
            threading.Thread(target=echo,daemon=True).start()
    threading.Thread(target=accept,daemon=True).start()
def connection(source):
    family=socket.AF_INET6 if ':' in source else socket.AF_INET
    conn=socket.socket(family); conn.settimeout(1); conn.bind((source,0))
    try: conn.connect(('::1' if family==socket.AF_INET6 else '127.0.0.1',4810)); return conn
    except OSError: conn.close(); return None
def allowed(source):
    conn=connection(source)
    if conn: conn.close(); return True
    return False
# Baseline blanket allows reproduce the original bypass in both families.
for binary in ['iptables','ip6tables']:
    subprocess.run([binary,'-A','INPUT','-m','conntrack','--ctstate','RELATED,ESTABLISHED','-j','ACCEPT'],check=True)
    subprocess.run([binary,'-A','INPUT','-i','lo','-j','ACCEPT'],check=True)
held=connection('100.68.10.7'); held6=connection('2001:db8::7')
assert held and held6
sources=[ipaddress.ip_network('100.68.10.7/32'),ipaddress.ip_network('2001:db8::7/128')]
window.apply_rules(4810,sources,0)
for source in ['100.68.10.7','100.68.10.8','127.0.0.1','2001:db8::7','::1']: assert not allowed(source),source
for conn in [held,held6]:
    conn.sendall(b'kept'); assert conn.recv(64)==b'kept'
window.apply_rules(4810,sources,int(time.time())+300)
assert allowed('100.68.10.7') and allowed('2001:db8::7')
assert not allowed('100.68.10.8')
window.apply_rules(4810,sources,0)
assert not allowed('100.68.10.7') and not allowed('2001:db8::7')
for conn in [held,held6]:
    conn.sendall(b'closed'); assert conn.recv(64)==b'closed'; conn.close()
window.apply_rules(4810,sources,int(time.time())+300)
assert allowed('100.68.10.7')
print('Google SSH: blanket bypass blocked, source restrictions, dual-stack close/reopen and established sessions passed')
PY
