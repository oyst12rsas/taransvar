# DB-authorized browser Poke demo

The DB server controls admission and the participating receiver list. Open
`http://<your-gateway-IP>/demo/poke/` on the phone/laptop connected through that
gateway. No Standard-gateway address is compiled into the browser or receiver
admission logic. New gateways need the gateway installer, a central partnerRouter
registration, but do not require editing receiver code.

## DB admission policy

The DB-only file `/etc/tarasec-web-poke-db.conf` contains:

```ini
ALLOW_ANY_GATEWAY=YES
ALLOWED_GATEWAYS=100.68.165.190
```

The first DB installation explicitly writes **YES**, as requested for initial
testing. Reinstallation preserves the existing choice and authentication keys.

- **YES:** any registered NetBird gateway may request sessions. The DB verifies
  gateway-confirmed local visitor and origin through a one-use callback proof.
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

## Automatic roles and participant list

A node has no exclusive gateway/receiver role. Every installed node serves local
subnet clients as their gateway and authorized connections from another gateway
as a receiver. The single DB server additionally runs the coordinator.

The installer reads `setup.isGlobalDbServer`, `setup.globalDb1ip` and the machine's
NetBird interface addresses. Runtime classification uses the actual socket peer
and `setup.internalIP` / `setup.nettmask`. Device and owner registration is
optional. Only peers inside that local subnet may request gateway sessions; a
unit-table row alone does not make an external source local. Nonlocal Pokes still need DB authorization,
a receiver-scoped token, matching gateway address and browser origin. Invalid or
missing subnet configuration cannot grant gateway access.

The DB coordinator's fixed participant list is:

| Node | NetBird address |
|---|---|
| Standard gateway | 100.68.165.190 |
| Roquefort | 100.68.176.110 |
| Camembert | 100.68.149.164 |
| dbserver1 | 100.68.126.0 |
| Gouda | 100.68.51.247 |

The participant's own gateway is removed from its receiver list. A Standard
session therefore has the familiar four receivers; a Roquefort session instead
includes Standard as a receiver. A registered gateway outside the participant
list may serve its own units, but does not automatically gain receiver credentials.
Squash, Tomato, Audi and Porsche remain excluded. Adding a new receiver is an
explicit DB participant-list change, independent of allowing a gateway to join.

## Deployment: the same command on every computer

Run on **dbserver1 first**, then on **wt-gw1, Roquefort, Camembert and Gouda**:

```sh
clear
# Machine: dbserver1 first; then wt-gw1 / Roquefort / Camembert / Gouda
cd ~/taransvar
git pull --ff-only
sudo bash misc/install_web_poke_demo.sh
```

No role argument or credential-file transfer is needed. Old `--db`, `--gateway`
and `--receiver` arguments remain accepted, but configuration determines the
installed roles. A listed receiver without matching credentials automatically
enrolls after its root installer proves that enrollment is running locally.
Existing matching credentials are retained; reinstallation preserves DB policy
and keys. The DB installer generates any newly required participant key, including
Standard's receiver key. It must run first after this upgrade.

During automatic enrollment, the root installer creates an ephemeral encryption
key in memory and a 120-second root-owned, read-only-for-Apache bootstrap file.
The DB calls back the actual approved receiver peer with an unguessable proof,
obtains the local administrator's public key, and seals that receiver's credentials
to it with libsodium. Only the installer process can decrypt the result. The
bootstrap is removed when the attempt finishes, including failures. A browser
cannot substitute its own public key, request another node's credentials, or
turn an unlisted gateway into a receiver. No key is printed or placed in a URL.

The installer copies the demo, menu links and API files to `/var/www/html`,
preserving deployed DB credentials. Gateway/receiver configuration is installed
on every applicable node; DB-only policy stays on the coordinator. Generated
root-only files in `/etc/tarasec-web-poke-enrollment/` are retained for administrative
recovery, not routine manual distribution.

A first enrollment requires DB-to-node HTTP callback access on the registered
NetBird address. Enrollment fails closed if the DB has not been upgraded, the
callback is unavailable, the peer is unlisted, or the credential reply cannot be
decrypted. Apache and CLI PHP need mysqli, curl and sodium. Existing TaraSec tables
and working tarakernel/taralink traffic reporting remain required. No DB migration,
new daemon, firewall change or route change is introduced. Production mode blocks
installation and the APIs. For a different DB address, configure `globalDb1ip`;
`WEB_POKE_DB_IP` remains an explicit installer override.

