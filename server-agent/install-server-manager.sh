#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this installer as root." >&2
    exit 1
fi
SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
getent group tarasec-manager >/dev/null || groupadd --system tarasec-manager
id tarasec-manager >/dev/null 2>&1 || useradd --system --gid tarasec-manager --groups systemd-journal --home-dir /nonexistent --shell /usr/sbin/nologin tarasec-manager
install -d -m 0755 /usr/local/lib/tarasec
install -m 0755 "$SOURCE_DIR/tarasec-server-manager.py" /usr/local/lib/tarasec/
[ -e /etc/tarasec-server-manager.conf ] || install -m 0644 "$SOURCE_DIR/tarasec-server-manager.conf.example" /etc/tarasec-server-manager.conf
[ -e /etc/tarasec-server-manager.env ] || install -o root -g tarasec-manager -m 0640 "$SOURCE_DIR/tarasec-server-manager.env.example" /etc/tarasec-server-manager.env
install -m 0644 "$SOURCE_DIR/tarasec-server-manager.service" "$SOURCE_DIR/tarasec-server-manager.timer" /etc/systemd/system/
systemctl daemon-reload
echo "Installed but not enabled. Configure the allowlist and API key, test snapshot, then start the service."
