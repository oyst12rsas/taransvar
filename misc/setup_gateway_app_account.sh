#!/usr/bin/env bash
set -euo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run with sudo on the node.' >&2; exit 1; }
[[ $# -eq 1 ]] || { echo 'Usage: sudo bash misc/setup_gateway_app_account.sh NODE_ORIGIN' >&2; exit 2; }
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
for tool in php mysql systemctl; do command -v "$tool" >/dev/null; done
for file in html/script/unitLinkCommon.php html/script/nodePublicMetadata.php html/script/gatewayAppLinkCommon.php html/script/unitGatewayLink.php misc/gateway_app_poll.php; do php -l "$repo_dir/$file"; done
config=/etc/tarasec/unit-link.php
[[ -f $config && -f /var/www/html/dbfunc.php ]] || { echo 'Install the existing hosted account/linking configuration first.' >&2; exit 1; }
php -r 'require $argv[2]; $c=require $argv[3]; $c["mode"]="hosted_gateway"; $c["transport"]="account_service"; $c["base_url"]=rtrim($argv[1],"/"); unitLinkValidateConfig($c);' "$1" "$repo_dir/html/script/unitLinkCommon.php" "$config"
backup=$(mktemp -d /var/backups/tarasec-app-account.XXXXXX)
cp -a "$config" "$backup/unit-link.php"
cp -a /var/www/html/script "$backup/script"
[[ ! -f /usr/local/lib/tarasec/unit_link_transport_guard.sh ]] || cp -a /usr/local/lib/tarasec/unit_link_transport_guard.sh "$backup/"
mysql taransvar < "$repo_dir/misc/gateway_app_link.sql"
install -d -m 0755 /usr/local/share/tarasec/gateway-app/html/script /usr/local/share/tarasec/gateway-app/misc
for name in unitLinkCommon unitLinkRequestCommon gatewayAppLinkCommon serviceDiscoveryCommon nodePublicMetadata; do
    install -m 0644 "$repo_dir/html/script/$name.php" /var/www/html/script/
    install -m 0644 "$repo_dir/html/script/$name.php" /usr/local/share/tarasec/gateway-app/html/script/
done
install -m 0644 "$repo_dir/html/script/unitGatewayLink.php" /var/www/html/script/
install -m 0640 -o root -g root /var/www/html/dbfunc.php /usr/local/share/tarasec/gateway-app/html/dbfunc.php
install -m 0644 "$repo_dir/misc/gateway_app_poll.php" /usr/local/share/tarasec/gateway-app/misc/
php -r 'require $argv[3]; $c=require $argv[2]; $c["mode"]="hosted_gateway"; $c["transport"]="account_service"; $c["base_url"]=rtrim($argv[1],"/"); unitLinkValidateConfig($c); file_put_contents($argv[2],"<?php\nreturn ".var_export($c,true).";\n");' "$1" "$config" "$repo_dir/html/script/unitLinkCommon.php"
chown root:www-data "$config"; chmod 0640 "$config"
install -d -m 0755 -o root -g root /var/lib/tarasec-node
php -r 'require $argv[1]; nodePublicWrite(unitLinkConfig());' "$repo_dir/html/script/nodePublicMetadata.php"
cat > /etc/systemd/system/tarasec-gateway-app.service <<'UNIT'
[Unit]
Description=Collect account-service app approvals with local administrator authorization
After=network-online.target mariadb.service
[Service]
Type=oneshot
ExecStart=/usr/bin/php /usr/local/share/tarasec/gateway-app/misc/gateway_app_poll.php
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/var/lib/tarasec-node
ProtectHome=true
PrivateTmp=true
UNIT
cat > /etc/systemd/system/tarasec-gateway-app.timer <<'UNIT'
[Unit]
Description=Refresh node account-service connection
[Timer]
OnBootSec=10s
OnUnitInactiveSec=10s
[Install]
WantedBy=timers.target
UNIT
systemctl daemon-reload
# Verify HTTPS registration before removing the obsolete HTTP transport restriction.
systemctl start tarasec-gateway-app.service
systemctl enable --now tarasec-gateway-app.timer
if systemctl cat tarasec-unit-link-poll.timer >/dev/null 2>&1; then
    systemctl disable --now tarasec-unit-link-poll.timer
fi
if systemctl cat tarasec-unit-link-transport.timer >/dev/null 2>&1; then
    systemctl disable --now tarasec-unit-link-transport.timer
    systemctl stop tarasec-unit-link-transport.service
fi
# Remove only the old rule at its previous configured destination/interface/port.
readarray -t old < <(php -r '$c=require $argv[1]; $u=parse_url($c["base_url"]); foreach([$u["host"],$u["port"]??80,$c["netbird_interface"]??""] as $v) echo $v,"\n";' "$backup/unit-link.php")
if [[ -n ${old[2]} ]]; then
    command -v iptables >/dev/null
    while iptables -C INPUT -d "${old[0]}" -p tcp --dport "${old[1]}" ! -i "${old[2]}" -j REJECT 2>/dev/null; do
        iptables -D INPUT -d "${old[0]}" -p tcp --dport "${old[1]}" ! -i "${old[2]}" -j REJECT
    done
fi
rm -f /run/tarasec-unit-link/transport
printf 'Installed network-independent app linking. Backup: %s\n' "$backup"
echo 'Requests and administrator sign-in now use the selected HTTPS account service. Legacy HTTP credential endpoints are disabled in this mode.'
