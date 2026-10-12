#!/usr/bin/env bash
# Install the Demo 3 isolation fix on the DB server and participating gateways.
set -euo pipefail
if (( EUID != 0 )); then
    echo 'Run as root: sudo bash misc/install_demo3_isolation.sh' >&2
    exit 1
fi
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
web_root="${1:-/var/www/html}"
test -f "$web_root/dbfunc.php" || { echo 'Expected an existing TaraSec web root' >&2; exit 1; }
for file in appDemoAssistance.php partnerRequest.php; do
    php -l "$repo_root/html/script/$file"
done
backup="$(mktemp -d /var/backups/tarasec-demo3-isolation.XXXXXX)"
for file in appDemoAssistance.php partnerRequest.php; do
    test -f "$web_root/script/$file" || { echo "Missing existing $file" >&2; exit 1; }
    cp -a "$web_root/script/$file" "$backup/$file"
done
for file in appDemoAssistance.php partnerRequest.php; do
    install -m 0644 "$repo_root/html/script/$file" "$web_root/script/$file.new"
    mv -f "$web_root/script/$file.new" "$web_root/script/$file"
done
echo "Demo 3 isolation installed. API backup: $backup"
echo 'No schema change, kernel rebuild or service restart is required.'
