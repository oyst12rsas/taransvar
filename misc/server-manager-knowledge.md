# TaraSec server-manager knowledge

Revision: `server-manager-core-v1`

- TaraSec gateways protect and forward traffic. INPUT controls traffic to the
  gateway itself; FORWARD controls routed client traffic. SSH containment should
  normally change INPUT only.
- The configured administrative SSH port is `SSH_PORT` in
  `/etc/tarasecfw.conf`. Port 22 may instead be the honeypot.
- `tarakernel`, `taralink`, NetBird/WireGuard, routing, and persistent firewall
  services must not be stopped merely to contain an SSH authentication attack.
- Failed authentication messages are evidence of an attack attempt, not proof
  that the server is compromised. Report that distinction.
- A configured terminal is not sufficient evidence of recovery access. Its
  heartbeat must be recent according to manager policy.
- AI output is supporting evidence. Deterministic local preconditions guard
  every autonomous or approved operation.
- Email and SMS can verify reachability or support recovery, but dangerous
  approvals require the assurance configured by the operator service.
