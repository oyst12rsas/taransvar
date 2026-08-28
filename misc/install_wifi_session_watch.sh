#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
WATCH_SRC="$REPO_ROOT/hotspot/opennds/tarasec-wifi-session-watch"

if [ ! -x "$WATCH_SRC" ]; then
    echo "ERROR: missing executable $WATCH_SRC" >&2
    exit 1
fi

if ! command -v iw >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y iw
fi

install -m 0755 "$WATCH_SRC" /usr/local/sbin/tarasec-wifi-session-watch

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
systemctl enable --now tarasec-wifi-session-watch.service
sleep 1
systemctl is-active --quiet tarasec-wifi-session-watch.service

echo "TaraSec Wi-Fi session watcher installed and active."
