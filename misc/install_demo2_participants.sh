#!/usr/bin/env bash
# Deploy the reviewed Demo 2 identity API on the DB and each participating gateway.
set -euo pipefail
if (( EUID != 0 )); then
    echo 'Run as root: sudo bash misc/install_demo2_participants.sh' >&2
    exit 1
fi
repo_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
web_root="${1:-/var/www/html}"
test -f "$web_root/dbfunc.php" || { echo 'Expected an existing TaraSec web root with dbfunc.php' >&2; exit 1; }
for file in demoSshIdentityLib.php appDemoSshIdentity.php appDemoSshSession.php; do
    php -l "$repo_root/html/script/$file"
done
backup="$(mktemp -d /var/backups/tarasec-demo2-participants.XXXXXX)"
for file in demoSshIdentityLib.php appDemoSshIdentity.php appDemoSshSession.php; do
    if [[ -f "$web_root/script/$file" ]]; then
        cp -a "$web_root/script/$file" "$backup/$file"
    else
        touch "$backup/$file.absent"
    fi
done
# Schema first. Retain the installed local migration timer's existing checkout.
perl "$repo_root/misc/upgrade_db.pl"
mkdir -p "$web_root/script"
for file in demoSshIdentityLib.php appDemoSshIdentity.php appDemoSshSession.php; do
    install -m 0644 "$repo_root/html/script/$file" "$web_root/script/$file.new"
    mv -f "$web_root/script/$file.new" "$web_root/script/$file"
done
echo "Demo 2 participant API installed. Previous API files: $backup"
echo 'Update the DB server and every participating gateway, then install the updated Android app.'
echo 'Acceptance: two devices on one gateway start distinct sessions; each SSH sequence changes only its own session.'
