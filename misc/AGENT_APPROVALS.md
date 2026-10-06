# Operator-approved node changes

TaraSec operates the live approval page at
`https://tarasec.org/ops/agent/` for configured operators. Its companion
public page at `https://tarasec.org/status/` displays
only short health labels and the pseudonym each node supplies from
`AGENT_PUBLIC_NICKNAME` in `/etc/tarasecfw.conf`. Avoid hostnames, IP
addresses, personal names, and location clues in that nickname.

The node's worker makes outbound HTTPS requests to TaraSec; no inbound SSH
or agent port is opened. A distinct random 32-byte token identifies each
node. TaraSec registers only its SHA-256 hash. The node installer does not
create an operator account or enroll a node in the live service; arrange
registration with a TaraSec operator before expecting the worker to report.

For each node:

1. Put a non-identifying nickname in `/etc/tarasecfw.conf` as
   `AGENT_PUBLIC_NICKNAME="Blue Lantern"`.
2. Generate `openssl rand -hex 32` into
   `/etc/tarasec/agent-node.token`; owner root, mode 0600.
3. Give the SHA-256 hash of the node token and its intended public nickname
   to a TaraSec operator for registration. Keep the raw token on the node;
   never send or publish it. A node that is not registered receives
   `node_not_registered` from the live service.
4. Install with `sudo bash misc/install_agent_approvals.sh` from a current
   taransvar checkout. The installer copies scripts to a root-owned path
   and enables a one-minute systemd timer. Inspect its journal before
   allowing any operations.

The first operator-approved executable proposal is
`disable_obsolete_gateway_unit`. The
worker proposes it only on a non-gateway with a failed, non-executable,
enabled `tarasec-gateway.service` while `netfilter-persistent` is active
and enabled. It rechecks these conditions after approval. The operation
only disables the obsolete unit and clears its failed state; it does not
run the firewall script, reload SSH, or modify live rules. All other
findings produce an attention status for operator review.

Only the operator can approve a proposal. Node credentials can submit a
proposal and retrieve an approved job but cannot approve it. Google token
signature, issuer, audience, expiry, verified email and operator allowlist
are checked server-side. Each approval applies to one typed operation and
expires in one hour. The worker rejects unknown operations and logs the
result to its systemd journal.

The worker uses deterministic checks to generate proposals. An AI model is
not connected by the node installer. Additional typed operations would need
explicit local checks and recovery behavior.

## Server-manager policy, prompt, and knowledge

The installer also installs `/etc/tarasec-server-manager.conf`, a versioned
agent prompt, and a small trusted knowledge document. The prompt and knowledge
are the initial retrieval corpus; current host state continues to come directly
from bounded local diagnostics rather than cached documents.

### Configuration reference

Edit the installed `/etc/tarasec-server-manager.conf` on the **intended node**.
The installer creates it only if absent, so later installs preserve local
policy. The worker reads the file on each run. The following are all keys in
the supplied example; values shown are defaults.

| Setting | Values and current effect |
| --- | --- |
| `AI_AGENT_MODE=conservative` | Assessment label only. `conservative` is the only documented value. This key does not grant privileges or select an LLM. |
| `AI_STATUS_REPORT_ENABLED=yes` | Include the latest agent summary in the normal per-minute node status. Set `no` to send `"aiAgent":"report disabled"` instead. This does not stop the worker or its separate approval heartbeat. |
| `TERMINAL_AVAILABLE=no` | Boolean declaration of a genuine independent recovery console. SSH on the protected port does not qualify. |
| `TERMINAL_HEARTBEAT_FILE=/run/tarasec/operator-terminal.heartbeat` | Local path whose modification time proves that an operator recently checked the console. Do not refresh it automatically. |
| `TERMINAL_HEARTBEAT_MAX_AGE_SECONDS=120` | Maximum age, clamped to 30–3600 seconds. Console is verified only when declared and fresh. |
| `AI_MAY_CLOSE_SSH_DURING_ACTIVE_ATTACK=no` | Boolean permission to temporarily reject **new** admin-SSH connections after the attack threshold and console checks. |
| `AI_MAY_TERMINATE_EXISTING_SSH_SESSIONS=no` | Independent boolean permission to SIGTERM established admin-SSH sessions after the same checks. A legitimate session may be disconnected. |
| `SSH_ATTACK_WINDOW_SECONDS=120` | Journal lookback, clamped to 60–900 seconds. |
| `SSH_ATTACK_FAILURE_THRESHOLD=20` | Minimum matching journal lines, clamped to 5–10000. Counts lines, not distinct attackers. |
| `SSH_CONTAINMENT_SECONDS=600` | New-connection rejection duration, clamped to 60–3600 seconds. Established-session termination is not timed. |
| `AUTH_PASSKEY_ENABLED=yes`, `AUTH_TOTP_ENABLED=yes`, `AUTH_EMAIL_VERIFICATION_ENABLED=yes`, `AUTH_SMS_VERIFICATION_ENABLED=no`, `AUTH_OIDC_ENABLED=yes`, `AUTH_RECOVERY_CODES_ENABLED=yes` | **Declarations only** in this file. The node worker does not read these settings. The operator website currently enforces its own Google login and TOTP configuration. |
| `AUTH_DANGEROUS_ACTION_MINIMUM=phishing_resistant_mfa` | **Declaration only**; does not alter the website's approval policy. |

