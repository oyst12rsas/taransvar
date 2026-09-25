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
