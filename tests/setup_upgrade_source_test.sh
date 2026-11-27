#!/usr/bin/env bash
# Run as root: verifies the launcher moves off a user-owned checkout and stays idempotent.
set -euo pipefail
repo="$(cd "$(dirname "$0")/.." && pwd)"
[[ $EUID -eq 0 ]] || { echo 'SKIP: needs root'; exit 0; }
work=$(mktemp -d /root/tarasec-source-test.XXXXXX); trap 'rm -rf "$work"' EXIT
chmod 0755 "$work"
user_checkout="$work/user"; git clone -q "$repo" "$user_checkout"
git -C "$user_checkout" remote set-url origin https://github.com/oyst12rsas/taransvar.git
chown -R 1000 "$user_checkout"; chmod 0775 "$user_checkout"
ln -s "$user_checkout/tarasec" "$work/launcher"
export TARASEC_UPGRADE_SOURCE="$work/src" TARASEC_LAUNCHER="$work/launcher"
bash "$repo/misc/setup_upgrade_source.sh" "$user_checkout" >/dev/null
[[ $(readlink "$work/launcher") == "$work/src/tarasec" ]] || { echo 'Launcher not moved'; exit 1; }
[[ $(stat -c '%U' "$work/src") == root ]] || { echo 'Clone not root-owned'; exit 1; }
out=$(bash "$repo/misc/setup_upgrade_source.sh" "$user_checkout"); [[ -z $out ]] || { echo "Not idempotent: $out"; exit 1; }
git -C "$user_checkout" remote set-url origin "$repo"
rm -rf "$work/src"; ln -sfn "$user_checkout/tarasec" "$work/launcher"
if bash "$repo/misc/setup_upgrade_source.sh" "$user_checkout" 2>/dev/null; then echo 'Non-HTTPS origin accepted'; exit 1; fi
[[ $(readlink "$work/launcher") == "$user_checkout/tarasec" ]] || { echo 'Launcher changed on failure'; exit 1; }
echo 'Upgrade source setup checks passed'
