#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR=/root/taransvar/perl
SYSTEMD_DIR=/etc/systemd/system
GATEKEEPER_INDEX="$ROOT_DIR/../html/gatekeeper/index.php"
DEPLOYED_INDEX=/var/www/html/gatekeeper/index.php

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo bash misc/setup_node_app_services.sh" >&2
    exit 1
fi

python3 - "$GATEKEEPER_INDEX" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
s=p.read_text()
old='if (intval($setupRow["dbVersion"])+0 != $nRequiredDbVersion)'
new='if (intval($setupRow["dbVersion"])+0 < $nRequiredDbVersion)'
if old in s:
    s=s.replace(old,new,1)
    p.write_text(s)
PY

mkdir -p "$RUNTIME_DIR"
install -m 0755 "$ROOT_DIR/manager_requests.pl" "$RUNTIME_DIR/manager_requests.pl"
install -m 0644 "$ROOT_DIR/systemd/tarasec-manager-requests.service" "$SYSTEMD_DIR/tarasec-manager-requests.service"
install -m 0644 "$ROOT_DIR/systemd/tarasec-manager-requests.timer" "$SYSTEMD_DIR/tarasec-manager-requests.timer"

install -m 0755 "$ROOT_DIR/hotspot_watch.pl" "$RUNTIME_DIR/hotspot_watch.pl"
install -m 0755 "$ROOT_DIR/dhcp_capture.pl" "$RUNTIME_DIR/dhcp_capture.pl"
install -m 0644 "$ROOT_DIR/systemd/tarasec-hotspot-watch.service" "$SYSTEMD_DIR/tarasec-hotspot-watch.service"
install -m 0644 "$ROOT_DIR/systemd/tarasec-hotspot-watch.timer" "$SYSTEMD_DIR/tarasec-hotspot-watch.timer"
install -m 0644 "$ROOT_DIR/systemd/tarasec-dhcp-capture@.service" "$SYSTEMD_DIR/tarasec-dhcp-capture@.service"

bash "$ROOT_DIR/setup_gateway_ai.sh"

systemctl daemon-reload
systemctl enable --now tarasec-manager-requests.timer
systemctl enable --now tarasec-hotspot-watch.timer

if [[ -x "$ROOT_DIR/deploy_web.sh" ]]; then
    bash "$ROOT_DIR/deploy_web.sh"
elif [[ -f "$GATEKEEPER_INDEX" ]]; then
    install -D -m 0644 "$GATEKEEPER_INDEX" "$DEPLOYED_INDEX"
fi

echo
echo "Installed TaraSec App/node services:"
echo "  manager verification worker: tarasec-manager-requests.timer"
echo "  hotspot/DHCP self-check:      tarasec-hotspot-watch.timer"
echo "  DHCP capture template:        tarasec-dhcp-capture@.service"
echo "  gateway AI worker:            tarasec-gateway-ai.timer"
echo
echo "AI assessments still require central gateway AI policy/funding."
echo "Test hotspot with: sudo systemctl start tarasec-hotspot-watch.service"
echo "Inspect hotspot with: sudo journalctl -u tarasec-hotspot-watch.service -n 100 --no-pager"
echo "Inspect DHCP capture with: sudo systemctl status 'tarasec-dhcp-capture@*' --no-pager"
echo "Test AI with: sudo systemctl start tarasec-gateway-ai.service"
echo "Inspect AI with: sudo journalctl -u tarasec-gateway-ai.service -n 100 --no-pager"
echo "Test mail with: sudo systemctl start tarasec-manager-requests.service"
