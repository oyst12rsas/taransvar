# Node firewall logging with NetBird

Set `IS_GATEWAY=0` explicitly in `/etc/tarasecfw.conf` on nodes. The
configured `WAN_INTERFACE` defaults to `wt0`. Node mode in
`misc/firewall.sh` requires an existing NetBird ACL and final DROP on that
interface for both IPv4 and IPv6. It adds a rate-limited `TARASEC_` LOG
chain immediately before those final DROP rules and leaves the existing
ACLs, routing, other firewall chains, and live SSH sessions untouched.

After reviewing the node's `/etc/tarasecfw.conf`, run **on that node**:

```sh
clear
cd ~/taransvar
git pull --ff-only
sudo bash misc/install_node_firewall.sh
sudo iptables -S INPUT
sudo ip6tables -S INPUT
systemctl status tarasec-node-firewall.service --no-pager
```

The timer reruns the idempotent node path every two minutes, including after
boot, because NetBird can rebuild its own INPUT rules. If either NetBird
terminal DROP is missing, the script fails without changing firewall rules.
This hook logs packets reaching the final NetBird DROP; NetBird ACL rules that
drop packets earlier will not produce TaraSec log lines. Rsyslog forwarding
must be configured independently.

`IS_GATEWAY=1` retains the existing gateway firewall path, which has
different NAT and forwarding behavior. Do not use the gateway path on a node.
