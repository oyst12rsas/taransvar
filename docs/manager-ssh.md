# Temporary SSH from the Android manager

The app triggers opening and closing; the conf file governs who may connect.

The node management views are Status, AI, Assistance and SSH. Status describes
only the selected node and its clients. Network assistance is independent of AI.
Management approval and reconnection appear when required, rather than in an
otherwise empty Access view.

## Optional installation on a standard gateway

Install `ipset` first if missing (`sudo apt-get install ipset`). From a current
checkout run `sudo bash misc/setup_manager_ssh.sh` on the gateway. This is an
explicit owner opt-in. The script checks the configured SSH port, an actual
sshd listener and a recognised TaraSec INPUT policy before installing anything.
It retains the baseline firewall, authorized keys, passwords and recovery
sources. It does not request an opening. It backs up existing web endpoints and
service units under `/var/backups/tarasec-manager-ssh.*`.

The PHP web server does not read `/etc/tarasec` or execute privileged commands.
`managerSsh.php` requires an active manager session, rechecks the approval row
and expiry, requires a session CSRF token for POST, and queues only open/close
requests. It records the requester from REMOTE_ADDR for audit, never a supplied address
or forwarded header. This requester is not used as a new SSH source restriction:
the app replaces the web opening action, and SSH can then be used from a computer
permitted by the existing conf policy. The temporary firewall rule is IPv4.

A root systemd worker rechecks approval and request freshness, then applies a
port entry in `tarasec_app_ssh`, an ipset with a kernel timeout. Openings
are limited to 300, 600 or 900 seconds, also capped by manager approval expiry.
Revocation is checked every worker pass. The rule is inserted at the SSH policy
boundary after earlier global and source-specific deny rules. Missing or
unrecognised firewall policy is an error, not a top-of-chain bypass.

The temporary rule uses a separate chain with the exact IPv4 address/CIDR
restrictions from `SSH_ALLOWED_SOURCES` in `/etc/tarasecfw.conf`. Empty means
unrestricted, matching the existing firewall semantics; unsupported entries fail
closed. Recovery rules remain separate. The app does not choose or replace the
allowed sources.

Public state is atomically written to `/run/tarasec-app-ssh/status.json`, root
owned and world readable, with no credentials. The API returns the gateway's temporary
opening state. State older than 30 seconds is unavailable. The worker's database
configuration is root-only. No changes to the old `sshControl.php` endpoint are
made; that endpoint is a separate owner-configured legacy interface.

## Status and limitations

The SSH tab offers 5, 10 and 15 minute controls, a countdown, queued/rejected
request state and End temporary opening. Open/Closed refers to this temporary
firewall allowance, not a completed SSH login. The configured port is shown for
use in an external SSH client. SSH login credentials are still required.

Expiry does not depend on the app or worker staying alive. A worker failure can
delay processing or revocation but cannot extend an already accepted kernel
lease. Existing baseline/recovery rules may independently permit SSH after a
temporary lease expires. Expiry removes the allowance; existing connections
remain subject to the owner's conntrack and firewall rules. This feature does
not change those rules, restart sshd, widen configured sources, or close recovery.
If changing the configured SSH port, end all temporary windows and flush the
temporary set before changing the node's authoritative configuration.

## Live acceptance and recovery

Keep an independent recovery login. Confirm the node's effective sshd listener,
key login, IPv4/IPv6 firewall and baseline access policy before relying on timed
access. In the app choose SSH, request 5 minutes, wait for confirmation, and
connect from a computer allowed by the conf to the displayed port. Check another unapproved peer is
denied under the baseline policy. End the temporary opening and inspect the set.
Repeat a five-minute opening, stop the worker, and verify the set entry expires
without it. Verify actual new SSH connections after expiry, not only the label.
Live acceptance on wt-qw1 is pending; CI checks authorization, SQL queue behavior
and kernel port timeout and configured-source isolation in a disposable network namespace.

To disable the optional feature without changing baseline or recovery policy:

```sh
sudo rm -f /etc/tarasec/manager-ssh.enabled
sudo systemctl disable --now tarasec-manager-ssh.timer
sudo systemctl stop tarasec-manager-ssh.service
sudo ipset flush tarasec_app_ssh
```

An empty set grants no access. Restore backed-up web endpoints/service files if
needed. Do not flush INPUT or replace the owner's firewall to undo this feature.
Baseline review: https://tarasec.org/safety/#deployment and
https://tarasec.org/safety/#hardening-priorities.
