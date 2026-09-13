#!/bin/bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Run with sudo: sudo $0" >&2
    exit 1
fi

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE="$REPO_ROOT/taralink/taralink.c"
INSTALLED="/usr/local/sbin/taralink"
BACKUP_DIR="/var/backups/tarasec"
BUILD_DIR="$(mktemp -d /tmp/tarasec-demo2-repair.XXXXXX)"
BUILD="$BUILD_DIR/taralink"
STARTED_AT="$(date --iso-8601=seconds)"

cleanup() {
    rm -rf -- "$BUILD_DIR"
}
trap cleanup EXIT

[ -f "$SOURCE" ] || { echo "Missing source: $SOURCE" >&2; exit 1; }
grep -q 'expected 6 or 10' "$REPO_ROOT/taralink/module_traffic_report.c" || {
    echo "Repository does not contain the Demo 2 compatibility fix. Pull main first." >&2
    exit 1
}

echo "Building taralink with six/ten-field traffic-report compatibility..."
gcc -g "$SOURCE" -o "$BUILD" $(mysql_config --cflags --libs) -lcurl -lcjson
test -x "$BUILD"
ldd "$BUILD" >/dev/null

install -d -m 0750 "$BACKUP_DIR"
if [ -f "$INSTALLED" ]; then
    BACKUP="$BACKUP_DIR/taralink.$(date +%Y%m%dT%H%M%S).bak"
    install -m 0755 "$INSTALLED" "$BACKUP"
    echo "Backup: $BACKUP"
fi

install -o root -g root -m 0755 "$BUILD" "$INSTALLED"
systemctl restart taralink.service
systemctl is-active --quiet taralink.service

echo "taralink restarted successfully."
echo "Checking for rejected traffic records since $STARTED_AT..."
journalctl -u taralink.service --since "$STARTED_AT" --no-pager \
    | grep -E 'Invalid traffic record|records inserted|records updated' || true

echo
echo "Repair installed. Repeat one Demo 2 Node A attempt, then run:"
echo "  sudo journalctl -u taralink.service -n 80 --no-pager"
