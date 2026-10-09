#!/usr/bin/env bash
set -euo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run with sudo on the gateway.' >&2; exit 1; }
enforce=false
case ${1:-} in
    '') [[ $# -eq 0 ]] || exit 2 ;;
    --enforce-gate) [[ $# -eq 1 ]] || exit 2; enforce=true ;;
    --confirm-gate)
        [[ $# -eq 1 && -f /etc/tarasec/manager-ssh-gate.enabled ]] || exit 2
        php -r 'require "/usr/local/share/tarasec/manager-ssh/html/script/managerSshCommon.php"; $s=managerSshPublic(); if (empty($s["enabled"]) || empty($s["gateEnforced"])) exit(1);'
        systemctl disable --now tarasec-manager-ssh-rollback.timer
        echo 'Timed gate kept enabled; automatic rollback cancelled after operator verification.'
        exit ;;
    *) echo 'Usage: setup_manager_ssh.sh [--enforce-gate|--confirm-gate]' >&2; exit 2 ;;
esac
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
for tool in php mysql systemctl ipset iptables ip6tables ss; do command -v "$tool" >/dev/null; done
[[ -f /etc/tarasecfw.conf && -f /var/www/html/dbfunc.php ]] || { echo 'Existing gateway installation required.' >&2; exit 1; }
for file in html/script/managerSshCommon.php html/script/managerSsh.php misc/manager_ssh_worker.php; do php -l "$repo_dir/$file"; done
php "$repo_dir/misc/manager_ssh_worker.php" --check
if $enforce; then
    # Verify the app-to-worker reopening path before adding a blocking rule.
    php -r 'require $argv[1]; require "/var/www/html/dbfunc.php"; mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT); sshGateReady(getConnection(),sshConfig("/etc/tarasecfw.conf"));' "$repo_dir/misc/manager_ssh_worker.php"
    echo '=== EFFECTIVE SSH LOGIN CONFIGURATION (UNCHANGED) ==='
    /usr/sbin/sshd -T | awk '/^(port|listenaddress|pubkeyauthentication|passwordauthentication|authenticationmethods|authorizedkeysfile) /'
fi
backup=$(mktemp -d /var/backups/tarasec-manager-ssh.XXXXXX)
for file in /var/www/html/script/managerSsh.php /var/www/html/script/managerSshCommon.php /var/www/html/script/managerOverview.php /etc/systemd/system/tarasec-manager-ssh.service /etc/systemd/system/tarasec-manager-ssh.timer; do
    [[ ! -f $file ]] || cp -a --parents "$file" "$backup/"
done
[[ ! -d /usr/local/share/tarasec/manager-ssh ]] || cp -a /usr/local/share/tarasec/manager-ssh "$backup/worker"
systemctl stop tarasec-manager-ssh.timer 2>/dev/null || true
systemctl stop tarasec-manager-ssh.service 2>/dev/null || true
mysql taransvar < "$repo_dir/misc/manager_ssh.sql"
install -d -m 0755 -o root -g root /usr/local/share/tarasec/manager-ssh/html/script /usr/local/share/tarasec/manager-ssh/misc /run/tarasec-app-ssh
install -d -m 0700 -o root -g root /etc/tarasec
for name in managerSsh managerSshCommon managerOverview; do install -m 0644 "$repo_dir/html/script/$name.php" /var/www/html/script/; done
install -m 0644 "$repo_dir/html/script/managerSshCommon.php" /usr/local/share/tarasec/manager-ssh/html/script/
install -m 0600 -o root -g root /var/www/html/dbfunc.php /usr/local/share/tarasec/manager-ssh/html/dbfunc.php
install -m 0644 "$repo_dir/misc/manager_ssh_worker.php" /usr/local/share/tarasec/manager-ssh/misc/
install -m 0755 "$repo_dir/misc/manager_ssh_rollback.sh" /usr/local/share/tarasec/manager-ssh/misc/
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
if $enforce; then
    cat > /etc/systemd/system/tarasec-manager-ssh-rollback.service <<'UNIT'
[Unit]
Description=Restore previous SSH access if timed gate is not verified
[Service]
Type=oneshot
ExecStart=/usr/local/share/tarasec/manager-ssh/misc/manager_ssh_rollback.sh
UNIT
    cat > /etc/systemd/system/tarasec-manager-ssh-rollback.timer <<'UNIT'
[Unit]
Description=Automatic rollback during timed SSH gate acceptance
[Timer]
OnActiveSec=10min
AccuracySec=1s
[Install]
WantedBy=timers.target
UNIT
fi
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
if $enforce; then
    systemctl enable --now tarasec-manager-ssh-rollback.timer
    systemctl restart tarasec-manager-ssh-rollback.timer
    install -m 0600 /dev/null /etc/tarasec/manager-ssh-gate.enabled
fi
systemctl start tarasec-manager-ssh.service
systemctl enable --now tarasec-manager-ssh.timer
printf 'Installed manager SSH controls. Backup: %s\n' "$backup"
if $enforce; then
    echo 'Timed gate enforced: configured sources need an active window for NEW connections. Existing sessions and explicit recovery sources are retained.'
    echo 'Automatic rollback in 10 minutes. Test close, rejected new connection, reopen and successful NEW connection, then run setup_manager_ssh.sh --confirm-gate.'
else
    echo 'No new gate enabled. Use --enforce-gate after verifying an app-requested opening.'
fi
