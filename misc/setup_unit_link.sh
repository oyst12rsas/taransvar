#!/usr/bin/env bash
set -euo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run this on the gateway with sudo.' >&2; exit 1; }
[[ $# -eq 2 ]] || { echo 'Usage: sudo bash misc/setup_unit_link.sh https://gateway.example.org GOOGLE_WEB_CLIENT_ID' >&2; exit 2; }
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
for tool in php mysql composer; do command -v "$tool" >/dev/null || { echo "Install $tool on the gateway first." >&2; exit 1; }; done
php -r '$u=parse_url($argv[1]); if(!$u || ($u["scheme"]??"")!=="https" || empty($u["host"]) || isset($u["user"]) || isset($u["pass"]) || isset($u["query"]) || isset($u["fragment"]) || !in_array($u["path"]??"",["","/"],true) || !preg_match("/^[A-Za-z0-9._-]+\\.apps\\.googleusercontent\\.com$/D",$argv[2])) exit(1);' "$1" "$2" || { echo 'Invalid HTTPS origin or Google web client ID.' >&2; exit 2; }
install -d -m 0755 /opt/tarasec-google
COMPOSER_ALLOW_SUPERUSER=1 composer --working-dir=/opt/tarasec-google require --no-interaction --no-dev 'google/apiclient:^2.18'
mysql taransvar < "$repo_dir/misc/unit_app_token.sql"
mysql taransvar < "$repo_dir/misc/unit_google_link.sql"
install -d -m 0750 -o root -g www-data /etc/tarasec
config_path=/etc/tarasec/unit-link.php
if [[ -f "$config_path" ]]; then
  echo 'Keeping existing unit-link.php, including stable gateway ID and private subject key.'
else
  umask 0027
  php -r '$c=["gateway_id"=>bin2hex(random_bytes(16)),"subject_key"=>bin2hex(random_bytes(32)),"base_url"=>rtrim($argv[1],"/"),"google_client_id"=>$argv[2],"google_autoload"=>"/opt/tarasec-google/vendor/autoload.php"]; file_put_contents($argv[3],"<?php\nreturn ".var_export($c,true).";\n");' "$1" "$2" "$config_path"
  chown root:www-data "$config_path"; chmod 0640 "$config_path"
fi
bash "$repo_dir/misc/deploy_web.sh"
echo 'Configure HTTPS on this gateway and register its origin and /script/unitLink.php login URI in Google before linking.'
echo 'Then open /script/unitLink.php from each unit on this gateway LAN. See docs/unit-app-pairing.md.'
