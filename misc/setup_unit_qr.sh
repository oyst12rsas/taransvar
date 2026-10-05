#!/usr/bin/env bash
set -euo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run this on the gateway with sudo.' >&2; exit 1; }
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
php -r 'require $argv[1]; unitLinkConfig();' "$repo_dir/html/script/unitLinkCommon.php" || {
  echo 'Configure unit linking with misc/setup_unit_link.sh first. A working HTTPS origin is required.' >&2; exit 1;
}
command -v qrencode >/dev/null || apt-get install -y qrencode
for schema in unit_app_token unit_google_link unit_pair_code; do
  mysql taransvar < "$repo_dir/misc/$schema.sql"
done
bash "$repo_dir/misc/deploy_web.sh"
echo 'Open the gateway home page from the laptop on its LAN and choose Link to my app.'
