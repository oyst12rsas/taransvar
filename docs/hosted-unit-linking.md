# Link nodes using the account service

For a gateway without a public HTTPS domain, Google sign-in and approval can run
on the selected account service. The gateway's `/etc/tarasec/services.php` selects
an owner-hosted identity/subscriber service; when absent the default is tarasec.org.
This mode needs no Google web client or HTTPS listener on the gateway.

In **My units → Link a node**, enter the gateway IP, sign in to its selected
account service and choose **Request app link**. The phone creates a request for
that explicit gateway; the approving browser's source IP never selects a unit.
Open the approval page on the phone, acer or any browser that can reach the gateway.
Sign in using the existing gateway administrator login, return to the approval
page and approve only if its confirmation code matches the phone. Then choose
**Finish linking** in the app. Requests expire after ten minutes.

This grants read-only gateway access to that account and app instance. Management
uses the separate existing approval. Administrators can revoke approved apps at
`/gatekeeper/appLink.php`; unlinking in the phone also revokes the grant and pending
requests. Revoked or expired credentials cannot report a successful status.

The older `/script/unitLink.php` workflow remains for devices behind the gateway;
it identifies the initiating device and is not the phone-to-gateway workflow.

## Installation order

1. Account service: update `tarasec_payment` and run
   `sudo bash central-api/setup_hosted_link.sh`. It validates PHP, backs up the
   existing identity endpoints and applies the new schema. Default installed API
   directory is `/var/www/tarasec_payment/public/api/v1`; pass the real directory
   if it differs. Existing Google callback registration is reused. For a custom
   service, set `unit_approval_url` in `/etc/tarasec/identity.php` to the public
   `.../identity/unit-approve.php` on the callback's HTTPS origin.
2. Public proxy: update `tarasec.org` and deploy `api/v1/_proxy.php` plus
   `api/v1/identity/unit-request.php` and `unit-approve.php` into its existing web
   mount. Preserve `/etc/tarasec/backend-api.php`. The dedicated approval session
   cookie passes to the backend; unrelated site/admin cookies do not.
3. Gateway: update `taransvar`, then run
   `sudo bash misc/setup_hosted_unit_link.sh http://NETBIRD_GATEWAY_IP wt0`.
   Use its actual NetBird IPv4 address and WireGuard interface. For gateway TLS,
   use `sudo bash misc/setup_hosted_unit_link.sh https://gateway.example.org`.
4. Android: build/install current `TaraSec_App` using the existing signing setup.

The gateway installer preserves gateway ID and private subject key, backs up an
existing configuration, installs optional tables and two collection/transport
timers, and deploys the web tree. Switching from legacy Google mode scopes new
links to the selected service, so old links need renewed approval. It does not
configure TLS, alter SSH, or grant manager access.

## HTTP transport boundary

HTTP is enabled only for explicitly configured `service_handoff`/`netbird` mode.
The server requires direct NetBird source and destination addresses. A root timer
verifies the configured kernel WireGuard interface and installs an INPUT reject
rule for HTTP destined to that IP when arriving on any other interface. A fresh
runtime marker is required; a failed/stopped guard fails closed within 45 seconds.
Confirm actual enforcement and route ownership on the gateway; HTTP on a LAN or
public address remains unsupported. A firewall flush can temporarily invalidate
the guard until its next check; avoid flushing rules outside the gateway's policy.

Android allows HTTP credentials only to numeric 100.68.x.x destinations, binds
requests to a VPN with a matching route and TaraSec VPN address, and rejects an
absent/disconnected tunnel. TLS services retain normal trusted certificate checks.
VPN implementations that do not expose a usable Android Network are unsupported;
use a trusted gateway HTTPS origin instead. Do not weaken these checks to bypass
a routing failure.

## Verification and recovery

Check `systemctl status tarasec-unit-link-poll.timer` and, in HTTP mode,
`tarasec-unit-link-transport.timer`. Query `/script/unitGatewayLink.php` from a NetBird peer through the
configured transport: it should return `link_mode=gateway_app_approval`, the gateway
identity and selected account services. Create a request in the phone app, approve
it from a different browser, and confirm that the gateway stays the same. Check
ordinary users cannot approve; wrong account/app, expired request, replay, provider
change and revoked grants cannot obtain access. Management remains separately
approved. This correction needs only the gateway and Android updates when the
account-service identity handoff is already installed.

Rollback: stop/disable both new timers, restore the backed-up unit-link.php and
previous web files/Android release. Restore backend identity files from the printed
backup. Optional request tables may remain; do not delete existing tokens/links.
If removing the HTTP guard, first restore HTTPS mode; remove only its exact reject
rule after confirming that cleartext linking is disabled.

Database tests cover apply-once/revocation, owner/IP/provider changes, expiration,
broker audience and separate approval/poll secrets. Live Google/browser, VPN and
multi-node acceptance must be checked on the owner's hosts after deployment.
