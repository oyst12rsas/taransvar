#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
SRC="$SCRIPT_DIR/tarasec-hotspot-boot.sh"
DST=/usr/local/sbin/tarasec-hotspot-boot
UNIT=/etc/systemd/system/tarasec-hotspot-boot.service

[[ ${EUID:-$(id -u)} -eq 0 ]] || exec sudo -E bash "$0" "$@"
[[ -f "$SRC" ]] || { echo "Missing $SRC" >&2; exit 1; }
[[ -r /etc/tarasec/hotspot.conf ]] || { echo "Run hotspot/install.sh first" >&2; exit 1; }

install -m 0755 "$SRC" "$DST"

cat >"$UNIT" <<'EOF'
[Unit]
Description=TaraSec hotspot boot preparation
After=network-pre.target
Before=tarasec-hotspot-interface.service tarasec-hotspot-firewall.service tarasec-hotspot-dnsmasq.service hostapd.service opennds.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/tarasec-hotspot-boot
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

# Make every hotspot component wait for preparation even if it is started on
# its own after a failed/partial boot.
for service in tarasec-hotspot-interface tarasec-hotspot-firewall tarasec-hotspot-dnsmasq hostapd opennds; do
    mkdir -p "/etc/systemd/system/${service}.service.d"
    cat >"/etc/systemd/system/${service}.service.d/10-tarasec-boot.conf" <<EOF
[Unit]
Requires=tarasec-hotspot-boot.service
After=tarasec-hotspot-boot.service
EOF
done

systemctl daemon-reload
systemctl enable tarasec-hotspot-boot.service >/dev/null

# Apply immediately as well as on future boots.
systemctl restart tarasec-hotspot-boot.service
systemctl restart tarasec-hotspot-interface.service
systemctl restart tarasec-hotspot-firewall.service
systemctl restart tarasec-hotspot-dnsmasq.service
systemctl restart hostapd.service
systemctl restart opennds.service 2>/dev/null || true

echo "TaraSec hotspot boot recovery installed."
echo "Wi-Fi is unblocked and hotspot INPUT allowances are restored before the hotspot stack starts."
