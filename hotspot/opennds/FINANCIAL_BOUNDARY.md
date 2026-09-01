# TaraSec hotspot financial boundary

TaraSec core and ordinary hotspots are not the authority for loans, credit limits, debt, repayments or payment-provider state. Those belong to the private `tarasec_payment` service on the financial DB server.

Core retains only what is required to enforce Internet access and show the subscriber account:

- global subscriber identity/authentication
- spendable TaraSec connectivity-credit cache (`hotspotCreditAccount`)
- hotspot sessions, byte usage and tariff snapshots
- `hotspotCreditGrantReceipt`, which is an idempotency receipt proving a central financial grant was delivered once

Core must not contain an administrator that raises credit limits or edits debt. The old local `hotspotCreditFacility` schema and local credit-limit CLI were removed from new installs. Existing pilot databases may still physically contain those old tables after upgrade; they are no longer read or written. Do not drop them until any pilot values worth retaining have been reconciled with the central financial DB.

The trusted central subscriber API reads `/etc/tarasec/payment-core-client.php` (or `TARASEC_PAYMENT_CORE_CLIENT`) and calls the payment server over HTTPS using its private service token. Never install/copy that file to ordinary Student/Cigar/customer hotspots.

Credit draw flow:

1. App authenticates to the TaraSec subscriber API.
2. Core asks `tarasec_payment` to draw an approved amount for the opaque subscriber reference.
3. Payment service records debt and creates a pending grant exactly once.
4. Core adds that grant to the spendable connectivity balance in a local transaction and records `hotspotCreditGrantReceipt`.
5. Core ACKs the grant to `tarasec_payment`.

If delivery or ACK is interrupted, the pending grant is reused and the local receipt prevents double-crediting.
