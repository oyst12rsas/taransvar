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
install -m 0755 "$ROOT_DIR/hotspot_access.pl" "$RUNTIME_DIR/hotspot_access.pl"
install -m 0755 "$ROOT_DIR/hotspot_usage.pl" "$RUNTIME_DIR/hotspot_usage.pl"
install -m 0755 "$ROOT_DIR/opennds_configure.pl" "$RUNTIME_DIR/opennds_configure.pl"
install -m 0755 "$ROOT_DIR/managed_enroll.sh" "$RUNTIME_DIR/managed_enroll.sh"
install -m 0755 "$ROOT_DIR/dhcp_capture.pl" "$RUNTIME_DIR/dhcp_capture.pl"
install -m 0644 "$ROOT_DIR/lib_dhcp.pm" "$RUNTIME_DIR/lib_dhcp.pm"
install -m 0644 "$ROOT_DIR/systemd/tarasec-hotspot-watch.service" "$SYSTEMD_DIR/tarasec-hotspot-watch.service"
install -m 0644 "$ROOT_DIR/systemd/tarasec-hotspot-watch.timer" "$SYSTEMD_DIR/tarasec-hotspot-watch.timer"
install -m 0644 "$ROOT_DIR/systemd/tarasec-hotspot-access.service" "$SYSTEMD_DIR/tarasec-hotspot-access.service"
install -m 0644 "$ROOT_DIR/systemd/tarasec-hotspot-usage.service" "$SYSTEMD_DIR/tarasec-hotspot-usage.service"
install -m 0644 "$ROOT_DIR/systemd/tarasec-hotspot-usage.timer" "$SYSTEMD_DIR/tarasec-hotspot-usage.timer"
install -m 0644 "$ROOT_DIR/systemd/tarasec-dhcp-capture@.service" "$SYSTEMD_DIR/tarasec-dhcp-capture@.service"

bash "$ROOT_DIR/setup_gateway_ai.sh"

systemctl daemon-reload
systemctl enable --now tarasec-manager-requests.timer
systemctl enable --now tarasec-hotspot-watch.timer
systemctl enable --now tarasec-hotspot-usage.timer
systemctl enable --now tarasec-hotspot-access.service

if [[ -x "$ROOT_DIR/deploy_web.sh" ]]; then
    bash "$ROOT_DIR/deploy_web.sh"
elif [[ -f "$GATEKEEPER_INDEX" ]]; then
    install -D -m 0644 "$GATEKEEPER_INDEX" "$DEPLOYED_INDEX"
fi

# openNDS is optional. If present, configure its supported FAS interface and
# let it own captive-portal packet filtering. The helper disables the TaraSec
# iptables fallback service only after openNDS has been detected.
if command -v opennds >/dev/null 2>&1 || systemctl list-unit-files opennds.service --no-legend 2>/dev/null | grep -q opennds; then
    perl "$RUNTIME_DIR/opennds_configure.pl"
fi

# Managed operation is explicit/opt-in. A one-time TaraSec enrollment token may
# be supplied by the installer environment. No reusable NetBird or Wazuh secret
# is built into the repository.
if [[ -n "${TARASEC_ENROLL_TOKEN:-}" ]]; then
    echo "[+] Enrolling this hotspot in TaraSec managed services..."
    args=(--token "$TARASEC_ENROLL_TOKEN")
    [[ -n "${TARASEC_COUNTRY:-}" ]] && args+=(--country "$TARASEC_COUNTRY")
    [[ -n "${TARASEC_ENROLL_URL:-}" ]] && args+=(--server "$TARASEC_ENROLL_URL")
    "$RUNTIME_DIR/managed_enroll.sh" "${args[@]}"
else
    echo
    echo "TaraSec managed services are available but were NOT enabled automatically."
    echo "They include the TaraSec NetBird management VPN, Wazuh/SOC monitoring,"
    echo "and optional automatic M-Pesa/GCash-compatible payment integration."
    echo "To enroll after receiving a one-time token:"
    echo "  sudo $RUNTIME_DIR/managed_enroll.sh --token TOKEN --country KE"
    echo "  # use --country PH for a Philippine hotspot"
    echo "Local information/status page: /managed-services.php"
fi

echo
echo "Installed TaraSec App/node services:"
echo "  manager verification worker:  tarasec-manager-requests.timer"
echo "  hotspot/DHCP self-check:       tarasec-hotspot-watch.timer"
echo "  hotspot usage accounting:      tarasec-hotspot-usage.timer"
echo "  hotspot access fallback:       tarasec-hotspot-access.service"
echo "  DHCP capture template:         tarasec-dhcp-capture@.service"
echo "  gateway AI worker:             tarasec-gateway-ai.timer"
if systemctl is-active --quiet opennds.service 2>/dev/null; then
    echo "  captive portal:                opennds.service + /opennds/fas.php"
else
    echo "  captive portal:                TaraSec iptables fallback"
fi
if [[ -r /etc/tarasec-managed.conf ]]; then
    echo "  managed services:              enrolled (see /managed-services.php)"
else
    echo "  managed services:              available, not enrolled"
fi
echo
echo "AI assessments still require central gateway AI policy/funding."
echo "Test hotspot health: sudo systemctl start tarasec-hotspot-watch.service"
echo "Inspect hotspot health: sudo journalctl -u tarasec-hotspot-watch.service -n 100 --no-pager"
echo "Inspect hotspot usage: sudo journalctl -u tarasec-hotspot-usage.service -n 100 --no-pager"
echo "Inspect hotspot access: sudo journalctl -u tarasec-hotspot-access.service -n 100 --no-pager"
echo "Inspect OpenNDS: sudo systemctl status opennds --no-pager"
echo "Inspect DHCP capture: sudo systemctl status 'tarasec-dhcp-capture@*' --no-pager"
echo "Test AI with: sudo systemctl start tarasec-gateway-ai.service"
echo "Inspect AI with: sudo journalctl -u tarasec-gateway-ai.service -n 100 --no-pager"
echo "Test mail with: sudo systemctl start tarasec-manager-requests.service"
