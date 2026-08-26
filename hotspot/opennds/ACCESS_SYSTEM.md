# TaraSec hotspot access integration

The access decision is deliberately separate from accounting/payment processing.

## Flow

1. openNDS detects an unauthenticated client.
2. TaraSec identifies the device by the MAC/device key supplied by openNDS.
3. `tarasec-access.sh` asks the central `access-api.php` over HTTPS.
4. The API authenticates the hotspot gateway with its gateway key and token hash.
5. The API looks for an active, unexpired `hotspotEntitlement` belonging to that device's customer.
6. If found it creates/refreshes `hotspotSession` and returns `allow:true`.
7. The local hook returns `auth` to openNDS. Otherwise it returns `deauth`.

This makes the TaraSec entitlement table authoritative. openNDS remains the local enforcement point.

## Gateway config

Create `/etc/tarasec/access.env` mode 0600:

```sh
TARASEC_GATEWAY_KEY='cigar-or-registered-id'
TARASEC_GATEWAY_TOKEN='secret-issued-at-registration'
TARASEC_ACCESS_URL='https://tarasec.org/hotspot/opennds/access-api.php'
```

Do not put the plain token in the database. `hotspotGateway.apiTokenHash` stores SHA-256 of it.

## Pilot

`schema.sql` is intentionally kept outside `misc/install.sql` until the Cigar pilot proves the end-to-end model. A test entitlement can be inserted for the phone's device key, then reconnecting the phone should make the portal decision change from denied to authorized without changing openNDS rules.

Payment providers create `hotspotPayment` and then `hotspotEntitlement`; they do not directly authorize openNDS. That keeps M-Pesa, GCash, Braintree/manual payment and future providers behind the same access decision.
