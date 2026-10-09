#!/usr/bin/env bash
set -euo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run with sudo on the gateway.' >&2; exit 1; }
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
for tool in php mysql systemctl ipset iptables ss; do command -v "$tool" >/dev/null; done
[[ -f /etc/tarasecfw.conf && -f /var/www/html/dbfunc.php ]] || { echo 'Existing gateway installation required.' >&2; exit 1; }
for file in html/script/managerSshCommon.php html/script/managerSsh.php misc/manager_ssh_worker.php; do php -l "$repo_dir/$file"; done
php "$repo_dir/misc/manager_ssh_worker.php" --check
backup=$(mktemp -d /var/backups/tarasec-manager-ssh.XXXXXX)
for file in /var/www/html/script/managerSsh.php /var/www/html/script/managerSshCommon.php /var/www/html/script/managerOverview.php /etc/systemd/system/tarasec-manager-ssh.service /etc/systemd/system/tarasec-manager-ssh.timer; do
    [[ ! -f $file ]] || cp -a --parents "$file" "$backup/"
done
mysql taransvar < "$repo_dir/misc/manager_ssh.sql"
install -d -m 0755 -o root -g root /usr/local/share/tarasec/manager-ssh/html/script /usr/local/share/tarasec/manager-ssh/misc /run/tarasec-app-ssh
install -d -m 0700 -o root -g root /etc/tarasec
for name in managerSsh managerSshCommon managerOverview; do install -m 0644 "$repo_dir/html/script/$name.php" /var/www/html/script/; done
install -m 0644 "$repo_dir/html/script/managerSshCommon.php" /usr/local/share/tarasec/manager-ssh/html/script/
install -m 0600 -o root -g root /var/www/html/dbfunc.php /usr/local/share/tarasec/manager-ssh/html/dbfunc.php
install -m 0644 "$repo_dir/misc/manager_ssh_worker.php" /usr/local/share/tarasec/manager-ssh/misc/
install -m 0600 /dev/null /etc/tarasec/manager-ssh.enabled
cat > /etc/systemd/system/tarasec-manager-ssh.service <<'UNIT'
[Unit]
Description=Apply bounded manager SSH windows
After=network-online.target mariadb.service systemd-tmpfiles-setup.service
[Service]
Type=oneshot
ExecStart=/usr/bin/php /usr/local/share/tarasec/manager-ssh/misc/manager_ssh_worker.php
RuntimeDirectory=tarasec-app-ssh
RuntimeDirectoryMode=0755
RuntimeDirectoryPreserve=yes
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/run/tarasec-app-ssh /run/xtables.lock
ProtectHome=true
PrivateTmp=true
TimeoutStartSec=20
UNIT
cat > /etc/systemd/system/tarasec-manager-ssh.timer <<'UNIT'
[Unit]
Description=Refresh temporary SSH openings
[Timer]
OnBootSec=5s
OnUnitInactiveSec=5s
AccuracySec=1s
[Install]
WantedBy=timers.target
UNIT
# Keep the shared iptables lock available after reboot as well as during install.
echo 'f /run/xtables.lock 0644 root root -' > /etc/tmpfiles.d/tarasec-manager-ssh.conf
systemd-tmpfiles --create /etc/tmpfiles.d/tarasec-manager-ssh.conf
systemctl daemon-reload
systemctl start tarasec-manager-ssh.service
systemctl enable --now tarasec-manager-ssh.timer
printf 'Installed temporary SSH controls. Backup: %s\nExisting SSH policy and recovery access are unchanged. No temporary opening requested.\n' "$backup"
