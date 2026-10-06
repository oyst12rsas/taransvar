# Gatekeeper administrator Google sign-in

Gatekeeper's NetBird HTTP address uses a short-lived handoff from the existing
Google sign-in at `https://tarasec.org/ops/agent/gatekeeper.php`. The browser
never sends a Google credential to the HTTP address. The web servers exchange
a one-use ticket over HTTPS. Gatekeeper accepts the result only when the Google
email is already an administrator's `user.username` in its local database.

1. On the Gatekeeper DB server, create `/etc/tarasec/gatekeeper-google.php`
   from `html/gatekeeper/google_config.example.php`. Generate the separate
   shared secret with `openssl rand -hex 32`; keep the file readable by the
   Apache PHP process and outside the web root. Set its `client_id` to
   `dbserver1`.
2. On the tarasec.org web server, add `gatekeeper_clients['dbserver1']` to the
   existing `/etc/tarasec/agent-approvals.php` configuration. Set `return_url`
   to the exact browser-reachable Gatekeeper callback URL, for example
   `http://100.68.126.0/gatekeeper/google_callback.php`. Set `secret_hash` to
   the SHA-256 hash of the Gatekeeper secret, calculated locally with
   `printf %s 'THE_SECRET' | sha256sum` (never paste the secret into a chat).
   Restart the `tarasec-web` container if the configuration file is a bind
   mount to a replaced inode.
3. Deploy the new Gatekeeper PHP files and the tarasec.org `ops/agent` page
   and API. Confirm that the local Gatekeeper admin username equals the
   authorized Google email; existing password login remains available.
4. Test from a browser already connected to NetBird: choose **Continue with
   Google (administrator)** on Gatekeeper. Successful sign-in returns to Home.
   A non-admin Google account must be rejected.

The ticket lasts 90 seconds and can be redeemed once. Only the configured
Gatekeeper client can redeem it. Gatekeeper binds it to its initiating session
and regenerates the session ID after login. Keep NetBird transport and access
control on the callback HTTP endpoint; a publicly exposed Gatekeeper should
use HTTPS instead.

## Self-hosted identity/approval service

The URLs are no longer restricted to tarasec.org. Configure a compatible trusted
HTTPS `agent_api` and `sign_in_url` on the gateway, or use the administrator service
pair in `/etc/tarasec/services.php` described in [service-discovery.md](service-discovery.md).
The gateway's configured `agent_api` must match the chosen API, and its client ID,
callback and separate shared secret must be registered there. The provider that
started sign-in must still be configured when its callback arrives. Missing local
service configuration retains the existing tarasec.org flow; outages and auth
failures do not switch providers. Existing local admin eligibility still applies.
