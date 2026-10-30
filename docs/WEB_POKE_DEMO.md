# Browser Poke demo

Open **http://100.68.165.190/demo/poke/** on the phone/laptop connected through
Standard gateway. The browser is the participant; no Android update is needed.
The page is linked from `/demo/`. On other hosts it offers the Standard gateway link.

The fixed topology is:

| Role | Host | NetBird address |
|---|---|---|
| Gateway | wt-gw1 / Standard gateway | 100.68.165.190 |
| Receiver | Roquefort | 100.68.176.110 |
| Receiver | Camembert | 100.68.149.164 |
| Receiver | dbserver1 | 100.68.126.0 |
| Receiver | Gouda | 100.68.51.247 |

Squash, Tomato, Audi and Porsche are excluded. Gouda's address matches the
existing `html/gatekeeper/func/liveAudit.php` topology.

## Installation

On **each of wt-gw1, Roquefort, Camembert, dbserver1 and Gouda**, after this
change is merged:

```sh
clear
# Machine: wt-gw1 / Roquefort / Camembert / dbserver1 / Gouda — run on each
cd ~/taransvar
git pull --ff-only
sudo bash misc/install_web_poke_demo.sh
```

The installer deploys the new demo and its navigation link; it preserves deployed
DB credentials, existing APIs, firewall, routing and infection state. A normal
recursive web deployment also includes these files.
It needs existing TaraSec PHP endpoints and PHP mysqli/curl in CLI and Apache.
Production mode blocks all new demo endpoints. Existing unconfigured legacy demo
hosts retain the repository's deployment-mode behavior.

Standard gateway must NAT the participant's receiver-bound HTTP connections to
100.68.165.190, as in the existing browser demo. The participant needs HTTP access
to all four receivers; the gateway also needs HTTP access for observation polling.
Receivers need working tarakernel/taralink traffic reporting, plus Standard gateway
registered as a partner or the existing explicit `DEMO_NODE=1` configuration.
Missing routing, stale reports or a wrong route appear as UNKNOWN; no state is fabricated.

No DB migration or new service is required. Receiver snapshots are private files in
PHP's system temporary directory, capped at 2,048 sessions per node. Sessions expire
30 minutes after the first Poke. New sessions reclaim abandoned snapshots. The
web service account needs permission to create its private temporary directory.

## Sequence and polling isolation

1. **Start clean / Reset** clears only this participant's demo infection rows on
   the gateway using `appInfectionControl.php`. It checks local state and refuses
   to start if independent findings remain active.
2. The browser sends one baseline Poke directly to each receiver. Each captures
   fresh traffic evidence from `getTagData()`, matched to the actual TCP peer and
   source port. All five must confirm clean before marking is enabled. If a prior
   kernel mark is still being removed, wait briefly and repeat the affected Poke.
3. **Mark this device infected** invokes the existing gateway demo control.
   The gateway card follows `appLocalInfection.php`, not the command acknowledgement.
4. Once a second the browser asks **only the gateway** for local state and the
   saved receiver observations. `webPokeStatus.php` fetches the four snapshots in
   parallel, with an 800 ms deadline. Snapshot reads never call `getTagData()`.
   There is no background browser traffic to receivers.
5. A receiver's **Poke** button sends a direct browser POST through Standard gateway
   to `webPokeReceiver.php`. Only this operation replaces that receiver's saved
   observation. Fresh severity >1 yields INFECTED; severity <=1 yields CLEAN.
   Missing evidence, an unavailable service or a wrong route yields UNKNOWN.
6. **Reset** obtains a new random 256-bit observation token and fresh clean
   baselines. It never deletes genuine findings or historical traffic. **Stop**
   stops polling; it does not clear a gateway mark. Use Reset before leaving.

The screen says **Last observed**, because its green cards represent previous
connections. Other real traffic can still reach a receiver and affect the wider
TaraSec system. This demo freezes its own explicit Poke observations, not the
underlying kernel or threat database. A red gateway does not guarantee that a
Poke made before the kernel applies the mark will already be red; repeat it after
propagation. Browser background timer throttling may reduce refresh frequency.

The 30-minute observation expiry does **not** automatically clear a gateway mark.
Closing the page likewise leaves the mark active, matching the existing demo
control behavior. Return and use Reset to remove this device's demo evidence.

The snapshot token is a temporary bearer capability, omitted from copied debug
reports. Poke POSTs require the real socket peer to be Standard gateway and the
browser Origin to be the gateway page. Snapshot GETs also require Standard gateway.
Client-IP headers are ignored when recording observations. The gateway collector
uses fixed destinations, disables proxies and redirects, and accepts no user URL.

## Verification and live acceptance

Automated checks:

```sh
clear
# Machine: development host / CI
php tests/web_poke_test.php
node --check html/demo/poke/poke.js
# With Playwright and its Chromium installed:
node tests/web_poke_browser_test.cjs
```

PHP checks exercise the real evidence/snapshot code: read-only status, fresh tags,
wrong routes, missing/stale evidence, expiration and isolated topology. The browser
check uses mocked network APIs to exercise all five cards, mark/Poke sequencing,
no receiver requests during polling, unavailable services, reset, stop, independent
findings and phone-width layout. CI repeats syntax, PHP and browser checks.

Live multi-node acceptance remains necessary after deployment: verify all five
clean, mark and wait without poking (only gateway red), Poke each receiver (only
that card changes), reset to clean, and verify failures remain UNKNOWN. Confirm
actual kernel tags and receiver traffic in Debug evidence if a Poke stays unknown.
This development environment has no access to the demo nodes; local tests do not
establish their current routing, reporting or live deployment state.

Rollback: remove `/demo/poke/` and the three `script/webPoke*.php` files from the
web root, and remove the added `/demo/` link if deployed. Reset this participant's
demo mark before removing the UI. Existing Demos 1–5 and APIs are unchanged.
