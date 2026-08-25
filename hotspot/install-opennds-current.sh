#!/usr/bin/env bash
set -euo pipefail

# Install a current openNDS release on Debian/Raspberry Pi OS when the distro
# package is too old for the host firewall stack.
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
apt-get remove -y opennds opennds-daemon opennds-daemon-common 2>/dev/null || true

rm -rf "$WORK"
git clone --depth 1 --branch "$TAG" https://github.com/openNDS/openNDS.git "$WORK"
make -C "$WORK" -j"$(nproc)"
make -C "$WORK" install

# Generic Linux still uses /etc/opennds/opennds.conf in openNDS 11.x.
# Do NOT reuse Debian 9.10's config across the major-version jump; install the
# upstream 11.x template, then set only the TaraSec gateway interface.
mkdir -p /etc/opennds
[[ -f "$WORK/resources/opennds.conf" ]] || die "Upstream generic Linux config template missing"
cp -f "$WORK/resources/opennds.conf" /etc/opennds/opennds.conf
sed -i -E "s|^[#[:space:]]*GatewayInterface[[:space:]].*|GatewayInterface $HOTSPOT_IF|" /etc/opennds/opennds.conf

# Remove any stale UCI-format file left by previous experiments. Generic Linux
# does not use it in 11.x and stale content can confuse wrapper scripts.
rm -f /etc/config/opennds 2>/dev/null || true

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
log "Config: /etc/opennds/opennds.conf"
log "Backup: $BACKUP"
log "Connect a client and open an HTTP page to trigger captive portal detection."
