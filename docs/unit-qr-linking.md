# Link a laptop from its browser

Open the gateway IP/address in the laptop browser. Gatekeeper has a **Link to my app** button. On the phone, sign in to TaraSec, open **My units**, choose **Scan QR code**, and confirm the laptop name and gateway. No script paths need to be entered.

The browser shows a code only after identifying exactly one recent unit on the direct gateway LAN connection. If NetBird routes the browser over its overlay, use the LAN gateway address and check routing; the page refuses an ambiguous identity. Each code expires after five minutes and is consumed once. The app receives a unit-scoped read-only credential, stores it encrypted for the signed-in account, and remembers the gateway. Google-linked units can also be recovered using **Sync linked units**. A gateway manager request still needs its separate email and administrator approvals.

QR rendering happens locally on the gateway, never through a third-party QR service. The phone asks before contacting the scanned gateway. Both browsers and phones must trust its HTTPS certificate; certificate checks and redirects on credential requests are not bypassed.

## Gateway installation

For a gateway already configured with `/etc/tarasec/unit-link.php` and working HTTPS:

```sh
cd ~/taransvar
git pull --ff-only origin main
sudo bash misc/setup_unit_qr.sh
```

For first-time linking setup, use `misc/setup_unit_link.sh HTTPS_ORIGIN GOOGLE_WEB_CLIENT_ID`. Configure HTTPS with a trusted certificate and a hostname that resolves to the gateway on its LAN before testing. The setup script does not provision certificates or change network routing. Entering a plain IP on HTTP is supported as a starting point: **Link to my app** redirects to the configured HTTPS origin without creating or exposing a code on HTTP.

Migration 99 creates the three linking tables additively. The setup helper also applies their idempotent definitions for gateways whose automatic migration timer has not run. Existing Google and gateway-manager configuration is preserved.

## Test

1. Open the gateway home page from a laptop on its LAN and choose Link to my app. Verify the displayed laptop name.
2. Create and scan a QR code. Confirm the gateway. The laptop must appear in My units with a Check status button.
3. Scan the same code again: it must be rejected. A code older than five minutes must also be rejected.
4. With two phones redeeming the same code simultaneously, exactly one succeeds.
5. Sign out/change account: the saved unit must not appear under the other account. Sync restores it only for the linked account.
6. Unlink account, then Check status with the old credential: access must be revoked.
7. Open from outside the gateway LAN or from an ambiguous/stale IP identity: no QR should be generated.
