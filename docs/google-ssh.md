# Google-authorized temporary SSH

This optional node policy rejects new admin SSH connections unless an operator
explicitly approves a bounded opening through Google sign-in and TOTP on the
approval service. Signing in alone does not open SSH. Established connections
survive closure; console access is the recovery route. SSH authentication and
SSH_ALLOWED_SOURCES remain additional conditions. There are no permanent trusted,
recovery-address or loopback exemptions while the gate is closed.

## Automatic node and manager enrollment

Run on the server being enrolled, with sudo:

```bash
clear
cd ~/taransvar
sudo bash misc/install_agent_approvals.sh --enroll-google
```

The script asks for the authorized Google email. An interactive installation
without flags also offers enrollment; Enter keeps the existing enrollment.
For unattended use, pass `--email owner@example.com` (repeat for other managers).
`--no-enrollment` preserves existing identity and permissions without prompting.
Root access is the owner's authority to select this server's managers; no TaraSec
team enrollment approval is required.

The helper creates a unique root-only node credential when absent, retains it on
retry, and sends only normalized manager email SHA-256 hashes plus the public
nickname to `https://tarasec.org/ops/agent/enroll.php`. Existing registered tokens
retain their original private node ID. New identities are derived from their
credential; a submitted node ID cannot claim another server. Central stores the
credential hash and manager hashes in its private persistent state, without
rewriting `/etc/tarasec/agent-approvals.php` or adding global operator privileges.
Manager hashes identify accounts; they remain personal data, not proof of identity.

Sign in with the enrolled Google account at the approvals page. Newly enrolled
managers can set up and verify their authenticator there; Google sign-in alone
never approves an SSH opening. Existing configured authenticators are preserved.
Each approval still sends the verified deciding account hash back to the server,
which independently checks its current local policy.

Enrollment adds managers to existing local grants; it does not silently remove
other owners. To revoke managers, edit `/etc/tarasec/access.conf` and publish the
current list (including an empty list to revoke all central grants):

```bash
clear
sudo python3 /usr/local/lib/tarasec/enroll_google_manager.py --sync
```

For a private approval host, add `--approval-url https://approval.example.org/ops/agent/api.php`.
Enrollment and both approval pollers use this same HTTPS endpoint through
`AGENT_APPROVAL_URL` in `/etc/tarasec-server-manager.conf`. Redirects are refused.
Deploy the central endpoint before enrolling nodes. Connection failure leaves
SSH unchanged and retains the local credential for retry; it does not report
successful enrollment. The success receipt is `/etc/tarasec/agent-enrollment.json`.

Enrollment **does not close SSH**. Follow the enable/verify and console-recovery
steps below after confirming Google sign-in, MFA and independent console access.
This implementation retains five-minute approved windows and five-second local
polling without model calls. Test actual closed/open/expiry behavior per node.

On the approval host deploy `_enrollment.php`, `enroll.php`, `_totp_setup.php`
and the compatible `_common.php`, `api.php` and `index.php` from the website
repository. The existing live API/UI can contain local SSH changes; back them up
and merge their changes rather than overwriting them with an older checkout.
Central configuration still needs the Google web client ID and writable private
`state_dir`; a static node token or global operator email is no longer required
for self-enrolled nodes. `self_enrollment_enabled=false` disables registration
updates if an operator chooses that deployment policy.

## Manual enrollment (existing deployments)

Keep a working independent VM/local console open. Inspect the effective sshd
listener, authentication, and both firewall families. Install Python 3,
iptables/ip6tables (conntrack support) and systemd/systemd-run. All program files
come from this repository; do not copy installed code, tokens or policies from
another node.

Set a public, non-identifying AGENT_PUBLIC_NICKNAME in /etc/tarasecfw.conf.
SSH_PORT must match the real admin listener, and SSH_ALLOWED_SOURCES must contain
explicit comma-separated IPv4/IPv6 addresses or CIDRs. Empty and /0 lists are
refused. The corresponding family must also allow legitimate SSH through its
baseline rules: the Google window returns to that policy rather than bypassing
other firewall restrictions. Do not overlap the admin and honeypot ports.

Generate a unique root-only /etc/tarasec/agent-node.token containing 64 random
hex characters. Register SHA-256 of the token text **without its trailing newline**
in the approval host's /etc/tarasec/agent-approvals.php:

