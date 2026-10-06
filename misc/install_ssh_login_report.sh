#!/bin/bash
set -euo pipefail
test "$(id -u)" = 0 || { echo "Run with sudo"; exit 1; }
src=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
install -d -m 0755 /etc/tarasec /usr/local/lib/tarasec
install -m 0755 "$src/ssh_login_report.py" /usr/local/lib/tarasec/ssh_login_report.py
if [ ! -e /etc/tarasec/ssh-login-report.conf ]; then
    install -m 0644 "$src/ssh-login-report.conf.example" /etc/tarasec/ssh-login-report.conf
fi
cat > /etc/systemd/system/tarasec-ssh-login-report.service <<'SERVICE'
[Unit]
Description=TaraSec local successful SSH login report
After=systemd-journald.service
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/lib/tarasec/ssh_login_report.py
TimeoutStartSec=25
UMask=0027
SERVICE
cat > /etc/systemd/system/tarasec-ssh-login-report.timer <<'TIMER'
[Unit]
Description=Refresh TaraSec SSH login activity every minute
[Timer]
OnBootSec=30
OnUnitActiveSec=60
[Install]
WantedBy=timers.target
TIMER
systemctl daemon-reload
systemctl enable --now tarasec-ssh-login-report.timer
systemctl start tarasec-ssh-login-report.service
echo "SSH login activity enabled for authorized local managers; configuration: /etc/tarasec/ssh-login-report.conf"
