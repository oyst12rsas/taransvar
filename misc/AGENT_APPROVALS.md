# Operator-approved node changes

The HTTPS page at `https://tarasec.org/ops/agent/` accepts Google sign-in
for configured operators. Its companion public page `/status/` displays
only short health labels and the pseudonym each node supplies from
`AGENT_PUBLIC_NICKNAME` in `/etc/tarasecfw.conf`. Avoid hostnames, IP
addresses, personal names, and location clues in that nickname.

The node's worker makes outbound HTTPS requests; no inbound SSH or agent
port is opened. A distinct random 32-byte token identifies each node.
The site stores only its SHA-256 digest in
`/etc/tarasec/agent-approvals.php`, mounted read-only into the web container.
Create that file from `ops/agent/config.example.php` in the tarasec.org
repository. Add the same read-only mount to the live compose file. Configure
Google Identity Services with the authorized JavaScript origin
`https://tarasec.org`, and set its Web client ID in the private PHP config.
The website's existing `/var/lib/tarasec` mount holds the approval state.

For each node:

1. Put a non-identifying nickname in `/etc/tarasecfw.conf` as
   `AGENT_PUBLIC_NICKNAME="Blue Lantern"`.
2. Generate `openssl rand -hex 32` into
   `/etc/tarasec/agent-node.token`; owner root, mode 0600.
3. Add `hash('sha256', token)` to the site's `node_token_hashes` with a
   private ID. The raw token must never go into Git or the public page.
4. Install with `sudo bash misc/install_agent_approvals.sh` from a current
   taransvar checkout. The installer copies scripts to a root-owned path
   and enables a one-minute systemd timer. Inspect its journal before
   allowing any operations.

The first executable proposal is `disable_obsolete_gateway_unit`. The
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

This first worker uses deterministic checks to generate proposals. The
AI can inspect its evidence and propose additional typed operations after
each new operation has an explicit local safety check and rollback.

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

The worker currently uses deterministic checks and sends a structured
assessment. The prompt and knowledge documents are installed for a future
model integration, but no model runs because of this configuration alone.
The site's Google client, operator allowlist, node hashes and TOTP secrets
are configured separately in the site's private
`/etc/tarasec/agent-approvals.php`.

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

The manager configuration also declares supported operator authentication
methods. Passkeys/FIDO, TOTP, OIDC, email verification, SMS fallback, and
recovery codes are separate capabilities. The operator website must enforce
`AUTH_DANGEROUS_ACTION_MINIMUM`; merely enabling email or SMS must not weaken
approval requirements.
