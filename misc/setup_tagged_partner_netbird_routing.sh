#!/usr/bin/env bash
set -euo pipefail

# Route TaraSec-tagged TCP connections to selected partner routers over NetBird.
#
# This deliberately does NOT make NetBird the normal WAN. It installs a small
# nftables NAT table that only rewrites packets whose TaraSec tag is present
# (tcp urgptr != 0) and whose destination matches an explicitly enabled
# partnerRouter row.
#
# The receiving partner sees the connection on its NetBird address and the
# original TCP port. Conntrack keeps the DNAT mapping for subsequent packets.
# Source NAT on the NetBird egress makes return traffic use the overlay too.
#
# Scope of this first implementation:
#   public partner-router IP -> that router's NetBird IP.
# It is suitable for services handled by the partner router itself (for example
# TaraSec/demo/honeypot services). Routing onward to an arbitrary client behind
# that partner requires a separate destination/port -> internal-unit mapping.
#
# Requirements:
#   - migration db/migrate_partnerrouter_netbird_routing.sql applied
#   - nft, mysql and ip commands available
#   - NetBird interface up (normally wt0)
#   - /root/.my.cnf or MYSQL_DEFAULTS_FILE providing DB credentials
#
# Usage:
#   sudo ./misc/setup_tagged_partner_netbird_routing.sh
#   sudo NETBIRD_IF=wt0 ./misc/setup_tagged_partner_netbird_routing.sh
#   sudo DRY_RUN=1 ./misc/setup_tagged_partner_netbird_routing.sh

DB_NAME="${DB_NAME:-taransvar}"
MYSQL_DEFAULTS_FILE="${MYSQL_DEFAULTS_FILE:-/root/.my.cnf}"
NETBIRD_IF="${NETBIRD_IF:-wt0}"
NFT_TABLE="${NFT_TABLE:-tarasec_partner_netbird}"
DRY_RUN="${DRY_RUN:-0}"

need() {
    command -v "$1" >/dev/null 2>&1 || {
        echo "ERROR: required command not found: $1" >&2
        exit 1
    }
}

need nft
need mysql
need ip

if ! ip link show "$NETBIRD_IF" >/dev/null 2>&1; then
    echo "ERROR: NetBird interface '$NETBIRD_IF' is not present." >&2
    exit 1
fi

MYSQL=(mysql --batch --skip-column-names)
if [[ -r "$MYSQL_DEFAULTS_FILE" ]]; then
    MYSQL=(mysql --defaults-extra-file="$MYSQL_DEFAULTS_FILE" --batch --skip-column-names)
fi

mapfile -t ROUTES < <(
    "${MYSQL[@]}" "$DB_NAME" -e \
      "SELECT INET_NTOA(ip), INET_NTOA(netbirdIp)
         FROM partnerRouter
        WHERE routeTaggedViaNetbird=b'1'
          AND netbirdIp IS NOT NULL
          AND netbirdIp<>0
        ORDER BY routerId"
)

emit_ruleset() {
    cat <<EOF
table ip $NFT_TABLE {
    chain prerouting {
        type nat hook prerouting priority dstnat; policy accept;
EOF

    local row public_ip netbird_ip
    for row in "${ROUTES[@]}"; do
        public_ip="${row%%$'\t'*}"
        netbird_ip="${row#*$'\t'}"
        [[ -n "$public_ip" && -n "$netbird_ip" ]] || continue

        # A TaraSec tag is carried in TCP urg_ptr. Only tagged flows to an
        # explicitly enabled partner are redirected to its NetBird address.
        printf '        ip daddr %s tcp urgptr != 0 counter dnat to %s\n' \
               "$public_ip" "$netbird_ip"
    done

    cat <<EOF
    }

    chain postrouting {
        type nat hook postrouting priority srcnat; policy accept;
EOF

    local row public_ip netbird_ip
    for row in "${ROUTES[@]}"; do
        public_ip="${row%%$'\t'*}"
        netbird_ip="${row#*$'\t'}"
        [[ -n "$netbird_ip" ]] || continue
        printf '        oifname "%s" ip daddr %s counter masquerade\n' \
               "$NETBIRD_IF" "$netbird_ip"
    done

    cat <<EOF
    }
}
EOF
}

if [[ "$DRY_RUN" == "1" ]]; then
    emit_ruleset
    exit 0
fi

# Replace the generated table atomically enough for this small rule set: remove
# the previous TaraSec-owned table, then load the complete replacement.
nft delete table ip "$NFT_TABLE" 2>/dev/null || true

if ((${#ROUTES[@]} == 0)); then
    echo "No partnerRouter rows have routeTaggedViaNetbird=1; no routing rules installed."
    exit 0
fi

RULESET="$(mktemp)"
trap 'rm -f "$RULESET"' EXIT
emit_ruleset > "$RULESET"
nft -f "$RULESET"

echo "Installed tagged TaraSec partner routing over $NETBIRD_IF:"
nft list table ip "$NFT_TABLE"
