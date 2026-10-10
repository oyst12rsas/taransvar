# Local database credentials

The local database application passwords are generated independently on each
machine using 32 random bytes. They are not source-code defaults, VPN joining
keys or central API credentials. Normal installation and the hotspot installer
provision them through `misc/createUsers.pl` and the shared credential installer.

Files outside the web root:

- `/etc/tarasec/db-app.password`: application password, root-owned, mode 0640,
  readable by the configured web group (default `www-data`).
- `/etc/tarasec/db-perl.password`: legacy hotspot Perl account, root-only 0600.
- `/etc/tarasec/db-app.cnf` and `db-perl.cnf`: root-only CLI option files.
- `/etc/tarasec/access-mysql.cnf`: root-only openNDS option file using the app
  account. It is derived from the generated password rather than hardcoded.

The configuration directory is root-owned, mode 0750, with the web group.
Set `TARASEC_DB_WEB_GROUP` when installing with a different PHP web account.
Other secrets in that directory must retain their own restricted permissions.
PHP, Perl and Taralink read the password files; CLI checks use option files,
so passwords are not put in process arguments. SQL provisioning uses root's
local socket and stdin. Failure output is sanitized; no credential is printed.

## Upgrade and reinstall

The first updated install generates passwords and replaces the fixed passwords
of the two `localhost` accounts. Install all updated readers, rebuild/restart
Taralink and restart affected long-running clients in the same maintenance
window. Until those readers are updated, older clients cannot authenticate with
the replacement password. No live hosts are rotated by a repository change.

Later reinstalls preserve valid generated passwords, repair the derived option
files and verify authentication. If interrupted during account creation, rerun
the installer: generated credentials are retained for recovery. Corrupt or
symlink credential files fail explicitly rather than being silently regenerated.
Back up the private files securely. A full uninstall removes them; a subsequent
fresh install generates new passwords.

Only local accounts are created/updated and retain the existing CRUD grants on
`taransvar.*`; no wildcard-host account or administrative DB grant is created.
Existing non-local account entries are not changed automatically: audit them
separately when migrating central database access to authenticated APIs. These
files are not distributed to other nodes by enrollment.

For an existing checkout, the credential-only installer is:

```sh
sudo bash misc/install_database_credentials.sh
```

Use it only with the accompanying updated PHP/Perl files and rebuilt Taralink.
It is not a standalone zero-downtime rotation command for old clients.
