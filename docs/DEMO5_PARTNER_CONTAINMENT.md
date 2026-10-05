# Demo 5 — partner loses tagging

The Android app runs on a connected subnode. A connection hits the configured
SSH honeypot or a reporting firewall rule. The receiver submits its ordinary
`hackReport` to the central DB using taralink and `report.php`. The app cannot
submit or invent incident evidence. A partner router and a NAT subnode may have
identical source addresses, so the origin remains `router_or_subnode_unknown`.

The DB matches the source against `partnerRouter` (most specific subnet first).
Only routers explicitly enabled for this bounded demo can escalate automatically.
The first rejection is reported, then the DB notification timer retries the normal
partner report endpoint. After successful delivery, the grace period starts.
A subsequent rejection with actual on-wire tag zero can issue a temporary /32
restriction. Missing packet evidence is unknown, never zero. Positive tag evidence
is displayed as `tagging_observed`, not proof that every flow is tagged.

`partnerRouter` retains identity, tagging status, reason and restriction expiry.
`partnerIncidentEvidence` preserves the report, receiver, source port, observed tag
and time. `partnerRestrictionDelivery` distinguishes pending, applied, error and
released per receiver. Receivers reflect DB-owned entries in `colorListings`
without overwriting manual entries. Expiring DB entries are excluded from the
legacy `vListings` kernel feed, which has no safe expiry/removal protocol.

## Install on the central DB server

1. Apply `misc/demo5_partner_containment.sql` with the database administrator.
   It is repeatable and does not enable any router or receiver automatically.
2. Deploy updated `html/script/report.php`, `partnerIncidentLib.php`,
   `appDemo5.php`, `appPartnerStatus.php` and `partnerRestrictions.php`.
3. Install PHP CLI/mysql and copy `misc/tarasec-partner-notify.{service,timer}` to
   `/etc/systemd/system`. Adjust the checkout path if it is not `/root/taransvar`.
   Enable with `systemctl enable --now tarasec-partner-notify.timer`.
4. On a dedicated test gateway's existing `partnerRouter` record set
   `demo5Enabled=1`. Insert `demo5Configuration` with its routerId, a registered
   independent receiver's integer IPv4 address, and its honeypot port.
   Configure graceSeconds (default 30, minimum 15) and restrictionSeconds
   (default 120, runtime clamped to 30–300). Do not select the DB server as the
   receiver or enable the DB as a restriction receiver: app polling must remain
   available. Do not use a shared production gateway.
5. Enroll each independent receiving node in `partnerRestrictionReceiver` with
   its observed peer IP, SHA-256 hash of a unique random token, and enabled=1.
   The chosen honeypot receiver must be enrolled; zero receivers is not success.
   This selected set is the demo's distribution network, not the whole Internet.

Example configuration (replace IDs and addresses; do not copy tokens):

```sql
UPDATE partnerRouter SET demo5Enabled=1 WHERE routerId=TEST_ROUTER_ID;
INSERT INTO demo5Configuration(routerId,receiverIp,receiverPort,graceSeconds,restrictionSeconds)
VALUES(TEST_ROUTER_ID,INET_ATON('RECEIVER_IP'),22,30,120);
INSERT INTO partnerRestrictionReceiver(receiverIp,tokenHash,enabled)
VALUES(INET_ATON('RECEIVER_IP'),SHA2('UNIQUE_RANDOM_TOKEN',256),1);
```

## Install on every enrolled receiving node

1. Apply the same SQL migration and deploy the updated taralink source. Rebuild
   taralink using the existing build routine. It forwards a packet tag and time
   only when it can correlate a local rejection to a receiver traffic record.
   Without this update the demo displays unknown evidence and cannot blacklist.
2. Install PHP CLI/mysql, ipset and iptables. Copy
   `misc/partner-restrictions.example.json` to
   `/etc/tarasec/partner-restrictions.json`, set db_url and the node's token,
   and chmod 600. Use HTTPS or the existing encrypted NetBird path. Do not send
   receiver tokens over an unprotected public HTTP connection.
3. Copy `misc/tarasec-partner-restrictions.{service,timer}` to
   `/etc/systemd/system`, adjust the checkout path, then run
   `systemctl enable --now tarasec-partner-restrictions.timer`.
4. The worker creates its own timeout ipset and INPUT/FORWARD source-drop rules.
   It leaves manual policy alone. It acknowledges application only after testing
   kernel set membership and the two drop hooks. All IPv4 protocols are covered,
   independently of `doTagging` and independently of tarakernel being loaded.
5. Ensure the test honeypot/firewall produces ordinary report records and that
   receiver traffic recording is enabled. A service-close event that is only
   archived is insufficient: use a reporting rejection target.

## Run from the Android app (0.4.4)

Connect through the enabled gateway and open Security demo → Demo 5. Start the
exercise, then send a test connection. The DB observes the source of the app's
own request; the app cannot choose another gateway to blacklist. Check that the
receiver sees the same source (a different VPN route will not correlate).

Healthy baseline: after notification and the grace period, a second rejected
connection should carry a tag. Failure exercise: the operator arranges absent
TaraSec tagging on the dedicated gateway while leaving routing and reporting
available. The app never unloads the kernel module or disables services.

Send another connection after the grace period. Inspect on-wire zero-tag evidence,
DB restriction and each receiver's acknowledgement. The restriction covers the
observed /32; with NAT it can affect all users sharing that source. The app keeps
DB polling independently of the probe and independently of Demo 1 pause state.
Use Release this exercise for early expiry and watch receiver release status.
Kernel timeouts guarantee automatic expiry even if polling stops; expiry does
not claim repaired tagging. Tokens are omitted from debug-copy output.

## Validation and limits

Run `php tests/demo5_transition_test.php` and PHP lint on the new endpoints and
workers. Build taralink with the normal CI command and compile the Android app.
Live validation still requires the DB migration, enabled gateway, enrolled nodes,
and actual packet evidence. An applied acknowledgement proves installed enforcement,
not an independently measured packet drop. A timeout alone proves neither.

Production-wide automated partner quarantine and disabling a compromised partner's
control-plane authority are separate policies; this implementation enables only
bounded, explicitly configured Demo 5 exercises. It never deletes partner identity,
expands a /32 into a subnet, or lets the offending gateway acknowledge its own
receiver restriction. Manual blacklist entries survive release.

The updated `crontasks.pl` forwards ordinary external SSH/firewall syslog threats
to both their partner and configured DB servers, preserving a tag observation
correlated with the full tuple and original event time. Failed delivery stays
pending. Deploy it with taralink on receiving nodes; ensure the syslog normalizer
and existing cron are running. First evidence may wait for the minute cron pass.
