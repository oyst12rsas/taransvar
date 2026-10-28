# SSH opening from the app

The app opens the existing operator approval page at
https://tarasec.org/ops/agent/. Opening requires Google sign-in and an explicit
Authenticator (TOTP) approval for the selected machine. A saved app-manager session
alone cannot open SSH. The app does not request or display the SSH port; the
operator uses their separately configured SSH client settings.

The previous app manager-only timed-window API no longer accepts opening requests:
POST open returns ssh_mfa_required, without creating a queue row. GET reports the
legacy allowance only and omits the port. Closing that legacy allowance remains
available. The app itself does not use these legacy controls or mistake their
countdown for the Google-authorized window.

## Node requirements and deployment

Follow [Google-authorized temporary SSH](google-ssh.md) for enrollment, local
manager policy, TOTP setup, bounded windows and console recovery. The browser
approval page must show the intended node; verify its enrolled nickname before
approving. The current handoff does not silently select or authorize a machine.

Do not enable both the legacy app timed gate and the Google gate. An existing
legacy enforced gate must be reviewed from an independent console before
migrating: its separate lease can block the Google-authorized opening. This
repository change does not remove an existing gate, reopen a firewall, alter SSH
authentication, or claim a successful live migration.

Deploy the updated API to reject old app opening attempts, then install the new
app. Verify Google sign-in, Authenticator verification, opening on the intended
node, expiry and actual SSH connectivity with the owner's known connection
settings. Confirm unauthenticated requests, manager-only direct open POSTs and
missing/invalid MFA cannot grant access. Existing SSH sessions and independent
console recovery must be checked per node.

Public status allowlists only availability and legacy-window information.
Listener ports and other private internal status fields remain out of the API.

Baseline deployment guidance: https://tarasec.org/safety/#deployment
and https://tarasec.org/safety/#hardening-priorities.
