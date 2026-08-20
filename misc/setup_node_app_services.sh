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

# Gatekeeper dbVersion is a minimum schema capability, not an exact application
# version.  Keep the historical minimum number but make newer schemas valid.
python3 - "$GATEKEEPER_INDEX" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
s=p.read_text()
old='if (intval($setupRow["dbVersion"])+0 != $nRequiredDbVersion)'
new='if (intval($setupRow["dbVersion"])+0 < $nRequiredDbVersion)'
if old in s:
    s=s.replace(old,new,1)
    # With a minimum-version test the inner "older" test is always true; leave
    # the old newer-version branch unreachable to minimize change to legacy PHP.
    p.write_text(s)
PY

mkdir -p "$RUNTIME_DIR"
install -m 0755 "$ROOT_DIR/manager_requests.pl" "$RUNTIME_DIR/manager_requests.pl"
install -m 0644 "$ROOT_DIR/systemd/tarasec-manager-requests.service" "$SYSTEMD_DIR/tarasec-manager-requests.service"
install -m 0644 "$ROOT_DIR/systemd/tarasec-manager-requests.timer" "$SYSTEMD_DIR/tarasec-manager-requests.timer"

# Install gateway-local AI assessment worker/timer as part of every App-manageable
# node.  Whether an AI call is allowed/funded remains controlled by central policy.
bash "$ROOT_DIR/setup_gateway_ai.sh"

systemctl daemon-reload
systemctl enable --now tarasec-manager-requests.timer

if [[ -x "$ROOT_DIR/deploy_web.sh" ]]; then
    bash "$ROOT_DIR/deploy_web.sh"
elif [[ -f "$GATEKEEPER_INDEX" ]]; then
    install -D -m 0644 "$GATEKEEPER_INDEX" "$DEPLOYED_INDEX"
fi

echo
echo "Installed TaraSec App/node services:"
echo "  manager verification worker: tarasec-manager-requests.timer"
echo "  gateway AI worker:           tarasec-gateway-ai.timer"
echo
echo "AI assessments still require central gateway AI policy/funding."
echo "Test AI with: sudo systemctl start tarasec-gateway-ai.service"
echo "Inspect AI with: sudo journalctl -u tarasec-gateway-ai.service -n 100 --no-pager"
echo "Test mail with: sudo systemctl start tarasec-manager-requests.service"
