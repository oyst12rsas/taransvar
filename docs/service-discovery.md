# Gateway-first account service discovery

The app checks `GET /script/appServices.php` on the gateway before choosing an
account service. Discovery does not send account credentials. A gateway advertises
its owner-operated identity/subscriber APIs when configured; otherwise it advertises
the tarasec.org defaults. A legacy gateway returning 404/410 also uses the defaults.
Network failures, invalid replies, partial configuration, 401/403 and 5xx do not
silently change the selected provider.

Optional gateway configuration: `/etc/tarasec/services.php` (root:www-data, 0640).
Copy `misc/services.example.php` and configure BOTH API bases. They must share a
trusted HTTPS origin because identity and subscriber APIs share subscriber tokens.
Self-host the compatible TaraSec account backend, including identity-start,
identity-exchange, unit-identity and subscriber APIs. Configure its Google/Facebook
OAuth clients, callback URLs, schemas and secrets as for any independent deployment.
Advertising a URL does not install or configure that service.

Example:

```php
<?php
return [
    'identity_api_base' => 'https://accounts.example.org/api/v1/identity',
    'subscriber_api_base' => 'https://accounts.example.org/api/v1/subscriber',
];
```

Leaving both bases empty, or omitting the file, chooses tarasec.org. A configured
provider that is down remains the provider: repair it or deliberately change the
configuration. This avoids issuing requests with credentials to a different host.

In **My access**, the app checks the DB-recognized NetBird gateway on first sign-in.
A gateway may also be entered explicitly using **Find account service**. The selected
service hostname is shown before sign-in. Existing signed-in accounts are retained
until the user explicitly checks another gateway. Tokens, account IDs and saved units
are stored separately for each identity/subscriber API pair. The old tarasec.org keys
are retained for compatibility. OAuth exchange is bound to the provider that started
sign-in. Redirects on native credential-bearing API requests are disabled.

`unitLinked.php` advertises the same account service configuration. The app requires
sign-in at that provider before requesting a ticket; the gateway redeems the ticket
only at its configured identity service. Unit tokens remain scoped to one unit,
and gateway manager/admin permissions remain separate.

## Administrator services

The same optional services file may set `admin_api_url` and `admin_sign_in_url`
for a compatible self-hosted Gatekeeper approval service. Both must share a trusted
HTTPS origin. If absent, existing `/etc/tarasec/gatekeeper-google.php` URL settings
are preserved; otherwise tarasec.org is the default. Configure the gateway's
`agent_api` to exactly match the chosen administrator API and register a separate
shared secret with that service. Subscriber tokens never grant administrator access.
In-flight administrator callbacks are rejected if the selected service changes.
Account and administrator service configurations are independent.

## Deploy

Deploy the Core web tree using `misc/deploy_web.sh` and build the updated Android app.
With no optional services file, verify the gateway reports the tarasec.org defaults:

```bash
curl --fail http://GATEWAY_NETBIRD_IP/script/appServices.php
```

HTTP discovery is limited in the app to numeric NetBird addresses in 100.68.0.0/16;
all advertised account-service endpoints require trusted HTTPS. No passwords or
tokens are sent with discovery. Other gateway origins must use HTTPS.

## Separate unit-link prerequisite

Discovery does not remove the current Google unit-link flow's HTTPS requirement or
create `/etc/tarasec/unit-link.php`. A gateway such as wt-qw1 with only port 80 and no
unit-link configuration will report its account service, but Google-linked unit sync
still needs the deployment described in `docs/unit-app-pairing.md`. HTTPS on the
public account service and HTTPS for the existing gateway unit-link browser flow
serve different purposes. VPN-only unit linking needs a separate browser-handoff
implementation; do not bypass Google verification or accept forwarded HTTPS headers.
