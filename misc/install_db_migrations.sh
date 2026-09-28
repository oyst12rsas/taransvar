#!/usr/bin/env bash
# Install and run the local, reviewed schema migration service.
set -euo pipefail

if (( EUID != 0 )); then
    echo "Run as root: sudo bash misc/install_db_migrations.sh" >&2
    exit 1
fi

repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ "$repo_root" == *' '* ]]; then
    echo "Checkout path must not contain spaces for systemd ExecStart" >&2
    exit 1
fi
test -f "$repo_root/misc/install.sql"
test -f "$repo_root/misc/upgrade_db.pl"

cat > /etc/systemd/system/tarasec-db-migrate.service <<EOF
[Unit]
Description=Apply reviewed TaraSec local database migrations
After=mariadb.service mysql.service

[Service]
Type=oneshot
ExecStart=/usr/bin/perl $repo_root/misc/upgrade_db.pl
EOF

cat > /etc/systemd/system/tarasec-db-migrate.timer <<'EOF'
[Unit]
Description=Retry TaraSec database migration after startup

[Timer]
OnBootSec=2min
OnUnitInactiveSec=5min
Persistent=true
Unit=tarasec-db-migrate.service

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now tarasec-db-migrate.timer
systemctl start tarasec-db-migrate.service
echo "Local database migration is current; timer will check for new reviewed versions."
