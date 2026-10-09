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

## Enforcing the timed gate (existing installations)

The original installation added a temporary allowance while leaving the permanent
SSH ACCEPT path intact. This was not a closed-by-default gate: a permitted source
could still reach the baseline ACCEPT rule with no active app window.

The correction is an explicit `setup_manager_ssh.sh --enforce-gate` upgrade. Before
running it, use the app to request a five-minute opening and wait for confirmation.
The installer checks both the active kernel lease (at least 60 seconds remain)
and its latest applied, still-authorized manager request before changing access.
It retains the current SSH authentication settings and backs up the old worker.

The gate is reached for every connection to the configured IPv4 SSH port, before
`TARASEC_SSH_SOURCE` or the permanent ACCEPT rule. Its order is loopback,
established/related sessions, explicitly configured recovery sources, configured
allowed sources with an active kernel lease, then a final REJECT. Ordinary allowed
sources cannot fall through to permanent ACCEPT after expiry. Earlier global
security drops remain ahead of the gate. The existing source list is not rewritten.
The gate is rebuilt by the worker after a normal firewall refresh. IPv6 new remote
SSH is rejected because this version supports only IPv4 configured sources;
IPv6 loopback and established sessions remain available.

A ten-minute rollback timer is installed before enabling the gate. If acceptance
is not confirmed, it stops the worker, removes only the owned IPv4/IPv6 gate
rules and restores the pre-existing SSH path. It also runs after reboot unless
confirmed. Keep the original terminal, test a new login during an opening, end the
opening, verify a new connection is refused, then reopen from the app and verify
a new login. After those checks run `setup_manager_ssh.sh --confirm-gate` to stop
and disable the rollback timer. This is an operator verification step, not an
additional authorization request.

Manual rollback is available at
`sudo bash /usr/local/share/tarasec/manager-ssh/misc/manager_ssh_rollback.sh`.
Public status includes `gateEnforced`; no app rebuild is required for the upgrade.

## Status and limitations

The SSH tab offers 5, 10 and 15 minute controls, a countdown, queued/rejected
request state and End temporary opening. Open/Closed refers to this temporary
firewall allowance, not a completed SSH login. The configured port is shown for
use in an external SSH client. SSH login credentials are still required.

Expiry does not depend on the app or worker staying alive. A worker failure can
delay processing or revocation but cannot extend an already accepted kernel
lease. With the gate enforced, ordinary allowed sources require an active window
for a new connection. Explicit recovery sources and established sessions remain
available after expiry. Without gate enforcement the legacy baseline may still
permit SSH. The worker does not restart sshd or alter SSH login credentials.
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
and production gate enforcement in a disposable network namespace, including the
actual permanent ACCEPT bypass, established sessions, explicit recovery, expiry,
reopening, IPv6 isolation and rollback.

To disable the optional feature without changing baseline or recovery policy:

```sh
sudo bash /usr/local/share/tarasec/manager-ssh/misc/manager_ssh_rollback.sh
```

The rollback removes only the owned gate rules. Restore backed-up web endpoints/service files if
needed. Do not flush INPUT or replace the owner's firewall to undo this feature.
Baseline review: https://tarasec.org/safety/#deployment and
https://tarasec.org/safety/#hardening-priorities.
