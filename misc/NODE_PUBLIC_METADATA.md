# Public node discovery during migration

The hosted gateway API reads `/var/lib/tarasec-node/public-node.json` first.
This dedicated directory is root-owned (0755); its metadata file is root-owned
(0644), readable by PHP and contains only version, linking mode, node/gateway IDs,
node name and public HTTPS account service addresses. It is outside the web root.
Private subject keys and derived node authentication secrets are never published.

When the metadata file is absent, the API temporarily retains its existing
`/etc/tarasec/unit-link.php` path for older deployments. A present but invalid or
unreadable metadata file fails closed rather than falling back. No permissions
on `/etc/tarasec` are changed by this migration.

`setup_gateway_app_account.sh` installs the helper, creates the public metadata
from the existing stable identity and grants the root worker write access to
the dedicated directory under its systemd sandbox. The worker refreshes the
metadata each cycle before contacting the account service. Run the setup helper
with the existing node origin to migrate an installation; do not generate a new
gateway ID or subject key, as that would invalidate existing phone links.

Verification: the local GET `/script/unitGatewayLink.php` must return 200 with
the same node/gateway IDs, and credential-bearing POST requests must still be
rejected in hosted mode. Confirm PHP cannot read the private configuration on
root-only deployments, and check that the root worker continues to succeed.
