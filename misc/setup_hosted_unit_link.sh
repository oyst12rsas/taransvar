#!/usr/bin/env bash
set -euo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run on the gateway with sudo.' >&2; exit 1; }
[[ $# -ge 1 && $# -le 2 ]] || { echo 'Usage: sudo bash misc/setup_hosted_unit_link.sh GATEWAY_ORIGIN [NETBIRD_INTERFACE]' >&2; exit 2; }
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
origin=${1%/}; iface=${2:-}
for tool in php mysql systemctl; do command -v "$tool" >/dev/null; done
php -r 'require $argv[3]; $c=["mode"=>"service_handoff","gateway_id"=>str_repeat("a",32),"subject_key"=>str_repeat("a",64),"base_url"=>$argv[1],"transport"=>str_starts_with($argv[1],"https://")?"https":"netbird","netbird_interface"=>$argv[2]]; unitLinkValidateConfig($c);' "$origin" "$iface" "$repo_dir/html/script/unitLinkCommon.php"
php -r 'foreach(["curl","mysqli"] as $e) if(!extension_loaded($e)) {fwrite(STDERR,"Install PHP $e first.\n");exit(1);}'
if [[ $origin == http://* ]]; then
  for tool in wg ip iptables; do command -v "$tool" >/dev/null; done
  wg show "$iface" >/dev/null
fi
mysql taransvar < "$repo_dir/misc/unit_app_token.sql"
mysql taransvar < "$repo_dir/misc/unit_google_link.sql"
mysql taransvar < "$repo_dir/misc/unit_link_request.sql"
install -d -m 0750 -o root -g www-data /etc/tarasec
config=/etc/tarasec/unit-link.php
[[ ! -f $config ]] || cp -a "$config" "$config.before-hosted.$(date +%s)"
umask 0027
php -r 'require $argv[4]; $p=$argv[3]; $c=is_file($p)?require $p:[]; $c["gateway_id"]??=bin2hex(random_bytes(16)); $c["subject_key"]??=bin2hex(random_bytes(32)); $c["mode"]="service_handoff"; $c["base_url"]=$argv[1]; $c["transport"]=str_starts_with($argv[1],"https://")?"https":"netbird"; $c["netbird_interface"]=$argv[2]; unitLinkValidateConfig($c); file_put_contents($p,"<?php\nreturn ".var_export($c,true).";\n");' "$origin" "$iface" "$config" "$repo_dir/html/script/unitLinkCommon.php"
chown root:www-data "$config"; chmod 0640 "$config"
install -d -m 0755 /usr/local/lib/tarasec /usr/local/share/tarasec/unit-link/html/script
install -m 0644 "$repo_dir"/html/script/{unitLinkCommon,unitLinkRequestCommon,serviceDiscoveryCommon}.php /usr/local/share/tarasec/unit-link/html/script/
install -m 0640 -o root -g root "$repo_dir/html/dbfunc.php" /usr/local/share/tarasec/unit-link/html/
install -d /usr/local/share/tarasec/unit-link/misc
install -m 0644 "$repo_dir/misc/unit_link_poll.php" /usr/local/share/tarasec/unit-link/misc/
cat > /etc/systemd/system/tarasec-unit-link-poll.service <<'EOF'
[Unit]
Description=Collect approved TaraSec node links
After=network-online.target mariadb.service
[Service]
Type=oneshot
ExecStart=/usr/bin/php /usr/local/share/tarasec/unit-link/misc/unit_link_poll.php
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
EOF
cat > /etc/systemd/system/tarasec-unit-link-poll.timer <<'EOF'
[Unit]
Description=Check TaraSec node approvals
[Timer]
OnBootSec=20s
OnUnitInactiveSec=10s
[Install]
WantedBy=timers.target
EOF
if [[ $origin == http://* ]]; then
  install -m 0755 "$repo_dir/misc/unit_link_transport_guard.sh" /usr/local/lib/tarasec/
  cat > /etc/systemd/system/tarasec-unit-link-transport.service <<'EOF'
[Unit]
Description=Enforce encrypted NetBird HTTP linking boundary
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/local/lib/tarasec/unit_link_transport_guard.sh
EOF
  cat > /etc/systemd/system/tarasec-unit-link-transport.timer <<'EOF'
[Unit]
Description=Verify NetBird linking transport
[Timer]
OnBootSec=5s
OnUnitInactiveSec=15s
[Install]
WantedBy=timers.target
EOF
  systemctl daemon-reload
  systemctl start tarasec-unit-link-transport.service
  systemctl enable --now tarasec-unit-link-transport.timer
fi
bash "$repo_dir/misc/deploy_web.sh"
systemctl daemon-reload
systemctl enable --now tarasec-unit-link-poll.timer
systemctl start tarasec-unit-link-poll.service
echo 'Hosted node linking installed. Open /script/unitLink.php from the node, create an approval link, then copy it to your phone.'