Configuration files are root-owned, readable by www-data and not writable by the
web application. Session storage needs a private temporary directory writable by
Apache. These URLs currently use literal IPv4 addresses; reverse proxies, IPv6
and DNS names need an explicit trusted-origin design.

## Protocol and observation isolation

1. The gateway verifies the calling socket is local, looks up optional owner/unit IDs, creates
   a 60-second single-use identity proof and asks the DB to start a session.
2. The DB checks current policy and central gateway registration **before** any
   identity callback or state disclosure. It redeems the proof by calling that
   literal gateway peer; arbitrary URLs, proxies and redirects are not accepted.
3. The DB sends authenticated registrations to the selected receivers, with the
   actual gateway address, browser origin, session expiry and a distinct Poke
   capability for each receiver. All must acknowledge before admission succeeds.
4. The gateway receives a private DB-control token, kept server-side. The browser
   gets a separate device-bound gateway token and receiver-scoped Poke tokens.
   A browser token cannot be used directly as a DB control token. A different
   socket peer cannot reuse the gateway session. Registration changes during the
   demo do not invalidate an existing visitor capability. Unknown
   owner/unit IDs are zero; no account or device registration is created. Guest
   marks target only the observed local IP and their exact demo session.
5. The browser takes direct baseline Pokes. All participating computers must confirm clean before
   the Mark button is enabled. Mark uses an independently authorized DB request
   and creates only a `DEMO:WEB_POKE:<session>` infection row on the gateway.
6. A direct Poke validates its scoped capability, real socket peer and stored
   browser origin. The receiver checks current DB authorization **before**
   reading traffic, matches fresh evidence to that TCP connection, and sends an
   authenticated report to the DB. Unknown/missing/stale tags stay UNKNOWN.
7. Once per second, the browser requests combined status through its gateway.
   The DB returns its saved receiver reports. Polling never creates browser
   traffic to receivers and never reads new receiver traffic evidence.
8. Start / Reset requests a fresh DB-authorized session and clears current/earlier
   `DEMO:` marks for the actual local IP and matching unit (also unknown
   unit IDs from before registration). It works after page reload or session expiry.
   Other devices, different known unit IDs and security findings are preserved.
   The coordinator replaces older web-demo access for this gateway/device, so old
   status, controls and Poke capabilities stop working. It also cancels nonterminal
   verified SSH demo sessions matching the exact gateway and unit. Unknown units
   cannot close SSH sessions using a shared gateway IP. Group demos, router-wide
   demos and unverified SSH sessions lack device ownership and are not cancelled.
   A legacy anonymous web session with no device identity cannot be correlated
   centrally and expires normally. A device starting a new demo intentionally
   replaces the current one; old native toggles have no cancellable session.
9. Gateway state distinguishes this web demo, other `DEMO:` marks and separate
   security findings. A separate finding blocks admission, regardless of demo
   marks; if one appears during a session, the next gateway request closes that
   central session so receiver capabilities are revoked too. Resolve the finding
   through normal gateway management before retrying; Reset cannot clear it.
   An infection tag alone is not proof of actual malware. Receiver cards show
   observed tags, not independently verified compromises.
10. Start takes fresh direct Pokes after clearing earlier demo marks.
    Mark is enabled only after every observation is clean. After reset, kernel tag
    propagation may lag the DB; wait and Poke again rather than inventing CLEAN.


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
leaving. After expiry or page reload, Start can recover this device's older
demo-only marks while authorized. If admission is revoked or a separate security
finding blocks it, the gateway owner must use management access. Do not clear
genuine evidence merely to make the demonstration start.

## Verification and rollback

Automated checks cover YES/NO admission, default-deny behavior, new gateways, automatic dual roles, local-subnet isolation, encrypted root enrollment,
registration, one-time authentication, replay/tamper/stale messages, immediate
revocation, other-gateway tokens, receiver isolation, expired sessions, real
session-scoped mutation SQL, MariaDB registered and unregistered visitor binding and mark/reset, and the browser
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

### Device replacement feedback

The button is labelled **Start / Reset my demos**. Start returns counts of cleared
local demo marks and cancelled verified SSH sessions in `reset`, included in copied
debug evidence. If a demo mark remains under a different known unit ID, the page
asks the gateway operator to review device records rather than silently claiming
CLEAN. Non-demo security findings continue to block participation.
