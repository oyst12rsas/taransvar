#!/bin/bash
# Install or refresh the TaraSec manager verification worker
# (tarasec-manager-requests.timer, every minute). Used by the managed daily
# upgrade ("manager-requests" component) and by setup_node_app_services.sh.
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_DIR=/root/taransvar/perl
SYSTEMD_DIR=/etc/systemd/system
[[ $EUID -eq 0 ]] || { echo "Run with sudo: sudo bash misc/setup_manager_requests.sh" >&2; exit 1; }
[[ -f $RUNTIME_DIR/func.pm ]] || { echo "TaraSec runtime ($RUNTIME_DIR/func.pm) is not installed." >&2; exit 1; }
perl -c "$ROOT_DIR/manager_requests.pl" >/dev/null
install -m 0755 "$ROOT_DIR/manager_requests.pl" "$RUNTIME_DIR/manager_requests.pl"
install -m 0644 "$ROOT_DIR/systemd/tarasec-manager-requests.service" "$SYSTEMD_DIR/tarasec-manager-requests.service"
install -m 0644 "$ROOT_DIR/systemd/tarasec-manager-requests.timer" "$SYSTEMD_DIR/tarasec-manager-requests.timer"
systemctl daemon-reload
systemctl enable --now tarasec-manager-requests.timer
echo "Manager verification worker installed: tarasec-manager-requests.timer"
