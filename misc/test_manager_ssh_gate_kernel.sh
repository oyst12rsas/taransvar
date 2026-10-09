#!/usr/bin/env bash
# Only run inside a disposable network namespace, never on a live node.
set -euo pipefail
ip link set lo up
for address in 10.100.0.1 100.68.10.7 100.68.10.8 100.68.10.9; do ip addr add "$address/32" dev lo; done
ip -6 addr add 2001:db8::7/128 dev lo nodad
ipset create tarasec_app_ssh bitmap:port range 1-65535 timeout 900
iptables -P INPUT DROP
iptables -N TARASEC_SSH_SOURCE
iptables -A TARASEC_SSH_SOURCE -i lo -j RETURN
iptables -A TARASEC_SSH_SOURCE -s 100.68.10.7/32 -j RETURN
iptables -A TARASEC_SSH_SOURCE -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
iptables -A TARASEC_SSH_SOURCE -j DROP
iptables -A INPUT -p tcp --dport 5822 -j TARASEC_SSH_SOURCE
iptables -A INPUT -i lo -j ACCEPT
iptables -A INPUT -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
iptables -A INPUT -p tcp --dport 5822 -j ACCEPT
iptables -N TARASEC_APP_SSH
iptables -A TARASEC_APP_SSH -s 100.68.10.7/32 -j ACCEPT
iptables -I INPUT 1 -p tcp --dport 5822 -m set --match-set tarasec_app_ssh dst -m comment --comment tarasec-app-ssh-window -j TARASEC_APP_SSH
python3 - "$(dirname "$0")/manager_ssh_worker.php" <<'PY'
import json,socket,subprocess,sys,threading,time
worker=sys.argv[1]
def php(code,*args): subprocess.run(['php','-r','require $argv[1]; '+code,worker,*args],check=True)
def listener(family,host):
    server=socket.socket(family); server.bind((host,5822)); server.listen()
    def echo(conn):
        with conn:
            while True:
                data=conn.recv(64)
                if not data: return
                conn.sendall(data)
    def accept():
        while True:
            conn,_=server.accept(); threading.Thread(target=echo,args=(conn,),daemon=True).start()
    threading.Thread(target=accept,daemon=True).start()
    return server
servers=[listener(socket.AF_INET,'10.100.0.1'),listener(socket.AF_INET6,'::1')]
def connection(source,family=socket.AF_INET):
    client=socket.socket(family); client.settimeout(1); client.bind((source,0))
    try: client.connect(('10.100.0.1' if family==socket.AF_INET else '::1',5822)); return client
    except OSError: client.close(); return None
def reachable(source):
    conn=connection(source)
    if conn: conn.close(); return True
    return False
held=connection('100.68.10.7'); held6=connection('2001:db8::7',socket.AF_INET6)
assert held and held6, 'Fixture must reproduce the permanent ACCEPT bypass'
held.sendall(b'before'); assert held.recv(64)==b'before'
held6.sendall(b'before'); assert held6.recv(64)==b'before'
cfg={'SSH_ALLOWED_SOURCES':'100.68.10.7','SSH_RECOVERY_PROTECT':'on','SSH_RECOVERY_SOURCES':'100.68.10.8'}
install='sshInstallGate(json_decode($argv[2],true),5822,true); sshIpv6Gate(5822);'
php(install,json.dumps(cfg))
assert not reachable('100.68.10.7'), 'Inactive timer fell through to permanent SSH ACCEPT'
assert reachable('100.68.10.8'), 'Explicit recovery source must remain available'
assert not reachable('100.68.10.9'), 'Unapproved source bypassed the source conf'
assert connection('2001:db8::7',socket.AF_INET6) is None, 'IPv6 bypassed the IPv4-only allowed-source conf'
for conn in [held,held6]: conn.sendall(b'kept'); assert conn.recv(64)==b'kept', 'Existing session was disconnected'
subprocess.run(['ipset','add','tarasec_app_ssh','5822','timeout','2'],check=True)
assert reachable('100.68.10.7'), 'Active window failed to open the configured source'
assert not reachable('100.68.10.9'), 'Active window bypassed the source conf'
time.sleep(3)
assert not reachable('100.68.10.7'), 'Window expiry failed to close new connections'
held.sendall(b'after'); assert held.recv(64)==b'after', 'Expiry disconnected an established session'
subprocess.run(['ipset','add','tarasec_app_ssh','5822','timeout','10'],check=True)
assert reachable('100.68.10.7'), 'Reopening failed'
php(install,json.dumps(cfg))
assert reachable('100.68.10.7'), 'Repeated worker application broke the active window'
subprocess.run(['ipset','del','tarasec_app_ssh','5822'],check=True)
assert not reachable('100.68.10.7'), 'Early closure failed'
php('sshRemoveOwnedJumps(sshRun(["/usr/sbin/iptables","-S","INPUT"])); sshRemoveOwnedJumps(sshRun(["/usr/sbin/ip6tables","-S","INPUT"]),"/usr/sbin/ip6tables");')
assert reachable('100.68.10.7'), 'Rollback failed to restore the previous SSH path'
held.close(); held6.close()
print('Production timed gate: permanent ACCEPT bypass blocked, conf sources, recovery, established sessions, kernel expiry, reopening, idempotence, IPv6 isolation and rollback passed')
PY
