# Changing the private management SSH port

Recommended by AI; not yet reviewed or authorized by the TaraSec team.

This opt-in systemd/Linux migration retains the existing Google/TOTP approval
service and local manager/source policy. It does not change SSH authentication
methods, authorized keys or forwarding. Initial setup requires a working enrolled
MFA gate, an existing OpenSSH server, iptables/ip6tables, ipset and iproute2.
Run from the current full checkout; keep an independent console available.

## Run on the target server

```bash
clear
cd ~/taransvar &&
git pull --ff-only origin main &&
sudo bash misc/install_agent_approvals.sh --update-only &&
sudo python3 misc/ssh_port_setup.py
```

The script privately prompts for the new management port, then asks for decoy
ports/ranges. Enter retains configured decoys from SSH_HONEYPOT_PORTS in /etc/tarasecfw.conf.
If absent or containing only the backend listener, it defaults to 1000-5000. Lists such as 22,1000-5000 are supported. The management
port may be inside that range: management port 2345 with range 1000-5000 works.

The chosen port is not a command-line argument and is not echoed in the normal
setup summary. It still exists in local root-owned configuration, systemd/SSH
diagnostics and network traffic. This is exposure reduction, not a replacement
for keys, passwords and MFA.

SSH_HONEYPOT_PORT is the separate backend listener, normally 22; it cannot be
the real management listener. The script refreshes the existing simulator,
enables IPv4/IPv6 support and sets SSH_CLOSED_HONEYPOT=yes. Other live TCP
listeners and ports in TCP_PORTS or SSH_HONEYPOT_EXCLUDE_PORTS are reserved and
are not hijacked by a decoy range. Include future reserved services explicitly;
normal reconciliation discovers newly started listeners.

The existing MFA gate and all source restrictions still apply. Opening redirects
only configured sources to the real listener. Everyone else continues to reach
the honeypot. A kernel-expiring ipset exemption cannot outlive the approved
deadline. New connections return to the honeypot after expiry even if the
worker stops; established real SSH connections retain their conntrack mapping.

## Verify and confirm within ten minutes

A persistent systemd timer is armed before changing listener/firewall policy.
Any staging failure restores the previous configuration automatically. A staged
migration remains pending until a successful NEW real SSH login is confirmed.

Open a fresh MFA window. In Termux use the updated helper and the privately chosen
port, with --gateway/--user if several servers are saved. The helper's --connect
default is 5822, so a different management port needs --port.

From the new real SSH session run:

```bash
clear
sudo --preserve-env=SSH_CONNECTION python3 /usr/local/lib/tarasec/ssh_port_setup.py --confirm
```

Confirmation requires SSH_CONNECTION to identify the new server port. The old
session does not count. Without confirmation the ten-minute timer restores the
previous port, listener configuration and firewall, with MFA closed. To restore
immediately from a working session or console:

```bash
clear
sudo python3 /usr/local/lib/tarasec/ssh_port_setup.py --rollback
```

Backups stay private under /var/lib/tarasec/ssh-port-migration. Boot ordering makes
the SSH service depend on the closed-decoy policy; socket activation may queue a
connection before the service starts, but must not start real SSH without the
policy. Verify this on the actual distribution before treating boot behavior as
accepted. Migration requires the active SSH service to use KillMode=process to preserve
existing sessions when its master/listeners restart. Socket activation is detected
automatically. Fixed conflicting Port/ListenAddress
directives are refused rather than silently rewritten.

## Attack-aware opening

With closed decoys enabled, every new MFA opening also checks the local honeypot
journal. By default, five connection/credential events at the exact management
port in the last 60 seconds refuse opening. Ordinary probes on different decoy
ports do not meet that threshold. Optional root-owned settings are:

```ini
SSH_ATTACK_QUIET_SECONDS="60"
SSH_ATTACK_ATTEMPTS="5"
```

Quiet interval bounds are 30-600 seconds; threshold bounds are 2-100. No single
probe is a permanent lockout. Continued attacks can deny remote opening by
design: retain console recovery. Failed evidence reads, an inactive honeypot or
an overloaded evidence window also refuse opening. A new attack can begin after
the check; this is not an assertion of invulnerability. Existing connections are
not terminated by this guard.

Refusal details are currently in the approval worker journal; the app reports an
unsuccessful opening. No secret port number is sent as a new public status field.

## Compatibility and acceptance

Only the opt-in mode permits an overlapping SSH/honeypot range. Older setups keep
their collision check and original behavior. The updated firewall.sh recognizes
this mode, retains a closed gate while rebuilding, and restores MFA-aware
routing afterward. Use the installed policy bundle with the matching checkout;
the migration itself does not invoke a host-wide firewall flush.

Honeypot and real SSH host keys differ. Never disable host-key verification or
accept a changed host key merely to get past a closed-window decoy. Open the MFA
window and retry a NEW connection to real SSH. A decoy connection already open
stays a decoy connection.

CI verifies route selection for the overlapping range in an isolated kernel
network namespace, including IPv4/IPv6, trusted/untrusted sources, reserved
services, timeout-only closure and established-session survival. Installer tests
simulate systemd, file restoration and rollback ordering; a real systemd/SSH
migration and boot still require acceptance on the actual server.

Deployment checks: https://tarasec.org/safety/#deployment
Continuing priorities: https://tarasec.org/safety/#hardening-priorities
