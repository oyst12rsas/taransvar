#!/usr/bin/env bash
set -euo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run this on the gateway with sudo.' >&2; exit 1; }
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
[[ $# -le 1 ]] || { echo 'Usage: sudo bash misc/setup_unit_qr.sh [HTTPS_ORIGIN]' >&2; exit 2; }
config_path=/etc/tarasec/unit-link.php
if [[ ! -f "$config_path" ]]; then
  [[ $# -eq 1 ]] || { echo 'Supply the gateway HTTPS origin for first-time setup.' >&2; exit 2; }
  php -r '$u=parse_url($argv[1]); if(!$u || ($u["scheme"]??"")!=="https" || empty($u["host"]) || isset($u["user"]) || isset($u["pass"]) || isset($u["query"]) || isset($u["fragment"]) || !in_array($u["path"]??"",["","/"],true)) exit(1);' "$1" || { echo 'Invalid HTTPS origin.' >&2; exit 2; }
  install -d -m 0750 -o root -g www-data /etc/tarasec
  umask 0027
  php -r '$c=["gateway_id"=>bin2hex(random_bytes(16)),"subject_key"=>bin2hex(random_bytes(32)),"base_url"=>rtrim($argv[1],"/")];file_put_contents($argv[2],"<?php\nreturn ".var_export($c,true).";\n");' "$1" "$config_path"
  chown root:www-data "$config_path"; chmod 0640 "$config_path"
else
  echo 'Keeping existing gateway identity and linking configuration.'
fi
php -r 'require $argv[1]; unitGatewayConfig();' "$repo_dir/html/script/unitLinkCommon.php" || {
  echo 'Unit linking configuration is invalid. A working HTTPS origin is required.' >&2; exit 1;
}
command -v qrencode >/dev/null || apt-get install -y qrencode
for schema in unit_app_token unit_google_link unit_pair_code; do
  mysql taransvar < "$repo_dir/misc/$schema.sql"
done
bash "$repo_dir/misc/deploy_web.sh"
echo 'Open the gateway home page from the laptop on its LAN and choose Link to my app.'
