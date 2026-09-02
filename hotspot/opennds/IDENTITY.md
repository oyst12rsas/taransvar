# TaraSec global identity

Public URL ownership is deliberately separated:

- `/hotspot/` is reserved for node-local hotspot administration and captive portal pages.
- `/api/v1/subscriber/` is the central subscriber account API.
- `/api/v1/identity/` is the central Google/Facebook identity API.

A global subscriber token is never accepted as a node-management credential.

TaraSec subscriber identity is global. Google and Facebook authenticate a stable
provider account to the central TaraSec service. Hotspot nodes receive only a
TaraSec subscriber token; provider access tokens and secrets never leave the
central service.

Global subscriber identity does not grant node-management authority. A node
owner must still approve managers locally, and management tokens must remain
separate from subscriber tokens.

## Server configuration

Configure these values in the central web server environment:

- `TARASEC_GOOGLE_CLIENT_ID`
- `TARASEC_GOOGLE_CLIENT_SECRET`
- `TARASEC_FACEBOOK_CLIENT_ID`
- `TARASEC_FACEBOOK_CLIENT_SECRET`
- `TARASEC_IDENTITY_CALLBACK` (defaults to the production callback)
- `TARASEC_IDENTITY_APP_REDIRECTS` (comma-separated allowlist)

Register this callback with both providers:

`https://tarasec.org/api/v1/identity/identity-callback.php`

The Android pilot uses `tarasec://identity`. The callback returns a random,
single-use code valid for two minutes. The app exchanges that code for a
90-day TaraSec subscriber token stored in Android Keystore.

Facebook proves control of the Facebook account, but TaraSec does not mark a
Facebook email address verified because the provider response has no portable
`email_verified` claim. Google email is marked verified only when Google
returns that claim and the ID-token audience matches TaraSec's client ID.

For production, replace the custom URI callback with verified HTTPS Android App
Links and iOS Universal Links. The one-time code limits interception impact, but
verified links also prevent another installed app from claiming the callback.