For worker action flags, `yes`, `1`, `true`, and `on` enable a
setting (case-insensitive); absent or other values leave it disabled.
Malformed integers use defaults and out-of-range integers are clamped.
`SSH_PORT` and `IS_GATEWAY` come from `/etc/tarasecfw.conf`, while
`AGENT_PUBLIC_NICKNAME` identifies the node on the public status page.
Changes to the worker or prompt files require rerunning the installer;
policy value changes are read on the next timer run.

The worker writes `/var/lib/tarasec/agent-status.json` after each assessment.
The normal `crontasks.pl` status includes `aiAgent` when the manager is installed:
mode, overall status, bounded operator messages, SSH protection checks, attack
activity, recovery-console verification, forwarding health, bounded actions,
approval-service connection and assessment age. The assessment has no IPs,
port numbers, tokens, usernames or transcript. If no recent snapshot exists,
the report says unavailable or stale rather than reusing old findings as live
status. After updating the checkout on an installed node, rerun
`sudo bash misc/install_agent_approvals.sh` to install the updated worker and
sync `misc/crontasks.pl` to the installed `/root/taransvar/perl/crontasks.pl`.

The worker currently uses deterministic checks and sends a structured
assessment. The prompt and knowledge documents are installed for a future
model integration, but no model runs because of this configuration alone.
TaraSec manages the live approval page, operator accounts, node enrollment,
and authenticator setup separately. Node configuration changes do not change
website login or approval requirements.

`TERMINAL_AVAILABLE=yes` declares that a local or out-of-band recovery terminal
exists. It is considered usable only while
`TERMINAL_HEARTBEAT_FILE` is newer than
`TERMINAL_HEARTBEAT_MAX_AGE_SECONDS`. An operator at that terminal can refresh
the default heartbeat with:

```bash
sudo install -d -m 0755 /run/tarasec
sudo touch /run/tarasec/operator-terminal.heartbeat
```

When `AI_MAY_CLOSE_SSH_DURING_ACTIVE_ATTACK=yes`, a fresh terminal heartbeat
and the configured authentication-failure threshold permit the agent to reject
only **new** connections to the real administrative SSH port for a bounded
period. Existing sessions and the FORWARD chain are unchanged. A transient
systemd timer removes the rule; if that timer cannot be created, the worker
immediately removes the rule and reports a safe failure.

`AI_MAY_TERMINATE_EXISTING_SSH_SESSIONS=yes` separately permits the agent to
send SIGTERM to `sshd` processes owning established connections on the
administrative SSH port while the same attack and console guards are true. It
does not stop the listener or touch forwarded sessions. Because this can
disconnect a legitimate administrator, it is disabled by default; the action
report includes the aggregate number of sessions terminated.

The `AUTH_*` keys record intended authentication capabilities; they do not
enable methods on TaraSec's live approval page. That page currently uses
Google sign-in and a separate authenticator code for approvals. TaraSec must
enforce any stronger approval policy in the live service itself.

## Google-controlled temporary SSH access

Deploy the companion operator-site PR first, then update the node checkout.
This mode is opt-in and is separate from temporary attack containment.
The node continues polling over outbound HTTPS while SSH is closed.

On the intended node, set `SSH_GOOGLE_REOPEN_ENABLED=yes` in
`/etc/tarasec-server-manager.conf`, and set the real `SSH_PORT` and an explicit
comma-separated IP/CIDR `SSH_ALLOWED_SOURCES` in `/etc/tarasecfw.conf`.
An empty allowlist or /0 is refused. Run
`sudo bash misc/install_agent_approvals.sh` from a recovery console: enabling
this mode immediately closes NEW admin SSH connections in both IPv4 and IPv6.
The boot service restores closure after reboot; the minute worker repairs
its INPUT hooks after firewall refreshes. No NAT or FORWARD rules are changed.
A firewall manager which continually replaces INPUT rules must be coordinated
with this service; the minute repair is not a substitute for testing that integration.

At https://tarasec.org/ops/agent/, sign in with an allowlisted Google account.
Find the node's “Open SSH temporarily” proposal, choose 5, 10 or 15 minutes,
enter a fresh authenticator code and approve. The window runs from approval,
not job delivery; polling delay reduces usable time. Approval is not proof of
execution: check the job state and try the real SSH connection.
Normal SSH credentials are still required and only configured sources may connect.
Allowed sources return to the existing firewall rules; the baseline firewall must
permit the configured admin port for those sources. An opening never bypasses
another firewall rejection, including attack containment.

Closure is scheduled locally before any opening rule is installed.
Retries do not extend the deadline. Reboot loses the window and returns to
closed. Expiry blocks NEW connections; existing authenticated SSH sessions
remain connected. The independent attack policy can still reject connections
during a window. Disabling the opt-in flag does not remove installed firewall
protection; use the recovery console to deliberately change this policy.

Before production use, verify IPv4 and IPv6 closure, authorized opening,
unlisted-source rejection, expiry, website outage, worker restart, reboot,
and coexistence with NetBird/firewall refreshes on the intended node.
