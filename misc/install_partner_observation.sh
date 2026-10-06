#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
install -d -m 0755 /etc/tarasec
if [ ! -e /etc/tarasec/partner-observation.php ]; then
    install -o root -g www-data -m 0640 misc/partner-observation.example.php /etc/tarasec/partner-observation.php
fi
ROOT=$(pwd)
cat >/etc/systemd/system/tarasec-partner-observation.service <<EOF
[Unit]
Description=TaraSec targeted partner observation
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/bin/php $ROOT/misc/partner_observation_worker.php
TimeoutStartSec=90
EOF
cat >/etc/systemd/system/tarasec-partner-observation.timer <<EOF
[Unit]
Description=Sample and assess targeted partner observation
[Timer]
OnBootSec=1min
OnUnitInactiveSec=20s
[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now tarasec-partner-observation.timer
echo 'Apply partner_observation.sql on DB first. Enable DB config explicitly. Receiver credentials use partner-restrictions.json.'
