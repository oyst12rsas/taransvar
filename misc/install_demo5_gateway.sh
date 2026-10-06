#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
test -f /etc/tarasec/demo5-gateway.json || { echo 'Create root-only demo5-gateway.json with enabled, db_url and node_token first.'; exit 1; }
test -w /sys/module/tarakernel/parameters/demo5_pause_until || { echo 'Rebuild and load the updated tarakernel first.'; exit 1; }
chmod 600 /etc/tarasec/demo5-gateway.json
ROOT=$(pwd)
cat >/etc/systemd/system/tarasec-demo5-gateway.service <<EOF
[Unit]
Description=TaraSec bounded Demo 5 tagging pause
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/bin/php $ROOT/misc/demo5_gateway_worker.php
EOF
cat >/etc/systemd/system/tarasec-demo5-gateway.timer <<EOF
[Unit]
Description=Poll DB registered Demo 5 exercise
[Timer]
OnBootSec=30s
OnUnitInactiveSec=5s
[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now tarasec-demo5-gateway.timer
