#!/bin/bash
set -euo pipefail
if [[ $EUID -ne 0 ]]; then echo 'Run with sudo on the DB server or any participating node.' >&2; exit 1; fi
if [[ -f /etc/tarasec-deployment-mode ]] && [[ $(cat /etc/tarasec-deployment-mode) != demo ]]; then
    echo 'Demonstrations are disabled by this node’s deployment policy.' >&2; exit 1
fi
# Explicit old arguments remain accepted for compatibility; setup determines roles.
case "${1:-}" in ''|--db|--gateway|--receiver) ;; *) echo 'Usage: sudo bash misc/install_web_poke_demo.sh' >&2; exit 1 ;; esac
root="$(cd "$(dirname "$0")/.." && pwd)"
web=/var/www/html
for dependency in deploymentMode.php taraLib.php dbfunc.php; do
    if [[ ! -f "$web/$dependency" ]]; then echo "Missing existing TaraSec dependency: $web/$dependency" >&2; exit 1; fi
done
php -r 'exit(extension_loaded("mysqli") && extension_loaded("curl") && extension_loaded("sodium") ? 0 : 1);' || {
    echo 'Install the PHP mysqli, curl and sodium extensions for both CLI and Apache.' >&2; exit 1;
}
for source in "$root"/html/script/webPoke*.php "$root/html/demo/poke/index.php" "$root/html/taraLib.php" "$root/html/script/appLocalInfection.php" "$root/html/script/appInfectionControl.php" "$root"/misc/*web_poke.php; do php -l "$source" >/dev/null; done
detected="$(php "$root/misc/detect_web_poke.php" "$web")"
is_db="$(php -r '$c=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $c["is_db"] ? "yes" : "no";' "$detected")"
db_ip="$(php -r '$c=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $c["db_ip"];' "$detected")"
node_id="$(php -r '$c=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR); echo $c["node_id"];' "$detected")"
echo "Detected: DB coordinator=$is_db; DB=$db_ip; receiver=${node_id:-not in DB participant list}; local-subnet gateway support=enabled"
# Bootstrap callback is deployed before the credential exchange. No credentials
# are returned to the browser; the DB encrypts them to a root-generated local key.
install -d -m 0755 "$web/script"
install -m 0644 "$root/html/script/webPokeLib.php" "$root/html/script/webPokeEnrollment.php" "$web/script/"
if [[ "$is_db" == yes ]]; then
    php "$root/misc/setup_web_poke.php" db "$db_ip"
    chown root:www-data /etc/tarasec-web-poke-db.conf
    install -o root -g www-data -m 0640 "/etc/tarasec-web-poke-enrollment/$node_id.conf" /etc/tarasec-web-poke-node.conf
elif [[ -n "$node_id" ]]; then
    # Reinstall uses a valid matching existing credential without unnecessary enrollment.
    if ! php -r '$c=is_readable($argv[1])?parse_ini_file($argv[1],false,INI_SCANNER_RAW):[]; require $argv[4]; exit(($c["NODE_ID"]??"")===$argv[2]&&($c["DB_IP"]??"")===$argv[3]&&webPokeToken($c["AUTH_KEY"]??"")?0:1);' /etc/tarasec-web-poke-node.conf "$node_id" "$db_ip" "$root/html/script/webPokeLib.php"; then
        php "$root/misc/enroll_web_poke.php" "$db_ip" "$node_id"
    fi
fi
# Every node can serve its own local subnet as gateway, including receiver nodes.
php "$root/misc/setup_web_poke.php" gateway "$db_ip"
chown root:www-data /etc/tarasec-web-poke-gateway.conf
install -d -m 0755 "$web/demo/poke" "$web/gatekeeper/func"
install -m 0644 "$root"/html/script/webPoke*.php "$web/script/"
install -m 0644 "$root"/html/demo/poke/* "$web/demo/poke/"
install -m 0644 "$root/html/demo/index.php" "$web/demo/index.php"
install -m 0644 "$root/html/gatekeeper/func/demo.php" "$root/html/gatekeeper/func/appDemos.php" "$web/gatekeeper/func/"
install -m 0644 "$root/html/taraLib.php" "$web/taraLib.php"
install -m 0644 "$root/html/script/appLocalInfection.php" "$root/html/script/appInfectionControl.php" "$web/script/"
echo 'Installed: local subnet clients use gateway support; authorized remote gateway traffic uses receiver support.'
echo 'Open http://<your-gateway-IP>/demo/poke/. Run this same command on the DB server first, then the other nodes.'
