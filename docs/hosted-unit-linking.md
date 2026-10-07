# Link the phone app to a node through its account service

Node linking is independent of the connection route. LAN location is not proof of
identity or administrator authority. The entered HTTP or HTTPS address is used only
for public discovery; no subscriber token, identity ticket or app credential goes
to that address. Without an explicit scheme the app uses HTTP for public discovery.
Use an explicit HTTPS origin when the node has a trusted certificate.

The node publishes a stable gateway identity and an account-service node identity
bound to its private registration secret. It authenticates to the discovered HTTPS
account service over an outbound connection. Owner-hosted services remain supported;
`tarasec.org` is the existing fallback. There is no IP-range or VPN-interface test
in the new linking flow. Discovery over HTTP is not authenticated: confirm the
intended node and matching code before approval; a local address alone is no trust
anchor.

In **My units → Link a node**, enter its address, sign in to the selected account
service and choose **Request app link**. Open or copy the HTTPS approval URL to
any browser. The administrator signs in with a freshly verified Google email and
checks the matching code. The node checks that email against its current local
`user.username` and `isAdmin` permission before approving. An ordinary Google login
or LAN connection does not grant access. If the administrator uses a password-only
local account, configure its username as the matching verified Google email before
using this hosted approval flow; password login remains separate.

Requests expire after ten minutes. Grants expire after 90 days. **Finish linking**
saves the stable node identity and current reachable address. Status currently
checks the hosted grant and publicly reachable node identity; it does not expose
private threat reports. Management access still requires its existing separate
approval. A changed address can be re-entered without changing the node identity.

Unlinking revokes account-service access immediately, including when the node is
offline. The node subsequently collects the revocation. Local administrator
revocation is reflected when its worker next reports. Persisted local decisions
prevent acknowledgement retries from restoring revoked grants.

## Deployment order

1. dbserver1: update `tarasec_payment`; run
   `sudo bash central-api/setup_hosted_link.sh`. It applies `app-node.sql` before
   installing the new API and callback files. The callback records the freshly
   verified administrator email on each one-use identity code.
2. Website host: update `tarasec.org`; deploy `api/v1/_proxy.php` and the two
   `identity/app-node*.php` routes. Only the dedicated approval cookie is forwarded
   for the browser approval endpoint.
3. Node: update `taransvar`; run
   `sudo bash misc/setup_gateway_app_account.sh http://NODE_ADDRESS` (or its HTTPS
   origin). The installer preserves installed DB credentials, gateway ID and key,
   backs up affected files, installs the worker and verifies HTTPS registration.
   It then disables the obsolete transport timer and removes only its exact HTTP
   reject rule. Existing owner INPUT/SSH policy remains responsible for reachability.
4. Pull/build/install the Android app using the existing signing setup.

The old local-token HTTP linking flow is disabled in `hosted_gateway` mode. Existing
local-token cards must be linked again through the account service; their tokens
are never sent over a general HTTP route. The older behind-gateway device flow
remains in source for its separate legacy configuration.

## Verification and recovery

Check `tarasec-gateway-app.timer` and its service. Open
`/script/unitGatewayLink.php` from LAN, NetBird or WireGuard: public metadata should
have `link_mode=hosted_gateway`. Complete a request from the phone and approve it
from another browser. Confirm an ordinary account and a demoted administrator are
rejected, expired requests fail, and unlink/replay cannot restore access.

CI tests cover registration-secret binding, account/app isolation, browser decision
versus final node authorization, expiry, offline revocation, current local admin
permissions, route-independent node identity, and legacy credential isolation.
Live Google/browser and route acceptance remains to be tested on the owner's hosts.

Rollback: disable `tarasec-gateway-app.timer`, restore the printed backup's script
files and unit-link.php, then reinstall the former transport guard and re-enable
its timer before restoring the old Android release. Do not flush the owner's
firewall or delete existing account/unit records. Backend backup and optional new
tables may remain; restoring the callback must retain a compatible schema.
