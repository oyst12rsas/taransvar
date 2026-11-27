#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR=/root/taransvar/perl
SYSTEMD_DIR=/etc/systemd/system
GATEKEEPER_INDEX="$ROOT_DIR/../html/gatekeeper/index.php"
DEPLOYED_INDEX=/var/www/html/gatekeeper/index.php

if [[ $EUID -ne 0 ]]; then
    echo "Run with sudo: sudo bash misc/setup_node_app_services.sh [--with-hotspot]" >&2
    exit 1
fi

# The hotspot watcher changes firewall rules and restarts hostapd/dnsmasq, so it
# is installed only when the owner chose the hotspot: --with-hotspot, the
# installer's TARASEC_HOTSPOT_REQUESTED=1, or an SSID already configured.
WITH_HOTSPOT=0
[[ ${1:-} == --with-hotspot || ${TARASEC_HOTSPOT_REQUESTED:-} == 1 ]] && WITH_HOTSPOT=1
if [[ $WITH_HOTSPOT -eq 0 ]] && command -v mariadb >/dev/null; then
    ssid=$(mariadb -NB taransvar -e "SELECT COALESCE(ssid,'') FROM setup LIMIT 1" 2>/dev/null || true)
    [[ -n $ssid ]] && WITH_HOTSPOT=1
fi

mkdir -p "$RUNTIME_DIR"

if [[ $WITH_HOTSPOT -eq 1 ]]; then
    install -m 0755 "$ROOT_DIR/hotspot_watch.pl" "$RUNTIME_DIR/hotspot_watch.pl"
    install -m 0755 "$ROOT_DIR/dhcp_capture.pl" "$RUNTIME_DIR/dhcp_capture.pl"
    install -m 0644 "$ROOT_DIR/lib_dhcp.pm" "$RUNTIME_DIR/lib_dhcp.pm"
    install -m 0644 "$ROOT_DIR/systemd/tarasec-hotspot-watch.service" "$SYSTEMD_DIR/tarasec-hotspot-watch.service"
    install -m 0644 "$ROOT_DIR/systemd/tarasec-hotspot-watch.timer" "$SYSTEMD_DIR/tarasec-hotspot-watch.timer"
    install -m 0644 "$ROOT_DIR/systemd/tarasec-dhcp-capture@.service" "$SYSTEMD_DIR/tarasec-dhcp-capture@.service"
fi

bash "$ROOT_DIR/setup_gateway_ai.sh"

systemctl daemon-reload
bash "$ROOT_DIR/setup_manager_requests.sh"
if [[ $WITH_HOTSPOT -eq 1 ]]; then
    systemctl enable --now tarasec-hotspot-watch.timer
else
    echo "Hotspot not chosen for this node; hotspot watcher not installed (use --with-hotspot to add it)."
fi

if [[ -x "$ROOT_DIR/deploy_web.sh" ]]; then
    bash "$ROOT_DIR/deploy_web.sh"
elif [[ -f "$GATEKEEPER_INDEX" ]]; then
    install -D -m 0644 "$GATEKEEPER_INDEX" "$DEPLOYED_INDEX"
fi

echo
echo "Installed TaraSec App/node services:"
echo "  manager verification worker: tarasec-manager-requests.timer"
if [[ $WITH_HOTSPOT -eq 1 ]]; then
    echo "  hotspot/DHCP self-check:      tarasec-hotspot-watch.timer"
    echo "  DHCP capture template:        tarasec-dhcp-capture@.service"
fi
echo "  gateway AI worker:            tarasec-gateway-ai.timer"
echo
echo "AI assessments still require central gateway AI policy/funding."
if [[ $WITH_HOTSPOT -eq 1 ]]; then
    echo "Test hotspot with: sudo systemctl start tarasec-hotspot-watch.service"
    echo "Inspect hotspot with: sudo journalctl -u tarasec-hotspot-watch.service -n 100 --no-pager"
    echo "Inspect DHCP capture with: sudo systemctl status 'tarasec-dhcp-capture@*' --no-pager"
fi
echo "Test AI with: sudo systemctl start tarasec-gateway-ai.service"
echo "Inspect AI with: sudo journalctl -u tarasec-gateway-ai.service -n 100 --no-pager"
echo "Test mail with: sudo systemctl start tarasec-manager-requests.service"
