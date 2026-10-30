#!/bin/bash
set -euo pipefail
if [[ $EUID -ne 0 ]]; then echo 'Run with sudo on the DB server, a participating receiver or gateway.' >&2; exit 1; fi
role="${1:---gateway}"
if [[ -f /etc/tarasec-deployment-mode ]] && [[ $(cat /etc/tarasec-deployment-mode) != demo ]]; then
    echo 'Demonstrations are disabled by this node’s deployment policy.' >&2; exit 1
fi
root="$(cd "$(dirname "$0")/.." && pwd)"
web=/var/www/html
db_ip="${WEB_POKE_DB_IP:-100.68.126.0}"
case "$role" in --db|--gateway|--receiver) ;; *) echo 'Usage: install_web_poke_demo.sh --db | --gateway | --receiver /secure/path/node.conf' >&2; exit 1 ;; esac
for dependency in deploymentMode.php taraLib.php dbfunc.php; do
    if [[ ! -f "$web/$dependency" ]]; then echo "Missing existing TaraSec dependency: $web/$dependency" >&2; exit 1; fi
done
php -r 'exit(extension_loaded("mysqli") && extension_loaded("curl") ? 0 : 1);' || {
    echo 'Install the PHP mysqli and curl extensions for both CLI and Apache.' >&2; exit 1;
}
for source in "$root"/html/script/webPoke*.php "$root/html/demo/poke/index.php" "$root/html/taraLib.php" "$root/html/script/appLocalInfection.php" "$root/html/script/appInfectionControl.php"; do php -l "$source" >/dev/null; done
# Make sure a receiver key exists before deploying the policy-enforced endpoints.
if [[ "$role" == --receiver ]]; then
    enrollment="${2:?Supply the receiver enrollment file generated on the DB server}"
    php -r '$c=parse_ini_file($argv[1],false,INI_SCANNER_RAW); require $argv[2]; if(!is_array($c)||!webPokeToken($c["AUTH_KEY"]??"")||!webPokeIp($c["DB_IP"]??"")||!isset(webPokeNodes()[$c["NODE_ID"]??""])) exit(1);' "$enrollment" "$root/html/script/webPokeLib.php"
    install -o root -g www-data -m 0640 "$enrollment" /etc/tarasec-web-poke-node.conf
elif [[ "$role" == --db ]]; then
    php "$root/misc/setup_web_poke.php" db "$db_ip"
    chown root:www-data /etc/tarasec-web-poke-db.conf
    install -o root -g www-data -m 0640 /etc/tarasec-web-poke-enrollment/dbserver1.conf /etc/tarasec-web-poke-node.conf
else
    php "$root/misc/setup_web_poke.php" gateway "$db_ip"
    chown root:www-data /etc/tarasec-web-poke-gateway.conf
fi
install -d -m 0755 "$web/demo/poke" "$web/script" "$web/gatekeeper/func"
install -m 0644 "$root"/html/script/webPoke*.php "$web/script/"
install -m 0644 "$root"/html/demo/poke/* "$web/demo/poke/"
install -m 0644 "$root/html/demo/index.php" "$web/demo/index.php"
install -m 0644 "$root/html/gatekeeper/func/demo.php" "$root/html/gatekeeper/func/appDemos.php" "$web/gatekeeper/func/"
# Carry the policy checks to existing infection APIs, preserving deployed DB credentials.
install -m 0644 "$root/html/taraLib.php" "$web/taraLib.php"
install -m 0644 "$root/html/script/appLocalInfection.php" "$root/html/script/appInfectionControl.php" "$web/script/"
echo 'Installed DB-authorized Poke demo. On the phone, open http://<your-gateway-IP>/demo/poke/.'
echo 'Receiver tags require the gateway to be a registered partner, or explicit DEMO_NODE=1.'
echo 'No firewall, routing, infection records or production policy were changed.'
