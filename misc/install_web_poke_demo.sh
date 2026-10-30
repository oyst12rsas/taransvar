#!/bin/bash
set -euo pipefail
if [[ $EUID -ne 0 ]]; then echo 'Run with sudo on wt-gw1, Roquefort, Camembert, dbserver1 or Gouda.' >&2; exit 1; fi
if [[ -f /etc/tarasec-deployment-mode ]] && [[ $(cat /etc/tarasec-deployment-mode) != demo ]]; then
    echo 'Demonstrations are disabled by this node’s deployment policy.' >&2; exit 1
fi
root="$(cd "$(dirname "$0")/.." && pwd)"
web=/var/www/html
for dependency in deploymentMode.php taraLib.php dbfunc.php script/appInfectionControl.php script/appLocalInfection.php; do
    if [[ ! -f "$web/$dependency" ]]; then echo "Missing existing TaraSec dependency: $web/$dependency" >&2; exit 1; fi
done
php -r 'exit(extension_loaded("mysqli") && extension_loaded("curl") ? 0 : 1);' || {
    echo 'Install the PHP mysqli and curl extensions for both CLI and Apache.' >&2; exit 1;
}
for source in "$root"/html/script/webPoke*.php "$root/html/demo/poke/index.php"; do php -l "$source" >/dev/null; done
install -d -m 0755 "$web/demo/poke" "$web/script"
install -m 0644 "$root"/html/script/webPoke*.php "$web/script/"
install -m 0644 "$root"/html/demo/poke/* "$web/demo/poke/"
install -m 0644 "$root/html/demo/index.php" "$web/demo/index.php"
echo 'Installed browser Poke demo. Open http://100.68.165.190/demo/poke/ through Standard gateway.'
echo 'Receiver tags require Standard gateway to be a registered partner, or DEMO_NODE=1 in /etc/tarasecfw.conf.'
echo 'No firewall, routing, infection records or production policy were changed.'
