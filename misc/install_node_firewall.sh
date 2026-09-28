#!/usr/bin/env bash
# Keep the role-specific node firewall logging hook present after NetBird refreshes.
set -euo pipefail
if (( EUID != 0 )); then
    echo "Run as root: sudo bash misc/install_node_firewall.sh" >&2
    exit 1
fi
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
test -r /etc/tarasecfw.conf || { echo "Missing /etc/tarasecfw.conf" >&2; exit 1; }
# The config is already sourced by firewall.sh; check the role before installing a service.
source /etc/tarasecfw.conf
[[ "${IS_GATEWAY:-}" == "0" ]] || { echo "Set IS_GATEWAY=0 for this node" >&2; exit 1; }
[[ "$repo_root" != *' '* ]] || { echo "Checkout path may not contain spaces" >&2; exit 1; }
cat > /etc/systemd/system/tarasec-node-firewall.service <<EOF
[Unit]
Description=TaraSec node firewall logging hook
Wants=netbird.service
After=netbird.service network-online.target
ConditionPathExists=/etc/tarasecfw.conf

[Service]
Type=oneshot
ExecStart=/bin/bash $repo_root/misc/firewall.sh
EOF
cat > /etc/systemd/system/tarasec-node-firewall.timer <<'EOF'
[Unit]
Description=Reapply TaraSec node firewall logging after NetBird changes

[Timer]
OnBootSec=1min
OnUnitInactiveSec=2min
Unit=tarasec-node-firewall.service

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now tarasec-node-firewall.timer
systemctl start tarasec-node-firewall.service
echo "Node logging hook installed without flushing NetBird rules."
