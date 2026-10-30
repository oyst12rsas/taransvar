# DB-authorized browser Poke demo

The DB server controls admission and the participating receiver list. Open
`http://<your-gateway-IP>/demo/poke/` on the phone/laptop connected through that
gateway. No Standard-gateway address is compiled into the browser or receiver
admission logic. New gateways need the gateway installer, a central partnerRouter
registration and local unit ownership, but do not require editing receiver code.

## DB admission policy

The DB-only file `/etc/tarasec-web-poke-db.conf` contains:

```ini
ALLOW_ANY_GATEWAY=YES
ALLOWED_GATEWAYS=100.68.165.190
```

The first DB installation explicitly writes **YES**, as requested for initial
testing. Reinstallation preserves the existing choice and authentication keys.

- **YES:** any registered NetBird gateway may request sessions. The DB verifies
  gateway-observed owner/unit identity through a one-use callback proof.
- **NO:** only registered gateways whose exact overlay address appears in
  `ALLOWED_GATEWAYS` may join or use existing sessions. Comma-separated IPv4
  addresses are accepted; there are no wildcards or subnet matches.
- Missing/unreadable config, invalid flag, failed identity verification or DB
  outage fails closed. A missing flag acts as NO, not YES.

Edit this file on **dbserver1** with `sudoedit`. Changes apply on the next request
without restarting PHP. A switch to NO immediately blocks the unlisted gateways'
status, controls, Pokes and reports; it is not merely a new-signup restriction.
Authenticated messages from receivers also recheck the current registration.

The same policy is applied to older `appInfection.php`, `appLocalInfection.php`,
`appInfectionControl.php` and canonical `getTagData()` callers on enrolled nodes.
This closes the old anonymous status route as an alternate way around NO. These
legacy clients still work for permitted gateways. With NO, old app/browser demos
from an unlisted gateway deliberately stop working. This switch is demo/API
admission, not a guarantee that infection information cannot be inferred from
ordinary firewall/network behavior or obtained through separately authorized
management/reporting interfaces.

## Fixed receivers, dynamic gateways

Only the DB coordinator uses `webPokeNodes()` for participant selection:

| Receiver | NetBird address |
|---|---|
| Roquefort | 100.68.176.110 |
| Camembert | 100.68.149.164 |
| dbserver1 | 100.68.126.0 |
| Gouda | 100.68.51.247 |

Squash, Tomato, Audi and Porsche are excluded. Each receiver has an independent
256-bit authentication key, generated on the DB server, and a pinned DB address.
A new gateway cannot choose arbitrary destinations or claim another gateway IP.

## Deployment

Deploy the DB and all receivers before using the upgraded browser. The previous
unauthenticated `webPokeStatus.php` route now returns 410. Use the targeted
installer, or ensure a full recursive web deployment also installs the role
configuration and matching receiver keys. No DB migration or new daemon is needed.
All nodes need PHP 8.3-compatible PHP, mysqli/curl and existing TaraSec tables;
receivers need working tarakernel/taralink reporting. Production-mode hosts refuse
installation and the new APIs. No firewall or routing policy is modified.

On **dbserver1**, after pulling the merged change:

```sh
clear
# Machine: dbserver1
cd ~/taransvar
git pull --ff-only
sudo bash misc/install_web_poke_demo.sh --db
sudoedit /etc/tarasec-web-poke-db.conf
```

The DB installer also enrolls dbserver1 as a receiver. It generates individual
root-only receiver files under `/etc/tarasec-web-poke-enrollment/`:
`roquefort.conf`, `camembert.conf`, `dbserver1.conf`, `gouda.conf`. Transfer each
file through your existing authorized administrative channel to **only its
matching receiver**. Do not paste their keys into chat, commit them or publish
them. Keep the transferred file readable only by its administrator.

On **each matching receiver**, with its securely transferred file:

```sh
clear
# Machine: Roquefort / Camembert / Gouda — run on each with its own file
cd ~/taransvar
git pull --ff-only
sudo bash misc/install_web_poke_demo.sh --receiver /secure/path/this-receiver.conf
```

On **wt-gw1 or a new participating gateway**:

```sh
clear
# Machine: wt-gw1 / new gateway
cd ~/taransvar
git pull --ff-only
sudo bash misc/install_web_poke_demo.sh --gateway
```

`WEB_POKE_DB_IP` may be explicitly supplied to the installer when the DB overlay
address differs from 100.68.126.0. Gateway configuration is in
`/etc/tarasec-web-poke-gateway.conf`; the callback must be reachable at the
registered overlay address on HTTP port 80. A gateway must have a central
`partnerRouter` row, and the caller must resolve to local `unit.ownerId` +
`unit.unitId`. Missing ownership produces an actionable refusal, not an IP-only
identity. Receivers must see the participant's NAT source as that gateway's
registered NetBird address. Gateway page URLs currently use literal IPv4 addresses;
reverse proxies, IPv6 and DNS names need an explicit trusted-origin design.

