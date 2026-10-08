# VM host firewall telemetry

Use `FIREWALL_MODE=host` and `IS_GATEWAY=0` for a virtualization host that is not a TaraSec node. This mode requires neither tarakernel nor a database. It adds only a rate-limited INPUT observation chain for IPv4 and IPv6, then returns to the existing firewall. Existing INPUT decisions, libvirt/NetBird/Docker chains, forwarding, NAT and sysctls remain unchanged. It does not enforce gateway-mode ALLOW_SSH or SSH_ALLOWED_SOURCES; the existing host firewall remains authoritative.

Example /etc/tarasecfw.conf (root-owned, mode 0600):

```bash
FIREWALL_MODE=host
IS_GATEWAY=0
NODE_NAME=vm-server
SSH_PORT=4100
SSH_HONEYPOT=on
SSH_HONEYPOT_PORT=22
SSH_HONEYPOT_PORTS="22,4200-4202"
SSH_HONEYPOT_AUTH_MODE=reject-all
SSH_FAILSAFE=on
SSH_FAILSAFE_MINUTES=10
HOST_RSYSLOG_FORWARD=on
DBSERVER=100.68.126.0
HOST_RSYSLOG_PORT=5514
MAX_LOGS_PER_MIN=10
MAX_BURSTS=20
```

First run `sudo bash misc/firewall.sh`. This configures logging/forwarding only; it does not move SSH or start a honeypot. Compare IPv4/IPv6 FORWARD and NAT rules before and after. Retest admin login on the original port.

Provision SSH explicitly with `sudo bash misc/firewall.sh /etc/tarasecfw.conf --setup-ssh`. The existing SSH helper stages a second admin listener and arms timed rollback before migrating SSH. Reconnect from another computer on the configured SSH_PORT. Only after that succeeds, cancel `tarasec-ssh-rollback.timer`. Keep the original terminal open. Host rollback restores SSH/honeypot state without restoring a global firewall snapshot over changing VM rules.

Host honeypots listen directly on at most 64 configured ports, without NAT redirects. Reserve those ports for the host; preflight refuses occupied extra ports and overlapping admin/decoy settings. The current Python honeypot binds IPv4. IPv6 decoy attempts are logged but do not reach an IPv6 honeypot listener. Large ranges supported by gateway redirects cannot be used here. Do not select VM port-forwarding ports as host decoys.

Install logging persistence with `sudo bash misc/install_node_firewall.sh`; its timer runs firewall.sh without provisioning arguments. It never repeats SSH migration. Reboot testing must check admin access, listener state, telemetry receipt, and VM networking.

TCP/5514 sends SSH (including sshd-session), honeypot and host probe logs to the provenance-preserving remote archive. The DB receiver and normalizer must already be configured. Archive/database receipt is not proof of an alert or automatic tagging. Probe logs deliberately do not claim every connection is an attack.

Forwarding is managed in /etc/rsyslog.d/33-tarasec-host.conf and validated before restart. HOST_RSYSLOG_FORWARD=off removes only that managed file. Remove older overlapping manual forwarding snippets separately after verifying delivery, to avoid duplicate records. No VM host package installation or firewall persistence snapshot is performed by host mode.
