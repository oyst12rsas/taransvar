#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root'; exit 1; }
source_dir="$(cd "$(dirname "$0")" && pwd)"
install -d -o root -g root -m 0755 /usr/local/lib/tarasec-operations
install -d -o root -g root -m 0700 /etc/tarasec /var/lib/tarasec-operations
install -o root -g root -m 0755 "$source_dir/operations_agent.py" "$source_dir/operations_activity.py" /usr/local/lib/tarasec-operations/
cat > /usr/local/lib/tarasec-operations/activity-probe <<'EOF'
#!/bin/sh
exec /usr/bin/python3 /usr/local/lib/tarasec-operations/operations_activity.py --read
EOF
chmod 0755 /usr/local/lib/tarasec-operations/activity-probe
if [[ ! -e /etc/tarasec/operations-activity.json ]]; then
    install -o root -g root -m 0600 "$source_dir/operations-activity.json.example" /etc/tarasec/operations-activity.json
fi
cat > /etc/systemd/system/tarasec-operations-activity.service <<'EOF'
[Unit]
Description=TaraSec continuous demo and traffic observer
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
UMask=0077
RuntimeDirectory=tarasec-operations
RuntimeDirectoryMode=0700
ExecStart=/usr/bin/python3 /usr/local/lib/tarasec-operations/operations_activity.py
Restart=on-failure
RestartSec=10
[Install]
WantedBy=multi-user.target
EOF
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
