#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR=/root/taransvar/perl
SYSTEMD_DIR=/etc/systemd/system

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo bash misc/setup_gateway_ai.sh" >&2
    exit 1
fi

mkdir -p "$RUNTIME_DIR"
install -m 0755 "$ROOT_DIR/gateway_ai_assess.pl" "$RUNTIME_DIR/gateway_ai_assess.pl"
install -m 0644 "$ROOT_DIR/systemd/tarasec-gateway-ai.service" "$SYSTEMD_DIR/tarasec-gateway-ai.service"
install -m 0644 "$ROOT_DIR/systemd/tarasec-gateway-ai.timer" "$SYSTEMD_DIR/tarasec-gateway-ai.timer"

systemctl daemon-reload
systemctl enable --now tarasec-gateway-ai.timer

echo "Installed TaraSec gateway AI worker and timer."
echo "Run one test with: sudo systemctl start tarasec-gateway-ai.service"
echo "Inspect with: sudo journalctl -u tarasec-gateway-ai.service -n 100 --no-pager"
