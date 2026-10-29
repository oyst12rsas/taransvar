# Exact-node SSH app target

Enrollment publishes /var/lib/tarasec-ssh-approval/target.json as root-owned 0644
metadata. It contains only the opaque dashboard server reference and HTTPS approval
service base. No node ID, credential, email or SSH port is published.

The maintained approval installer refreshes this metadata from the existing
root-only enrollment receipt on upgrade. If there is no receipt, explicit Google
enrollment is required; an app name or IP must never be guessed as the identity.

Deploy the hosted mobile approval endpoints/UI and app changes together with
managerSshApprovalTarget.php and the updated managerSsh.php. Update the node
approval installer before expecting the target file. Existing local manager
policy, tokens and firewall settings are retained.

The app performs Google sign-in via the bound browser handoff, then offers native
duration and TOTP controls. Missing metadata fails closed. The matching browser
page must reject an unknown explicit server rather than selecting another one.
