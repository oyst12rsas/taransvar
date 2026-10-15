# Google-authorized temporary SSH

This optional node policy rejects new admin SSH connections unless an operator
explicitly approves a bounded opening through Google sign-in and TOTP on the
approval service. Signing in alone does not open SSH. Established connections
survive closure; console access is the recovery route. SSH authentication and
SSH_ALLOWED_SOURCES remain additional conditions. There are no permanent trusted,
recovery-address or loopback exemptions while the gate is closed.

## Prerequisites and enrollment

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
then run `sudo php -l /etc/tarasec/agent-approvals.php`. Gatekeeper browser handoff
(`gatekeeper_clients`) is a separate feature and does not enroll a node.

The deployed approval service must support Google/TOTP approvals for the typed
operation `open_ssh_temporarily`, returning a 32-hex job ID, nonce, and integer
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
standalone one-minute approval timer. Do not reinstall an older checkout: it can
replace the Google-capable worker with one that rejects opening jobs.

With the policy absent/no, installation does not close SSH. If already enabled,
the next worker/coordinator pass reconciles the existing policy. Do not enable
both the app timed gate and Google gate: their separate leases can block each
other. This guide concerns Google opening, not setup_manager_ssh.sh --enforce-gate.

Confirm registration by checking the operator page displays this node's fresh
nickname/status (not merely a successful systemd exit). With console recovery
available, prepare to test Google/TOTP opening immediately after enablement.

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
