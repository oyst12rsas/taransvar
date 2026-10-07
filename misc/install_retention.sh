#!/usr/bin/env bash
set -euo pipefail
if (( EUID != 0 )); then
    echo 'Run as root: sudo bash misc/install_retention.sh' >&2
    exit 1
fi
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
for command in perl logrotate systemctl; do
    command -v "$command" >/dev/null || { echo "Missing command: $command" >&2; exit 1; }
done
perl -MDBI -MDBD::mysql -e 1
perl -I "$repo_root/misc" -c "$repo_root/misc/db_retention.pl"
install -d -m 0750 /usr/local/lib/tarasec-retention /var/lib/tarasec-retention
install -m 0644 "$repo_root/misc/"{db_retention.pl,lib_retention.pm,func.pm} /usr/local/lib/tarasec-retention/
if [[ ! -e /etc/tarasec-retention.conf ]]; then
    install -m 0600 "$repo_root/misc/retention.conf.example" /etc/tarasec-retention.conf
fi
install -m 0644 "$repo_root/misc/retention.logrotate" /etc/tarasec-retention.logrotate
logrotate --debug /etc/tarasec-retention.logrotate

# Validate DB connectivity and the local policy without deleting rows before enabling.
perl /usr/local/lib/tarasec-retention/db_retention.pl
cat > /etc/systemd/system/tarasec-retention.service <<'EOF'
[Unit]
Description=Retain recent TaraSec telemetry and rotate diagnostic logs
After=mariadb.service mysql.service

[Service]
Type=oneshot
UMask=0077
Nice=10
IOSchedulingClass=idle
TimeoutStartSec=90
ExecStart=/usr/sbin/logrotate --state /var/lib/tarasec-retention/logrotate.state /etc/tarasec-retention.logrotate
ExecStart=/usr/bin/perl /usr/local/lib/tarasec-retention/db_retention.pl --apply
EOF
cat > /etc/systemd/system/tarasec-retention.timer <<'EOF'
[Unit]
Description=Clean old TaraSec telemetry every 15 minutes

[Timer]
OnBootSec=5min
OnUnitInactiveSec=15min
RandomizedDelaySec=60
Unit=tarasec-retention.service

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now tarasec-retention.timer
echo 'Retention installed. Preview passed; first cleanup runs through the timer.'
echo 'Policy: /etc/tarasec-retention.conf'
echo 'Inspect: journalctl -u tarasec-retention.service --no-pager -n 50'
