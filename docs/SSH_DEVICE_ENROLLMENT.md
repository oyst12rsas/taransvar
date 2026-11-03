# Automatic SSH device enrollment

Run one setup command on the device that will connect, from an updated taransvar checkout:

```bash
clear
# Machine: Acer (or Phone / Termux)
python3 ~/taransvar/misc/pair_ssh_device.py --server http://100.68.165.190 --user wt-gw1
```

Use the intended server's management address and Linux username. The default
device name is the local hostname; override it with --device Acer or --device Termux.
Manage → SSH in app 0.4.11 also provides a setup command for the selected server.

The helper discovers that exact server's enrolled reference, generates or reuses
the local Ed25519 key, signs a one-use challenge and submits the public key
automatically. Existing private keys are not overwritten or transmitted.
OpenSSH may ask locally for the private-key passphrase. Termux needs Python and
OpenSSH (`pkg install python openssh`). There are no pairing codes to transfer.

Keep setup running. In the already connected TaraSec app, open Manage → SSH.
The device request appears automatically with its name, server account and key
fingerprint. Compare the fingerprint with the helper and approve using your
Authenticator code, or reject in place. The existing Google management session
covers this approval; a separate website visit is unnecessary.

The existing worker installs the exact approved key subject to its current local
manager policy and allowed-account list. The app and helper report installation
only after the worker reports done. Approval alone is shown as waiting for the
server. Rejection, expiry and failure stop the helper with an error. Requests
expire after ten minutes without approval; each private receipt lasts at most
30 minutes. Public submissions prove possession but grant no installation authority;
rate, capacity, server binding and existing MFA checks apply.

The no-argument helper and old app-issued pairing protocol remain supported for
older apps. New devices should use --server for the automatic flow.

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

This also publishes /script/sshDeviceTarget.php through the updated approval installer.
That unauthenticated endpoint returns only the root-owned enrollment identity, with
no manager session, status, SSH controls or credentials.

This updates the existing approval worker with --update-only and stores the
node-owned account allowlist in root-only /etc/tarasec/ssh-key-enrollment.json.
It retains local manager policy, existing keys, SSH authentication and firewall
rules. No enrollment request can add root or invent a new account. Standard
worker upgrades include the helper and retain that explicit opt-in policy.
The normal worker polls approved jobs at its existing cadence. If Google-window
fast polling is disabled, enrollment still works through the minute worker.

Install Android app 0.4.11 or newer for device approval. Older apps and approval
operations retain their existing protocols. A custom approval deployment is
supported with --service https://host/ops/agent/; the app displays the matching
service command. Approval service URLs must be HTTPS; redirects are rejected. Server identity discovery may use the node's existing HTTP management address. The helper requires the discovered service to match its explicitly configured --service; device enrollment always goes to that HTTPS service.

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
