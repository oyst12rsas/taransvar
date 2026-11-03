# App-initiated SSH device enrollment

The manager selects a server in Manage → SSH access, enters its local SSH
account and a device name, and taps Start device pairing. The connected app's
existing short-lived management grant is checked for that exact server. No
public key is pasted into the app.

On Acer or Termux, from an updated taransvar checkout, run:

```bash
clear
# Machine: Acer or Phone / Termux
python3 ~/taransvar/misc/pair_ssh_device.py
```

Termux needs Python and OpenSSH (`pkg install python openssh`). The helper prompts
for the app's ten-minute pairing code without echoing it. It creates an Ed25519
key if absent; existing keys are not overwritten. OpenSSH prompts locally for the
private-key passphrase as needed. Only the public key and a signed challenge are
sent over HTTPS. Keep the displayed pairing code private; it is a single-use
submission capability, never an installation approval.

The helper then directs the operator to https://tarasec.org/ops/agent/. The
pending approval identifies the device name, exact server, local account and
SHA256 key fingerprint. Google sign-in and a fresh Authenticator code are required
by the existing approval handler. A submission alone installs nothing. The
request expires five minutes after submission. A pairing can be used only once;
its signature binds the key to that pairing's account/server/challenge.

## Server setup and deployment

Deploy the companion tarasec.org changes first (ops/agent/ssh-device.php,
_ssh_device.php, _mobile.php, api.php and index.php). Use the normal hosted site
deployment; source presence does not demonstrate production deployment.

On each intended target server, explicitly opt in its actual non-root SSH user:

```bash
clear
# Machine: Squash
cd ~/taransvar &&
git pull --ff-only origin main &&
sudo bash misc/install_ssh_device_enrollment.sh squash
```

This updates the existing approval worker with --update-only and stores the
node-owned account allowlist in root-only /etc/tarasec/ssh-key-enrollment.json.
It retains local manager policy, existing keys, SSH authentication and firewall
rules. No enrollment request can add root or invent a new account. Standard
worker upgrades include the helper and retain that explicit opt-in policy.
The normal worker polls approved jobs at its existing cadence. If Google-window
fast polling is disabled, enrollment still works through the minute worker.

Install the updated Android app to initiate pairing. Older apps and approval
operations retain their existing protocols. A custom approval deployment is
supported with --service https://host/ops/agent/; the app displays the matching
service command. HTTP URLs and redirects are rejected by the device helper.

## Verification and recovery

The worker requires current local manager permission, an approved account and a
fresh centrally approved exact key/fingerprint. It rejects unsafe home paths,
symlinks, hardlinked authorized_keys and insecure ownership/permissions. Key
installation appends one plain Ed25519 key and is idempotent under retries.
Existing keys are retained. The operation never executes submitted shell text.

A completed approval means the key was installed, not that an SSH connection was
verified. Open the normal temporary SSH window and test a new key-authenticated
login from the device. Verify host identity independently as usual. Only after a
successful new login should the owner enable AuthenticationMethods
publickey,password using the existing guarded SSH policy procedure. This version
does not remotely infer login verification or automatically enforce it. Accounts
using custom AuthorizedKeysCommand or nonstandard AuthorizedKeysFile need their
existing owner configuration reviewed separately.

Revoke a device by removing its exact public key from that account's
.ssh/authorized_keys. Disable new enrollment by removing the account from the
local enrollment allowlist. Restoring the previous worker bundle is available
from the installer's private backup. Never publish private keys, node credentials,
pairing tokens or local manager policy files.
