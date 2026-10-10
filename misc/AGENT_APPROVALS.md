# Operator-approved node changes

TaraSec operates the live approval page at
`https://tarasec.org/ops/agent/` for configured operators. Its companion
public page at `https://tarasec.org/status/` displays
only short health labels and the pseudonym each node supplies from
`AGENT_PUBLIC_NICKNAME` in `/etc/tarasecfw.conf`. Avoid hostnames, IP
addresses, personal names, and location clues in that nickname.

The worker makes outbound HTTPS requests; no agent listening port is opened.
New installations generate a root-only RSA identity key and register a pending
request automatically. Public VPN membership and observed IP addresses grant
no permissions. Check the fingerprint shown in the node journal against the
operator registration page, then approve its reporting or operations role with
Google login and an authenticator code. Partner certification is separate.

After approval, the server returns a per-node credential encrypted to the
node's public key. Credentials expire after 24 hours and renew automatically.
The server stores only a token hash and encrypted credential. Revocation
immediately rejects subsequent API requests. Existing manually registered
credentials remain compatible and must be retired explicitly after migration.

The normal installers install this worker. For an existing node, run
`sudo bash misc/install_agent_approvals.sh`. Set `NODE_API_URL` in
`/etc/tarasec-server-manager.conf` to the configured DB-server HTTPS endpoint.
The compatibility default is the current website endpoint; new enrollment
requires deployment of its companion backend from `tarasec.org` first.
See `ops/agent/ENROLLMENT.md` in that repository for central deployment.
The timer retries pending enrollment without blocking local checks.

Central AI requests use only allowlisted health observations, never logs,
passwords, tokens or provider API keys. Assessments are advisory; the bounded
worker still executes only locally supported, individually approved operations.
The minute report includes both `bounded_checks` and `central_ai`, with its
original assessment timestamp and budget/unavailable state.

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

The worker uses deterministic checks to generate executable proposals.
Central AI assessments remain separate advisory evidence. Additional typed operations would need
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

The worker uses deterministic checks locally and can request an advisory
assessment from the central AI service. The provider key and model are
configured only on that service.
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
