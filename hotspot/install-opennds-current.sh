#!/usr/bin/env bash
set -euo pipefail

# Install a current openNDS release on Debian/Raspberry Pi OS when the distro
# package is too old for the host firewall stack.
# Defaults to the current validated TaraSec target release.
VERSION="${OPENNDS_VERSION:-11.0.0}"
TAG="v${VERSION}"
STATE=/etc/tarasec/hotspot.conf
WORK=/usr/local/src/tarasec-opennds-${VERSION}
BACKUP=/root/tarasec-opennds-backup-$(date +%Y%m%d-%H%M%S)

log(){ echo "[TaraSec openNDS] $*"; }
die(){ echo "[TaraSec openNDS ERROR] $*" >&2; exit 1; }
state_value(){ [[ -r "$STATE" ]] && sed -n "s/^$1=//p" "$STATE" | tail -1; }

[[ ${EUID:-$(id -u)} -eq 0 ]] || exec sudo -E bash "$0" "$@"
command -v apt-get >/dev/null || die "Debian/Ubuntu/Raspberry Pi OS required"

HOTSPOT_IF="${TARASEC_HOTSPOT_IF:-$(state_value HOTSPOT_IF)}"
[[ -n "$HOTSPOT_IF" ]] || die "Hotspot interface unknown; run hotspot/install.sh first or set TARASEC_HOTSPOT_IF"
ip link show "$HOTSPOT_IF" >/dev/null 2>&1 || die "Hotspot interface '$HOTSPOT_IF' does not exist"

mkdir -p "$BACKUP" /usr/local/src
[[ -d /etc/opennds ]] && cp -a /etc/opennds "$BACKUP/" || true
[[ -f /etc/config/opennds ]] && { mkdir -p "$BACKUP/etc-config"; cp -a /etc/config/opennds "$BACKUP/etc-config/"; } || true

systemctl disable --now opennds 2>/dev/null || true

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y build-essential git libmicrohttpd-dev nftables ca-certificates curl

# Remove Debian's 9.x binaries/service while preserving our backup. The source
# install below provides /usr/bin/opennds, /usr/bin/ndsctl and systemd service.
apt-get remove -y opennds opennds-daemon opennds-daemon-common 2>/dev/null || true

rm -rf "$WORK"
git clone --depth 1 --branch "$TAG" https://github.com/openNDS/openNDS.git "$WORK"
make -C "$WORK" -j"$(nproc)"
make -C "$WORK" install

# Configure the native UCI-style generic-Linux config installed by openNDS 10.1+
# without depending on the uci command being present.
CFG=/etc/config/opennds
[[ -f "$CFG" ]] || die "openNDS installation did not create $CFG"

if grep -qE "^[[:space:]]*option[[:space:]]+gatewayinterface" "$CFG"; then
    sed -i -E "s|^[[:space:]]*option[[:space:]]+gatewayinterface.*|\toption gatewayinterface '$HOTSPOT_IF'|" "$CFG"
else
    # Insert in the first config block.
    sed -i "0,/^[[:space:]]*config[[:space:]]/s//&\n\toption gatewayinterface '$HOTSPOT_IF'/" "$CFG"
fi

systemctl daemon-reload
systemctl enable opennds
systemctl restart opennds
sleep 2

if ! systemctl is-active --quiet opennds; then
    journalctl -u opennds -n 80 --no-pager >&2 || true
    die "openNDS failed to start"
fi

INSTALLED="$(opennds -v 2>/dev/null | head -1 || true)"
log "Installed: ${INSTALLED:-openNDS $VERSION}"
log "Gateway interface: $HOTSPOT_IF"
log "Backup: $BACKUP"
log "Connect a client and open an HTTP page to trigger captive portal detection."
