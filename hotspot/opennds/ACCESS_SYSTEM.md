# TaraSec hotspot access integration

This directory contains the TaraSec-specific openNDS integration. Keep three layers separate when installing or debugging a hotspot:

1. **NetworkManager hotspot** provides the Wi-Fi AP, DHCP/DNS and WAN NAT.
2. **openNDS captive portal** is the local enforcement point. A TaraSec hotspot is not considered complete unless openNDS is running on the client interface.
3. **TaraSec subscriber credit/accounting** is the network layer that decides whether a TaraSec subscriber can use a hotspot and what that use costs.

The basic captive portal must work even before TaraSec credit/accounting integration is configured.

## Basic Raspberry Pi / Debian hotspot path

The maintained install path is:

```sh
sudo bash hotspot/distro/install.sh
```

The installer calls `misc/install_opennds.sh` and `misc/setupWifiNicAsHotspot.pl`. Global subscriber/accounting schemas are installed only on the DB server from the private `tarasec_payment` repository.

## TaraSec subscriber model

A subscriber belongs to **TaraSec**, not to an individual hotspot. A device maps to a network-wide `hotspotCustomer`, which has a global credit account. The customer and each hotspot are linked to owners/jurisdictions for later settlement, but jurisdiction does not prevent the subscriber from roaming.

Credits are deliberately not called GB. The serving hotspot has its own `priceCreditsPerMiB`. Therefore the same TaraSec balance buys more data at a low-cost urban hotspot and less data at an expensive rural/remote hotspot.

The serving price and provider reward percentage are copied into `hotspotSession` when the session starts. Changing a hotspot price later never reprices historical usage.

## Access flow

1. openNDS detects an unauthenticated client.
2. TaraSec identifies the device by the MAC/device key supplied by openNDS.
3. `tarasec-access.sh` asks the central `access-api.php` over HTTPS.
4. The API authenticates the hotspot with its gateway key and token hash.
5. The API resolves the device to an active TaraSec subscriber and checks `hotspotCreditAccount.balanceCredits`.
6. If credits remain, the API creates/refreshes a `hotspotSession`, snapshotting the serving hotspot price and reward percentage, and returns `allow:true`.
7. Usage accounting posts cumulative byte counters to `accounting-api.php`. Only the increase since the previous report is charged.
8. Subscriber credits are debited at the session price. The serving hotspot owner's reward account is credited separately according to `providerRewardBps`.
9. If the subscriber balance reaches zero, accounting returns `allow:false` and closes the session.

## Settlement model

`hotspotOwner.primaryCountry` identifies the owner's primary jurisdiction. A hotspot can also have its own `countryCode`, allowing an owner to operate in more than one country.

Provider earnings go to `hotspotOwnerAccount` and `hotspotProviderLedger`. `cashEligible` and `businessPermitRef` record whether an owner is currently eligible for monetary settlement. Credits and cash are kept separate so an operator can earn/use TaraSec credits even when cash-out is not permitted.

Cross-border cash settlement is not assumed to disappear. TaraSec can net country flows and use the retained margin before settling residual imbalances. The pilot therefore stores the actual serving hotspot price and provider share rather than promising a fixed monetary value per GB worldwide.

## Gateway access configuration

Create `/etc/tarasec/access.env` mode 0600 on a gateway participating in the central pilot:

```sh
TARASEC_GATEWAY_KEY='registered-hotspot-id'
TARASEC_GATEWAY_TOKEN='secret-issued-at-registration'
TARASEC_ACCESS_URL='https://tarasec.org/api/v1/subscriber/access-api.php'
TARASEC_ACCOUNTING_URL='https://tarasec.org/api/v1/subscriber/accounting-api.php'
```

Do not put the plain token in the database. `hotspotGateway.apiTokenHash` stores its SHA-256 hash.

## Student + Cigar pilot

Use two different gateway rows against the same central TaraSec database. Give them visibly different test rates, for example Student `1.000000` credit/MiB and Cigar `3.000000` credits/MiB. Use the same test TaraSec subscriber/device and one global credit balance.

After authenticating the same subscriber at each gateway, confirm that each session snapshots its gateway's price. Then submit the same byte delta to `accounting-api.php`: Cigar should debit three times as many subscriber credits as Student. Provider rewards should appear under the owner of the gateway that actually served the traffic.

Useful verification query:

```sql
SELECT s.sessionId,g.name,s.priceCreditsPerMiB,
       s.bytesUp,s.bytesDown,s.chargedCredits,s.providerCredits
FROM hotspotSession s
JOIN hotspotGateway g ON g.gatewayId=s.providerGatewayId
ORDER BY s.sessionId DESC;

SELECT customerId,balanceCredits FROM hotspotCreditAccount;

SELECT o.displayName,g.name,p.usageMiB,p.subscriberCreditsCharged,
       p.providerCreditsEarned,p.settlementCountry,p.cashEligibleSnapshot
FROM hotspotProviderLedger p
JOIN hotspotOwner o ON o.ownerId=p.ownerId
JOIN hotspotGateway g ON g.gatewayId=p.gatewayId
ORDER BY p.providerLedgerId DESC;
```

The current pilot accounts usage when a gateway posts counters. Automatic periodic extraction of live openNDS counters is the next integration step; the database/API deliberately does not invent usage between reports.
