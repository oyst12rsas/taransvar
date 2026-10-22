# SSH operator controls

The approvals page offers typed node actions, not a remote command box. Alongside
requesting a temporary opening, enrolled managers can request **Close SSH and
disconnect sessions**. Approval requires Google identity, CSRF protection and a
fresh TOTP code. The proposal explains that legitimate SSH sessions are also
terminated and cannot be restored.

On the intended node, in `/etc/tarasec-server-manager.conf`, explicitly enable:

```ini
SSH_GOOGLE_REOPEN_ENABLED=yes
AI_MAY_TERMINATE_EXISTING_SSH_SESSIONS=yes
TERMINAL_AVAILABLE=yes
```

A VM/local console must actually be usable. `TERMINAL_AVAILABLE=yes` is the
owner’s persistent declaration of that recovery access; there is no heartbeat,
timeout, or requirement for an operator currently to be at the console. Start
the approval service once after updating policy to publish a fresh node report:

```sh
sudo systemctl start tarasec-agent-approvals.service
```

The UI enables the control only when a recent node report advertises permission
and owner-declared console availability. Execution reads the current local policy
again. Older nodes without the capability fields cannot select the control.
The local Google gate and local session-termination permission must both be on.

Execution first closes the Google SSH gate for IPv4 and IPv6. If gate closure
fails, sessions are not terminated. It then SIGTERMs sshd/sshd-session processes
owning established sockets on the configured admin SSH port. Other services and
ports are excluded. Permission or console-declaration changes during execution may leave SSH
closed without terminating sessions; the job reports failure rather than success.
The manual action checks that admin SSH sockets disappear after SIGTERM; lingering
sessions or an unavailable check report failure. This is still local evidence,
not independent proof against a compromised root user.

Approving closure cancels older opening proposals. Local state also records a
closure timestamp, rejecting stale opening jobs even if already fetched before
closure. A later deliberate opening needs a new approval after the closure.
An opening approved in the same clock second is rejected; request a fresh one.
These local revocation receipts live under /run and are reset on reboot, like
existing SSH window state. Boot-time firewall ordering remains a separate check.

## Automatic attack response

Session termination during an ongoing SSH authentication attack already uses:

```ini
AI_MAY_CLOSE_SSH_DURING_ACTIVE_ATTACK=yes
AI_MAY_TERMINATE_EXISTING_SSH_SESSIONS=yes
```

These remain explicit owner permissions, combined with the same owner-declared console
checks and the configured authentication-failure threshold. The manual action
requires no attack, since the verified operator is explicitly requesting it.
The legacy automatic temporary-REJECT path is IPv4-only; the new manual action
closes both families through the Google gate. Google approval of an SSH window
alone does not disable either enabled attack-response permission.

## Deployment

Update tarasec.org's `ops/agent/api.php` and `index.php`, then update the node
worker and `ssh_google_window.py` together using `install_agent_approvals.sh
--no-enrollment`. Existing policies are preserved. Configure the permissions
above only on the intended node with working independent console recovery.

Test on a console-accessible node: with TERMINAL_AVAILABLE=no the action is refused;
with yes, approve closure, verify fresh SSH fails on both families and the old
admin SSH sessions end, then request a new approved opening and verify recovery.
Unit/API tests are not a live SSH-disconnection acceptance claim.

## Privilege boundary

An SSH login does not inherently grant sudo. A compromised account with
unrestricted sudo/root can disable the agent, alter firewall rules, keep other
access paths, and falsify local acknowledgements or heartbeats. These controls
reduce exposure and contain attacks while the host remains trustworthy; they
cannot guarantee recovery after root compromise. Independently enforced network
isolation at a gateway, hypervisor or network controller is required for that
case. The current operator control does not implement external isolation.

