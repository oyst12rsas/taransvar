#!/bin/bash
# Drop admin SSH from anyone outside SSH_ALLOWED_SOURCES; allowed sources RETURN so
# later layers (Google/TOTP gate, NetBird ACL) still decide. Idempotent; IPv4+IPv6.
set -euo pipefail
CONF=/etc/tarasecfw.conf
SSH_PORT=22; SSH_ALLOWED_SOURCES=""
# shellcheck disable=SC1090
[ -r "$CONF" ] && source "$CONF"
[ -n "$SSH_ALLOWED_SOURCES" ] || { echo "SSH_ALLOWED_SOURCES empty: leaving rules unchanged" >&2; exit 0; }
for T in iptables ip6tables; do
  $T -N TARASEC-SSH 2>/dev/null || true
  $T -F TARASEC-SSH
  $T -A TARASEC-SSH -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
  for src in ${SSH_ALLOWED_SOURCES//,/ }; do
    case "$src" in *:*) fam=ip6tables ;; *) fam=iptables ;; esac
    [ "$fam" = "$T" ] && $T -A TARASEC-SSH -s "$src" -j RETURN
  done
  $T -A TARASEC-SSH -j DROP
  # drop stale hooks for any port, then hook the current admin port first
  $T -S INPUT | grep -- '-j TARASEC-SSH' | sed 's/^-A /-D /' | while read -r r; do $T $r; done
  $T -I INPUT 1 -p tcp --dport "$SSH_PORT" -j TARASEC-SSH
done
