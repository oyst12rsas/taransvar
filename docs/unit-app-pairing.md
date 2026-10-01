# TaraSec unit-owner pairing v1

This flow is separate from gateway-manager access.

1. A laptop/Raspberry Pi is physically/logically on its own TaraSec-managed LAN.
2. `/script/unitSelf.php` proves the current connection maps to a local unit.
3. `/script/unitPair.php` creates a random 256-bit token scoped to that unit only.
4. The gateway stores only SHA-256(token), never the plaintext token.
5. The phone stores the token and may later query `/script/unitStatus.php` remotely over the TaraSec management path/VPN.
6. The token cannot call manager endpoints and cannot read another unit.

The first implementation deliberately avoids names, email addresses, subscriber IDs or other personal identity. The gateway/ISP remains the only party that can map its own technical unit ID to an employee/subscriber where policy and law permit.

For now the Linux/Raspberry Pi test agent prints a JSON pairing secret that can be copied into the phone manually. QR encoding/scanning is the next UI step.

## Google linking and My units

The optional Google flow links each unit locally and lets the Android app retrieve
all units linked to the same account at a specified gateway. It does not grant
manager privileges. The gateway stores a keyed, gateway-specific account hash;
shared reports continue to use technical owner/unit identities.

Run on the **gateway**, after deploying the central identity service and public
proxy changes:

```bash
sudo apt-get install composer php-curl php-mysql
sudo bash misc/setup_unit_link.sh https://gateway.example.org YOUR_WEB_CLIENT_ID.apps.googleusercontent.com
```

Configure a trusted HTTPS certificate on the gateway (Apache must see HTTPS=on).
Register that HTTPS origin and the exact `/script/unitLink.php` login URI in the
Google web client. Preserve `/etc/tarasec/unit-link.php` in protected backups: its
gateway ID and private subject key must remain stable. Never publish the key.
Existing configuration is preserved by the setup script; edits are deliberate.
The script applies optional schemas and deploys web endpoints; it does not
configure DNS, TLS or Google Cloud for you.

From each unit on its own gateway LAN, visit `/script/unitLink.php`, identify the
shown unit and continue with the same Google account used in the app. The page
requires recent, unambiguous canonical unit attribution (15 minutes), validates
Google's signature/audience/issuer/expiry and session nonce, and checks CSRF and
that the local connection still resolves to the same unit. Unrecognized/stale
attribution fails instead of selecting an old DHCP lease. Shared devices should
only be linked by users authorized to see their threat information.

In the app, open **My units**, add the reachable gateway HTTPS origin and sync.
Repeat for another gateway. Unit tokens remain read-only; offline units stay saved.
`unitLinked.php` rotates this app's grants on sync; account unlink revokes all of
that account's Google-linked app grants for the unit. Existing manual pairing
credentials and other accounts are separate. Manual pairing import is supported;
QR scanning remains a follow-up.

The subscriber token stays at the central identity service. A gateway-bound,
single-use ticket expires after 60 seconds. The gateway redeems it over verified
TLS at the fixed public identity endpoint and hashes the stable Google subject
locally. The central service does not receive unit IDs or security observations
from this linking flow. Expired handoff rows are deleted by its deployment cron.

### Validation before declaring this operational

Deploy the matching Core, central identity, public proxy and Android changes.
Test two units behind one gateway plus a unit behind another gateway. Check
account changes/sign-out, offline units, missing API, wrong account, expired
handoff, repeat handoff redemption and account unlink. Verify a unit token cannot
read a different unit or enter manager APIs. Confirm HTTPS certificates, Google
origin/login registration, local attribution, cron operation and schema availability.
Do not claim immediate interactive AI assistance: this flow presents available
unit assessments and guidance, not an implemented remediation chat.
