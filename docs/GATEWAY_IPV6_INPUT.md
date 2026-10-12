# Gateway IPv6 INPUT policy

`misc/firewall.sh` now loads adjacent `firewall_ipv6.sh` on gateways. Previously
the gateway routine rebuilt IPv4 but left old IPv6 INPUT rules in place and saved
them again. A blanket `-A INPUT -i wt0 -j ACCEPT` and default ACCEPT policy could
therefore survive every reload.

The new helper validates configuration and the candidate IPv6 filter transaction
before the existing IPv4 setup changes rules. It atomically rebuilds its owned
`TARASEC_IPV6_INPUT` chain, removes exact blanket acceptance for WAN_INTERFACE,
appends its INPUT hook after existing rules and sets INPUT policy DROP. It does
not flush IPv6 forwarding, NAT, NetBird ACLs, or existing source/timed SSH gates.
Explicit earlier owner rules retain their order and may further allow or deny.
The usual `netfilter-persistent save` then persists the resulting IPv6 rules.

The owned policy permits loopback, established/related traffic, ICMPv6 error/link
configuration traffic and DHCPv6 client replies. Echo requests follow ALLOW_PING.
ALLOW_WEB and configured TCP_PORTS/UDP_PORTS open services only on WAN_INTERFACE.
SSH_PORT is excluded from TCP_PORTS; it requires ALLOW_SSH plus explicit
SSH_ALLOWED_IPV6_SOURCES (comma-separated IPv6 hosts/CIDRs). IPv4 SSH sources do
not grant IPv6 access. Existing SSH gates run first and may still deny these
sources; this setting never overrides the timed/source gate.

Kernel WireGuard listening UDP ports are discovered with `wg show all listen-port`.
Additional userspace VPN transport ports must be listed explicitly in
IPV6_TRANSPORT_UDP_PORTS. The helper does not infer permissions from arbitrary
listening services or blanket-allow all UDP. Re-run the routine after changing
transport listen ports; persistence contains the ports observed at setup time.

Deploy both adjacent shell files when updating installed copies. A repository
update alone does not alter a host. This source change has not been applied to
the user's live gateway. To apply just the IPv6 correction, use
`sudo bash misc/firewall.sh --ipv6-only` (an alternate config path may follow the
flag). This mode validates and applies the IPv6 transaction, then exits before
IPv4 changes or persistence. Back up the current IPv6 rules and schedule rollback
before applying; verify connectivity, then persist only IPv6 with `ip6tables-save`
to `/etc/iptables/rules.v6` and cancel rollback. Before applying the full firewall routine, verify access
and recovery: its pre-existing IPv4 path still rebuilds IPv4 filter chains, and
an active source/timed gate must be retained/reapplied by the installed workflow.
Do not treat IPv6 fixture checks as approval to bypass that login requirement.

`python3 tests/firewall_ipv6_test.py` checks the real generated policy with mocked
commands, including service restrictions, explicit transport ports, IPv6 control
traffic, SSH policy, repeated setup and failure-before-apply. It never changes the
test computer's firewall. Live Netfilter enforcement and transport/login recovery
remain deployment acceptance checks.
