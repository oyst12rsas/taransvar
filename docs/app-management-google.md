# Fresh Google verification for app management

Opening Manage requires both the existing locally approved manager credential
and a new Google assertion bound to this management request. Saved credentials,
old PHP cookies, long-lived subscriber tokens and another node's proof cannot
activate a session. All six management endpoints enforce freshness and exact
node identity before returning status, AI history or Assistance data.

The app starts a gateway cookie-session challenge, then a verifier-protected
Google handoff on the selected approval service. The node redeems the proof once
using its existing root-owned, web-readable Gatekeeper client configuration.
The broker checks current manager permission, matching email, exact server
reference, challenge binding, expiry and replay. The root-only agent token is
never exposed to Apache or the app.

Management lasts at most 15 minutes across Status, AI, Assistance and SSH.
SSH opening still needs TOTP and node-reported confirmation. Background polling
cannot authenticate with a saved credential. Google may use its existing SSO
when issuing a fresh assertion; TaraSec does not force password re-entry.
Legacy cookies and native tokens without confirmed management scope are rejected.

## Deployment

Deploy the website first, Core next, Android last. Keep independent console
access available while verifying the replacement management flow.
For an already-enrolled node, run from its taransvar checkout:

    git pull --ff-only origin main
    sudo bash misc/install_agent_approvals.sh --update-only

The installer deploys the complete management bundle and retains enrollment,
local grants and SSH firewall policy. The node needs its existing valid
/etc/tarasec/gatekeeper-google.php and exact public approval target.
Missing configuration means Google enrollment is incomplete; do not silently
create or replace owner policy. No payment/account database deployment is needed.
Previous deployed files are retained in the installer's printed backup directory.
Revert the complete management bundle together if acceptance fails; old code
restores its weaker authentication behavior.

## Verification

Automated tests cover real endpoint rejection of stale/legacy/wrong-node cookies,
credential-only login denial, expiry, browser request binding, node-authenticated
proof consumption, replay, cross-node rejection and mandatory TOTP. Android CI
compiles instrumentation checks; device execution remains a live acceptance step.

On the phone, open Manage and confirm sensitive tabs/data stay hidden until
fresh Google verification. Visit every tab and open SSH using Authenticator.
Switch tabs without another Google prompt. Check expiry, wrong account/node,
replay and revoked local grants. Source and CI checks do not certify live
Google/phone/node/firewall acceptance.
