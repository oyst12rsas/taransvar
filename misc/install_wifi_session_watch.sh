#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
WATCH_SRC="$REPO_ROOT/hotspot/opennds/tarasec-wifi-session-watch"
LOGOUT_SRC="$REPO_ROOT/hotspot/opennds/tarasec-subscriber-logout"

if [ ! -f "$WATCH_SRC" ]; then
    echo "ERROR: missing $WATCH_SRC" >&2
    exit 1
fi
if [ ! -f "$LOGOUT_SRC" ]; then
    echo "ERROR: missing $LOGOUT_SRC" >&2
    exit 1
fi

if ! command -v iw >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y iw
fi

# Repository files do not need their executable bit preserved by GitHub.
# install(1) sets the required runtime mode explicitly here.
install -m 0755 "$WATCH_SRC" /usr/local/sbin/tarasec-wifi-session-watch
install -m 0755 "$LOGOUT_SRC" /usr/local/sbin/tarasec-subscriber-logout

cat > /etc/systemd/system/tarasec-wifi-session-watch.service <<'EOF'
[Unit]
Description=TaraSec hotspot Wi-Fi session disconnect watcher
After=network-online.target opennds.service
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/sbin/tarasec-wifi-session-watch
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable tarasec-wifi-session-watch.service >/dev/null 2>&1 || true
systemctl restart tarasec-wifi-session-watch.service
sleep 1
if ! systemctl is-active --quiet tarasec-wifi-session-watch.service; then
    echo "ERROR: TaraSec Wi-Fi session watcher did not stay active." >&2
    systemctl status tarasec-wifi-session-watch.service --no-pager >&2 || true
    journalctl -u tarasec-wifi-session-watch.service -n 50 --no-pager >&2 || true
    exit 1
fi

echo "TaraSec Wi-Fi session watcher installed and active."