Configuration files are root-owned and readable by www-data, never writable by
the web application. Apache's account must be able to create a private temporary
session directory. Keys are preserved across reinstalls. Rotating a receiver key
requires distributing its matching file and ends outstanding signed requests.

## Protocol and observation isolation

1. The gateway identifies the calling socket's current local owner/unit, creates
   a 60-second single-use identity proof and asks the DB to start a session.
2. The DB checks current policy and central gateway registration **before** any
   identity callback or state disclosure. It redeems the proof by calling that
   literal gateway peer; arbitrary URLs, proxies and redirects are not accepted.
3. The DB sends authenticated registrations to all four receivers, with the
   actual gateway address, browser origin, session expiry and a distinct Poke
   capability for each receiver. All must acknowledge before admission succeeds.
4. The gateway receives a private DB-control token, kept server-side. The browser
   gets a separate device-bound gateway token and receiver-scoped Poke tokens.
   A browser token cannot be used directly as a DB control token. A different
   local unit or changed ownership cannot reuse the gateway session.
5. The browser takes direct baseline Pokes. All five must confirm clean before
   the Mark button is enabled. Mark uses an independently authorized DB request
   and creates only a `DEMO:WEB_POKE:<session>` infection row on the gateway.
6. A direct Poke validates its scoped capability, real socket peer and stored
   browser origin. The receiver checks current DB authorization **before**
   reading traffic, matches fresh evidence to that TCP connection, and sends an
   authenticated report to the DB. Unknown/missing/stale tags stay UNKNOWN.
7. Once per second, the browser requests combined status through its gateway.
   The DB returns its saved receiver reports. Polling never creates browser
   traffic to receivers and never reads new receiver traffic evidence.
8. Reset removes only this session's mark, revokes the old central session and
   requests a new one. Genuine findings and other demo sessions remain active.

Messages use per-receiver HMAC-SHA256, 30-second timestamps and replay-protected
nonces. Keys and DB-control tokens are never sent to the browser. The transport
is HTTP **inside the encrypted NetBird overlay**; do not publish these RPCs on
plain public HTTP. The browser's local HTTP origin is retained for the existing
traffic-tagging testbed; use only trusted test networks. Public browser access
requires a separate HTTPS deployment design; this change does not claim to have
implemented public invitations or Google presenter authorization.

Sessions expire after 30 minutes. Start attempts and receiver Pokes are limited;
private locked session storage is bounded and abandoned records are reclaimed.
Stopping, closing the page, expiry, or revoking access does **not** promise to
clear an already-applied kernel mark automatically. Reset while authorized before
leaving. After revocation/expiry, the gateway owner may need to clear that specific
`DEMO:WEB_POKE:` finding using management access. Do not clear genuine evidence.

## Verification and rollback

Automated checks cover YES/NO admission, default-deny behavior, new gateways,
registration, one-time authentication, replay/tamper/stale messages, immediate
revocation, other-gateway tokens, receiver isolation, expired sessions, real
session-scoped mutation SQL, MariaDB unit ownership and mark/reset, and the browser
flow on a non-Standard gateway. Browser network calls are mocked; they cannot
establish live routing or kernel-tag correctness. The phone layout was inspected.

```sh
clear
# Machine: development host / CI
php tests/web_poke_test.php
python3 tests/web_poke_control_test.py
python3 tests/hack_report_expiry_test.py
node --check html/demo/poke/poke.js
# With Playwright/Chromium:
node tests/web_poke_browser_test.cjs
# Disposable MariaDB only; set its WEB_POKE_TEST_PORT and password:
php tests/web_poke_database_test.php
```

After deployment: confirm all five clean, mark and wait without Poking (only
gateway red), Poke each receiver independently, reset, then set NO and verify an
unlisted gateway receives no status through either the new or old APIs. Confirm
Standard gateway remains usable when explicitly listed. Test DB unavailability
and ensure the UI shows UNKNOWN. Finally restore the operator's chosen policy.

This development session could not connect to dbserver1: SSH to
100.68.126.0:4810 returned **Network is unreachable**. Merge is not evidence of
installation. Live deployment and these multi-node acceptance checks remain
pending until run from a machine with the authorized overlay/admin connection.

Rollback: stop new sessions, reset their marks while authorized, then restore the
previous web files together on all nodes and remove the role configuration files.
Retain private DB/enrollment keys securely for recovery, or revoke them. Removing
only role configuration makes legacy APIs revert to their old behavior, so do
not treat configuration deletion as an access-revocation mechanism. Production
hosts keep their deployment-mode gate. For deployment/hardening context see
https://tarasec.org/safety/#deployment and https://tarasec.org/safety/#hardening-priorities.