```php
'node_token_hashes' => [
    // Preserve existing entries. This is a private ID, not the display nickname.
    'unique-private-node-id' => 'SHA256_HEX_OF_THIS_NODES_TOKEN',
],
```

For a new node, generate the token without overwriting an existing identity and
print only its registration hash:

```bash
clear
sudo python3 - <<'PYTHON'
from pathlib import Path
import hashlib, os, secrets
folder = Path('/etc/tarasec')
folder.mkdir(mode=0o700, exist_ok=True)
path = folder / 'agent-node.token'
if not path.exists():
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as target:
        target.write(secrets.token_hex(32) + '\n')
token = path.read_text().strip()
if len(token) != 64 or any(c not in '0123456789abcdef' for c in token):
    raise SystemExit('Malformed existing token; left unchanged')
print('Token SHA-256:', hashlib.sha256(token.encode('ascii')).hexdigest())
PYTHON
```

Never transmit the raw token. Back up the server configuration before editing,
then run `sudo php -l /etc/tarasec/agent-approvals.php`. Explicit manager enrollment through `install_agent_approvals.sh --enroll-google`
also configures Google browser handoff when Gatekeeper is deployed locally; see
[GATEKEEPER_GOOGLE_LOGIN.md](GATEKEEPER_GOOGLE_LOGIN.md). Legacy manual
`gatekeeper_clients` entries remain supported.

The deployed approval service must support Google/TOTP approvals for the typed
operation `open_ssh_temporarily`, returning approved_operator_sha256, a 32-hex job ID, nonce, and integer
Unix `open_until` deadline in authenticated poll results. Some website repository
revisions support only obsolete-unit approval: deploy a compatible operator
service first. The node does not manufacture approvals or invent a deadline.

## Install or upgrade

On the intended node, from a clean checkout:

```bash
clear
cd ~/taransvar
git switch main
git pull --ff-only
sudo bash misc/install_agent_approvals.sh
sudo systemctl start tarasec-agent-approvals.service
sudo journalctl -u tarasec-agent-approvals.service -n 30 --no-pager
```

The installer validates all Python source files before replacing code, creates a
root-only program/policy backup, and preserves existing node tokens and policies.
It installs the helper as /usr/local/lib/tarasec/ssh_google_window.py and retains
coordinator scheduling if that service is enabled. Otherwise it enables the
standalone five-second lightweight approval poller and a separate one-minute
assessment timer. The lightweight poller collects no health evidence and makes no
model calls; it does nothing while Google SSH is disabled. Five seconds is the
interval after a completed poll, not a network-latency guarantee. Inspect it with
`systemctl status tarasec-ssh-approval-poll.timer --no-pager` and
`journalctl -u tarasec-ssh-approval-poll.service -n 20 --no-pager`.
Coordinator installations retain their existing five-second dispatch and do not
enable a second polling timer. Do not reinstall an older checkout: it can
replace the Google-capable worker with one that rejects opening jobs.

With the policy absent/no, installation does not close SSH. If already enabled,
the next worker/coordinator pass reconciles the existing policy. Do not enable
both the app timed gate and Google gate: their separate leases can block each
other. This guide concerns Google opening, not setup_manager_ssh.sh --enforce-gate.

Confirm registration by checking the operator page displays this node's fresh
nickname/status (not merely a successful systemd exit). With console recovery
available, prepare to test Google/TOTP opening immediately after enablement.

## Node-owned manager policy

Before enabling opening approvals, edit `/etc/tarasec/access.conf` as root:

```ini
MANAGER_EMAILS="owner@example.com,colleague@example.com"
MANAGER_EMAIL_SHA256=""
```

The file must be root-owned, regular, not a symlink, and mode 0600 (0400 also
accepted). To store only identifiers, put comma-separated SHA-256 hashes of
trimmed, lowercase ASCII emails in MANAGER_EMAIL_SHA256. Both lists are combined;
no Gmail alias/plus/dot rewriting occurs. Empty, absent, malformed or insecure
policy denies approved operations. The installer creates an empty file only if
absent and preserves existing grants. The template is misc/access.conf.example.

Raw local email lists and the access file never leave the node. Explicit self-enrollment
publishes their hashes; manual enrollment can retain the earlier local-only model.
Central sends only the deciding
operator's hash in each authenticated job as approved_operator_sha256, calculated
from its own verified Google/TOTP decision, not node input. Both poll paths check
current local policy before every operation. The SSH helper checks again inside
its firewall lock. Revocation denies subsequent jobs immediately; reconciliation
closes any opening whose operator is no longer allowed. Existing SSH sessions
remain connected. No grants are automatically copied from central configuration.

