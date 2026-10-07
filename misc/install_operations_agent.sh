#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root'; exit 1; }
source_dir="$(cd "$(dirname "$0")" && pwd)"
install -d -o root -g root -m 0755 /usr/local/lib/tarasec-operations
install -d -o root -g root -m 0700 /etc/tarasec /var/lib/tarasec-operations
install -o root -g root -m 0755 "$source_dir/operations_agent.py" /usr/local/lib/tarasec-operations/
install -o root -g root -m 0644 "$source_dir/../docs/AI_OPERATIONS_MANUAL.md" /usr/local/lib/tarasec-operations/
if [[ ! -e /etc/tarasec/operations-agent.json ]]; then
    install -o root -g root -m 0600 "$source_dir/operations-agent.json.example" /etc/tarasec/operations-agent.json
fi
if [[ ! -e /etc/tarasec/operations-procedures.json ]]; then
    printf '{"procedures":{}}\n' > /etc/tarasec/operations-procedures.json
    chmod 0600 /etc/tarasec/operations-procedures.json
fi
cat > /etc/systemd/system/tarasec-operations-agent.service <<'EOF'
[Unit]
Description=TaraSec model-driven operations pilot
After=network-online.target
Wants=network-online.target
[Service]
Type=oneshot
UMask=0077
ExecStart=/usr/bin/python3 /usr/local/lib/tarasec-operations/operations_agent.py
TimeoutStartSec=15min
EOF
cat > /etc/systemd/system/tarasec-operations-agent.timer <<'EOF'
[Unit]
Description=Resume TaraSec operations pilot
[Timer]
OnBootSec=2min
OnUnitInactiveSec=1min
[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
echo 'Installed, not enabled. Configure and test the dedicated model connection first.'
echo 'See docs/AI_OPERATIONS_MANUAL.md for pilot enrollment and activity-probe contract.'
