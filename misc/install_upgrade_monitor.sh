#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run with sudo on the reporting node.' >&2; exit 1; }
source_dir="$(cd "$(dirname "$0")" && pwd)"
runtime=/root/taransvar/perl
[[ -f "$runtime/upgrade_status.pm" ]] || { echo 'Existing reporter module not found.' >&2; exit 1; }
python3 -m py_compile "$source_dir/automatic_upgrade_status.py"
perl -I "$runtime" -c "$source_dir/upgrade_status.pm"
backup="/var/backups/tarasec-upgrade-monitor/$(date -u +%Y%m%dT%H%M%S)-$$"
install -d -m 0700 "$backup"
cp -a "$runtime/upgrade_status.pm" "$backup/"
if [[ -f "$runtime/automatic_upgrade_status.py" ]]; then cp -a "$runtime/automatic_upgrade_status.py" "$backup/"; fi
install -o root -g root -m 0644 "$source_dir/automatic_upgrade_status.py" "$runtime/"
cat > /etc/systemd/system/tarasec-upgrade-monitor.service <<'UNIT'
[Unit]
Description=Read-only automatic upgrade health snapshot
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /root/taransvar/perl/automatic_upgrade_status.py --write
TimeoutStartSec=20s
UMask=0022
UNIT
cat > /etc/systemd/system/tarasec-upgrade-monitor.timer <<'UNIT'
[Unit]
Description=Refresh automatic upgrade monitor evidence every minute
[Timer]
OnBootSec=30s
OnUnitInactiveSec=60s
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
systemctl enable --now tarasec-upgrade-monitor.timer
# Do not replace the working reporter unless the independent collector works.
systemctl start tarasec-upgrade-monitor.service
install -o root -g root -m 0644 "$source_dir/upgrade_status.pm" "$runtime/"
echo "Reporter now reads an independent snapshot. Backup: $backup"
