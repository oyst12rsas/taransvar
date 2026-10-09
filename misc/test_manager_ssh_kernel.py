#!/usr/bin/env python3
"""Verify real kernel expiry in a fresh network namespace, never the host network."""
import os
import socket
import time
from unittest import mock
import manager_ssh as gate

if os.readlink('/proc/self/ns/net') == os.readlink('/proc/1/ns/net'):
    raise RuntimeError('Run only through sudo unshare --net; refusing host firewall mutation')
gate.run('/usr/sbin/ip', 'link', 'set', 'lo', 'up')
gate.run('/usr/sbin/iptables', '-A', 'INPUT', '-p', 'tcp', '--dport', '5822', '-j', 'REJECT', '--reject-with', 'tcp-reset')
listener = socket.socket()
listener.bind(('127.0.0.1',5822))
listener.listen(10)
real_run = gate.run
wall_now = int(time.time())
# A five-minute lease with its issuance clock advanced to 296 seconds ago
# lets this isolated acceptance test observe the real expiry in four seconds.
def command(*args):
    if args[0] == '/usr/bin/ss': return 'LISTEN users:(("sshd",pid=1,fd=3))'
    return real_run(*args)
with mock.patch.object(gate,'configuration',return_value={'SSH_PORT':'5822','ALLOW_SSH':'0','SSH_MANAGER_TIMED_OPEN':'on'}), \
     mock.patch.object(gate.time,'time',return_value=wall_now-296), \
     mock.patch.object(gate,'run',side_effect=command):
    report = gate.execute('open','127.0.0.1',5)
assert report['state'] == 'open'
connection = socket.create_connection(('127.0.0.1',5822),timeout=1)
connection.close()
other = socket.socket()
other.bind(('127.0.0.2',0))
other.settimeout(1)
assert other.connect_ex(('127.0.0.1',5822)) != 0, 'Other source must remain closed'
other.close()
end = time.monotonic()+5
while time.monotonic()<end: time.sleep(0.1)
try:
    socket.create_connection(('127.0.0.1',5822),timeout=1)
except OSError:
    pass
else:
    raise AssertionError('Kernel failed to close expired opening')
listener.close()
print('Isolated kernel opening, source restriction and automatic expiry passed')
