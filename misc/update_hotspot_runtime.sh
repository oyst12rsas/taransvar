#!/bin/bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Run with sudo: sudo bash misc/update_hotspot_runtime.sh" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if [ ! -d "$REPO_ROOT/html/hotspot" ]; then
    echo "ERROR: $REPO_ROOT/html/hotspot is missing." >&2
    exit 1
fi

if ! command -v mysql >/dev/null 2>&1; then
    echo "ERROR: mysql client is not installed." >&2
    exit 1
fi

echo "=== Updating TaraSec hotspot runtime ==="

echo "Deploying hotspot web/API files..."
mkdir -p /var/www/html/hotspot
cp -a "$REPO_ROOT/html/hotspot/." /var/www/html/hotspot/
chown -R root:root /var/www/html/hotspot
find /var/www/html/hotspot -type d -exec chmod 0755 {} +
find /var/www/html/hotspot -type f -exec chmod 0644 {} +

for migration in \
    "$REPO_ROOT/db/migrate_hotspot_pricing.sql" \
    "$REPO_ROOT/db/migrate_hotspot_earnings.sql"
do
    if [ -s "$migration" ]; then
        echo "Applying $(basename "$migration")..."
        mysql taransvar < "$migration"
    fi
done

if [ -f "$REPO_ROOT/hotspot/opennds/tarasec-global-bind" ]; then
    echo "Updating global bind helper..."
    install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-global-bind" /usr/local/sbin/tarasec-global-bind
fi

if [ -f "$REPO_ROOT/hotspot/opennds/tarasec-hotspot-accounting" ]; then
    echo "Updating hotspot accounting helper..."
    install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-hotspot-accounting" /usr/local/sbin/tarasec-hotspot-accounting
fi

if systemctl is-active --quiet apache2; then
    systemctl reload apache2
fi

echo "Verifying pricing endpoint file..."
test -r /var/www/html/hotspot/tarasec_hotspot_info.php

echo "TaraSec hotspot runtime update complete."
