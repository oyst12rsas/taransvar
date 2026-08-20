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
