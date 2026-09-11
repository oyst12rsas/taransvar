#!/bin/bash
set -euo pipefail

ROLE=""
START_NOW=1
while [ "$#" -gt 0 ]; do
    case "$1" in
        --role) ROLE="${2:-}"; shift 2 ;;
        --no-start) START_NOW=0; shift ;;
        *) echo "Usage: $0 --role gateway|dbserver [--no-start]" >&2; exit 2 ;;
    esac
done

case "$ROLE" in gateway|dbserver) ;; *)
    echo "Specify --role gateway or --role dbserver" >&2
    exit 2
esac

[ "$(id -u)" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
[ -x "$ROOT/taralink/taralink" ] || { echo "Build taralink first" >&2; exit 1; }
[ -f "$ROOT/tarakernel/tarakernel.ko" ] || { echo "Build tarakernel for $(uname -r) first" >&2; exit 1; }

ssh_port="$(sshd -T 2>/dev/null | awk '$1=="port"{print $2; exit}')"
[ -n "$ssh_port" ] || { echo "Unable to determine sshd port" >&2; exit 1; }
if ! awk -F= -v p="$ssh_port" '
    /^[[:space:]]*SSH_PORT[[:space:]]*=/ {
        v=$2; gsub(/[[:space:]"]/,"",v); if (v==p) ok=1
    }
    END { exit(ok?0:1) }
' /etc/tarasecfw.conf 2>/dev/null; then
    echo "Refusing to start: /etc/tarasecfw.conf SSH_PORT does not match sshd port $ssh_port" >&2
    exit 1
fi

install -d -m 0755 /usr/local/sbin /etc/systemd/system /etc/tarasec
install -o root -g root -m 0755 "$ROOT/taralink/taralink" /usr/local/sbin/taralink
install -d -m 0755 "/lib/modules/$(uname -r)/extra"
install -o root -g root -m 0644 "$ROOT/tarakernel/tarakernel.ko" "/lib/modules/$(uname -r)/extra/tarakernel.ko"
depmod -a
printf '%s\n' tarakernel > /etc/modules-load.d/tarakernel.conf
install -o root -g root -m 0644 "$ROOT/misc/systemd/taralink.service" /etc/systemd/system/taralink.service
printf '%s\n' "$ROLE" > /etc/tarasec/node-role
touch /etc/tarasec/taralink-managed-by-systemd
systemctl daemon-reload
systemctl enable taralink.service
if [ "$START_NOW" -eq 1 ]; then
    systemctl restart taralink.service
fi