A hash is an account identifier, not proof of identity or a secret. The node still
trusts the configured HTTPS approval service to verify Google/TOTP and bind the
operator to the node-specific job/deadline. This protects against a central
operator assignment mistake; it does not protect against a compromised approval
service that fabricates identities. Independent locally verifiable identity
proofs would be a separate protocol change.

For self-enrolled nodes, central visibility/Gatekeeper grants use the published
manager hashes and cannot display the original email list. A new registration
replaces older static per-node grants for that identity, including when the new
list is empty. For manually enrolled nodes, the existing per-node central grants
remain separate. Both central grants and current local execution grants must
allow the operator.

Deploy the central identity-hash job payload first, then install the node update,
configure the local policy and test an approved opening. Older API jobs without
the identity hash are deliberately rejected. Older local active-window state
without a hash is closed on reconciliation; keep console access during upgrade.
On a newer locally modified live API, port the payload addition into its real
poll handler instead of replacing that API with an older repository revision.

## Enable and verify

Back up /etc/tarasec-server-manager.conf and use sudoedit to set exactly one active
entry:

```ini
SSH_GOOGLE_REOPEN_ENABLED=yes
```

From the console apply it:

```bash
clear
sudo python3 /usr/local/lib/tarasec/ssh_google_window.py reconcile
sudo systemctl start tarasec-agent-approvals.service
sudo iptables -S INPUT
sudo iptables -S TARASEC_SSH_GOOGLE
sudo ip6tables -S INPUT
sudo ip6tables -S TARASEC_SSH_GOOGLE
```

The NEW-only jump must precede broad VPN/interface/source ACCEPT rules in both
families. Closed chains end in REJECT without source RETURN entries. No INPUT
flush, NAT, FORWARD or sshd configuration change is performed. On errors, retain
the console and examine the journal; do not assume the policy applied.

Test a fresh SSH connection with connection multiplexing disabled: it must fail
while closed. Sign in at https://tarasec.org/ops/agent/, explicitly approve opening
for this node with TOTP, wait for the worker/coordinator to apply it, then verify
a fresh login from an allowed source. A deadline must be in the future and at
most 900 seconds away. Verify another source is rejected, window expiry rejects
new connections, and the established session survives. Inspect both IP families.

A separate transient systemd timer schedules closure before allowance is applied.
It does not depend on the assessment worker continuing to run. This is a
systemd-based expiry mechanism, not a kernel ipset timeout. State is root-only
under /run/tarasec and lost on reboot. Reconciliation on the next worker pass then
installs a closed gate. The standalone timer starts after boot; this does **not**
guarantee a closed gate before the first network/sshd listener becomes available.
Verify boot firewall ordering separately before claiming uninterrupted boot-time
protection. Firewall replacement can also remove the hooks until the next pass.
A timer/reconciliation outage needs operator attention.

## Console recovery

First set SSH_GOOGLE_REOPEN_ENABLED=no to prevent the worker rebuilding the gate.
From the console remove only the owned INPUT hooks, in both families, using the
exact NEW-only jump shown by `iptables -S INPUT` / `ip6tables -S INPUT`. Also remove
any `tarasec-google-ssh-rebuild` guard left after an interrupted update. Do not
flush INPUT, change global policies or touch forwarding. Disabling the flag alone
intentionally does not silently reopen a previously closed firewall.

Retest the baseline SSH policy from an authorized source. Restore backed-up code
and policy if needed. Re-enabling requires repeating close/open/expiry checks.
Live per-node acceptance is separate from the repository's automated tests.


## SSH countdown confirmation latency

Successful opening and closure jobs attach bounded, checked SSH state to their
completion receipt. The hosted dashboard can therefore display the opening expiry
on its next five-second refresh instead of waiting for the minute health reporter.
This reports observed enforcement; approval alone never starts a countdown.

Deploy the companion hosted approval API/UI first, then update the node approval
worker bundle using the maintained installer. Existing local manager policy is a
prerequisite for update-only mode. Older hosted APIs ignore the optional evidence
and retain minute-heartbeat behavior. Missing post-execution evidence still permits
an execution receipt, so a status read failure does not cause a completed job to be
repeated. SSH-only receipts do not refresh unrelated health-report timestamps.
