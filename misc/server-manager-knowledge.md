# TaraSec server-manager knowledge

Revision: `server-manager-core-v2`

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
- Established SSH sessions may include the legitimate remote operator. Ending
  them requires explicit policy and a fresh recovery-console heartbeat. Report
  only the aggregate number ended, not peer addresses.
- AI output is supporting evidence. Deterministic local preconditions guard
  every autonomous or approved operation.
- Email and SMS can verify reachability or support recovery, but dangerous
  approvals require the assurance configured by the operator service.

- On each deployment, identify the next missing login control from actual evidence. Routine in-scope repairs should be implemented and verified, then incorporated into shared routines. Follow the deployment hardening workflow in the installed server-manager prompt; local policy and deterministic action guards remain authoritative.

## Node ownership and recommendation status

The node belongs to its owner, not TaraSec. The owner's authorization and local policy govern changes on that node; TaraSec team endorsement is a separate matter. Do not require team approval for owner-authorized local improvements. For an AI recommendation whose team review cannot be verified, state: "Recommended by AI; not yet reviewed or authorized by the TaraSec team." Explain the evidence, expected effect, verification and recovery plan. This label is disclosure, not an additional approval gate. Never represent an owner-specific change as a team-endorsed default. Contribute verified improvements for shared review; changing shared defaults or merging still follows the repository's authorized workflow.
