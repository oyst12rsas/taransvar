# TaraSec Braintree / PayPal payments

TaraSec keeps all merchant credentials on the server. Android and hotspot browser clients receive only a short-lived Braintree client token.

## Sandbox setup

Install the PHP SDK on the web host from the repository root (or another location covered by `paymentConfig.php`):

```bash
composer require braintree/braintree_php
```

Set server-side environment variables. Do not put these values in Git, Android resources, JavaScript, QR codes, or TaraSec.conf files that are distributed to clients.

```bash
export TARASEC_PAYMENT_ENV=sandbox
export BRAINTREE_MERCHANT_ID='...'
export BRAINTREE_PUBLIC_KEY='...'
export BRAINTREE_PRIVATE_KEY='...'
export BRAINTREE_DEFAULT_CURRENCY=USD
```

For additional currencies, configure the corresponding Braintree merchant account ID, for example:

```bash
export BRAINTREE_MERCHANT_ACCOUNT_PHP='...'
export BRAINTREE_MERCHANT_ACCOUNT_KES='...'
export BRAINTREE_MERCHANT_ACCOUNT_NOK='...'
```

Persist these through the web server/systemd environment rather than shell-only exports.

## Endpoints

- `GET /script/paymentSession.php` returns payment capability information and, when configured, a short-lived Braintree client token.
- `POST /script/paymentCheckout.php` accepts a payment-method nonce, amount, currency, hotspot ID and optional research-discount flag, then creates and submits a Braintree transaction for settlement.

The checkout endpoint generates the TaraSec order ID on the server. It never accepts or returns Braintree private credentials.

## Client methods

The planned common payment layer exposes:

- PayPal
- Google Pay for Android/web where supported
- Apple Pay through compatible web/iOS checkout

Actual method availability is controlled by the Braintree/PayPal merchant configuration and device/browser support.

## Research discounts

`researchDiscount=1` is currently contextual metadata from the client. The authoritative price must ultimately be calculated or verified by the hotspot/server before production use; clients must not be trusted to choose their own discounted amount.

## Before production

Production deployment still needs server-side price/plan lookup, transaction/order persistence, webhook verification, idempotency, refund handling, and hotspot-access activation only after confirmed payment state. Sandbox testing should happen before setting `TARASEC_PAYMENT_ENV=production`.
