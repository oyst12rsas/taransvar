# Timed SSH access from unit management

The Android **My units → Manage → Status / Units** page can open administrative
SSH for 5, 10 or 15 minutes and polls the local node every five seconds. Access is
limited to the IPv4 address from which the authenticated app reaches that node.
Other units shown in the status list are not implicitly authorized or controlled.

## Owner configuration

Deploy `html/script/managerSsh.php` to the node's corresponding web directory,
and run `sudo bash misc/install_manager_ssh.sh` from the current checkout.
The installer installs a root-owned helper and validates its narrow sudo rule.
It does not enable opening or change any live SSH/firewall rules.
The owner enables opening with `SSH_MANAGER_TIMED_OPEN=on` in
`/etc/tarasecfw.conf`. Existing `SSH_ALLOWED_SOURCES` restrictions still apply.

The initial implementation supports IPv4 hosts with the standard TaraSec direct
INPUT SSH reject barrier, `ALLOW_SSH=0`, and a listening administrative sshd.
It refuses unfamiliar preceding firewall decisions, including NetBird ACL jumps,
interface restrictions and attack containment; these require policy-specific
integration. IPv6 requests are explicitly unsupported. The UI reports unknown or
unavailable when it cannot establish the state, and disables opening.

## Enforcement and authorization

The endpoint rechecks the active, unexpired, unrejected local manager request and
matching email for every call. Opening requires POST and a session CSRF token.
The source comes only from REMOTE_ADDR, never a caller-supplied target or forwarded
header. The helper validates arguments, serializes concurrent operations, rechecks
owner configuration, and uses argv subprocess calls rather than a shell.

A temporary source/port ACCEPT is inserted immediately before the existing SSH
reject rule. Prior policy and recovery rules are retained. The UTC `xt_time`
stop date enforces expiry in the kernel, independently of app activity, PHP,
cron or a background timer. The persistent ALLOW_SSH setting is untouched.
Expired helper rules are cleaned on the next opening. A firewall rebuild may
remove a lease early; polling then shows the observed state. Already open SSH
access is reported without a countdown and cannot be extended through this API.
SSH account/key/password requirements remain in force. Existing sessions are
subject to the node's existing firewall order at expiry.

Status describes observed local INPUT permission for the app's current source
plus the administrative listener; it cannot certify reachability through upstream
routers or firewalls. An expired countdown is shown as "checking closure" until
the next confirmed node status. Failed polls clear the prior status.

Remove `/etc/sudoers.d/tarasec-manager-ssh` to disable helper execution. Turn the
owner flag off to stop new openings; existing leases expire at their original
kernel deadline. Remove a lease early with the exact root-reviewed iptables rule
shown by `sudo iptables -S INPUT` (do not flush the host's firewall).

## Validation

`python3 misc/test_manager_ssh.py` checks duration constraints, exact expiry,
source isolation, owner opt-in, allowed sources, listener absence, existing
recovery access and refusal to bypass unfamiliar rules. The API regression suite
checks authentication, revocation, CSRF, POST and invalid duration boundaries.
CI lints PHP/shell and builds the Android app. A live owner-authorized node test
must still confirm opening and closure from the app's source IP; source-only
checks do not constitute deployment acceptance.
