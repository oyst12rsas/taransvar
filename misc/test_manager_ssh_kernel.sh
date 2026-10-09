#!/usr/bin/env bash
# Run inside a disposable network namespace; never use the node's real firewall.
set -euo pipefail
ipset create tarasec_app_ssh bitmap:port range 1-65535 timeout 900
iptables -N TARASEC_APP_SSH
iptables -A TARASEC_APP_SSH -s 10.100.0.150/32 -j ACCEPT
iptables -A INPUT -p tcp --dport 5822 -m set --match-set tarasec_app_ssh dst -j TARASEC_APP_SSH
iptables -A INPUT -p tcp --dport 5822 -j REJECT --reject-with tcp-reset
iptables -C TARASEC_APP_SSH -s 10.100.0.150/32 -j ACCEPT
if iptables -C TARASEC_APP_SSH -s 10.100.0.151/32 -j ACCEPT 2>/dev/null; then exit 1; fi
ipset add tarasec_app_ssh 5822 timeout 2
ipset test tarasec_app_ssh 5822
if ipset test tarasec_app_ssh 22 2>/dev/null; then exit 1; fi
ip link set lo up
ip addr add 10.100.0.1/32 dev lo
ip addr add 10.100.0.150/32 dev lo
ip addr add 10.100.0.151/32 dev lo
python3 - <<'PY'
import socket, threading, time
server=socket.socket()
server.bind(('10.100.0.1',5822)); server.listen()
def serve():
    while True:
        conn,_=server.accept(); conn.close()
threading.Thread(target=serve,daemon=True).start()
def connects(source):
    with socket.socket() as client:
        client.settimeout(1); client.bind((source,0))
        try: client.connect(('10.100.0.1',5822)); return True
        except OSError: return False
assert connects('10.100.0.150'), 'Conf-permitted computer could not connect during opening'
assert not connects('10.100.0.151'), 'Opening bypassed the configured source restriction'
time.sleep(3)
assert not connects('10.100.0.150'), 'New connection still allowed after kernel timeout'
PY
if ipset test tarasec_app_ssh 5822 2>/dev/null; then echo 'Kernel lease failed to expire'; exit 1; fi
echo 'Kernel port isolation and expiration without a running worker passed'
