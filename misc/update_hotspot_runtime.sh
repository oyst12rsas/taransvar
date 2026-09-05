#!/bin/bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Run with sudo: sudo bash misc/update_hotspot_runtime.sh" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if [ ! -d "$REPO_ROOT/html/hotspot" ]; then
    echo "ERROR: $REPO_ROOT/html/hotspot is missing." >&2
    exit 1
fi

if ! command -v mysql >/dev/null 2>&1; then
    echo "ERROR: mysql client is not installed." >&2
    exit 1
fi

echo "=== Updating TaraSec hotspot runtime ==="

echo "Deploying hotspot web/API files..."
mkdir -p /var/www/html/hotspot
cp -a "$REPO_ROOT/html/hotspot/." /var/www/html/hotspot/
chown -R root:root /var/www/html/hotspot
find /var/www/html/hotspot -type d -exec chmod 0755 {} +
find /var/www/html/hotspot -type f -exec chmod 0644 {} +

for migration in \
    "$REPO_ROOT/db/migrate_hotspot_pricing.sql" \
    "$REPO_ROOT/db/migrate_hotspot_earnings.sql"
do
    if [ -s "$migration" ]; then
        echo "Applying $(basename "$migration")..."
        mysql taransvar < "$migration"
    fi
done

if [ -f "$REPO_ROOT/hotspot/opennds/tarasec-global-bind" ]; then
    echo "Updating global bind helper..."
    install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-global-bind" /usr/local/sbin/tarasec-global-bind
fi

if [ -f "$REPO_ROOT/hotspot/opennds/tarasec-hotspot-accounting" ]; then
    echo "Updating hotspot accounting helper..."
    install -m 0755 "$REPO_ROOT/hotspot/opennds/tarasec-hotspot-accounting" /usr/local/sbin/tarasec-hotspot-accounting
fi

update_identity_walled_garden() {
    if ! command -v ndsctl >/dev/null 2>&1; then
        echo "openNDS not installed; skipping pre-login identity access."
        return
    fi

    local hotspot_if=""
    if [ -r /etc/config/opennds ]; then
        hotspot_if="$(sed -n "s/^[[:space:]]*option[[:space:]]\+gatewayinterface[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/p" /etc/config/opennds | head -1)"
    fi
    if [ -z "$hotspot_if" ] && [ -r /etc/opennds/opennds.conf ]; then
        hotspot_if="$(awk '$1 == "GatewayInterface" {print $2; exit}' /etc/opennds/opennds.conf)"
    fi
    if [ -z "$hotspot_if" ]; then
        echo "Unable to determine openNDS hotspot interface; skipping pre-login identity access." >&2
        return
    fi

    echo "Configuring pre-login TaraSec/Google identity access on $hotspot_if..."

    # Keep this list intentionally narrow. It is only enough to load TaraSec's
    # identity service and complete Google OAuth while the client is still
    # captive. Normal Internet access remains blocked until hotspot login.
    local fqdn_list="tarasec.org accounts.google.com oauth2.googleapis.com www.googleapis.com ssl.gstatic.com www.gstatic.com accounts.googleusercontent.com apis.google.com"

    # NetworkManager shared mode starts its own dnsmasq. openNDS needs DNS
    # answers for the allowed FQDNs placed into its walledgarden nft set.
    local dnsmasq_dir=/etc/NetworkManager/dnsmasq-shared.d
    local dnsmasq_file="$dnsmasq_dir/tarasec-walledgarden.conf"
    local dnsmasq_tmp
    local dnsmasq_changed=0
    mkdir -p "$dnsmasq_dir"
    dnsmasq_tmp="$(mktemp)"
    cat > "$dnsmasq_tmp" <<'EOF'
nftset=/tarasec.org/accounts.google.com/oauth2.googleapis.com/www.googleapis.com/ssl.gstatic.com/www.gstatic.com/accounts.googleusercontent.com/apis.google.com/4#ip#nds_filter#walledgarden
EOF
    if [ ! -f "$dnsmasq_file" ] || ! cmp -s "$dnsmasq_tmp" "$dnsmasq_file"; then
        install -m 0644 "$dnsmasq_tmp" "$dnsmasq_file"
        dnsmasq_changed=1
    fi
    rm -f "$dnsmasq_tmp"

    if [ -r /etc/config/opennds ]; then
        local uci_tmp
        uci_tmp="$(mktemp)"
        grep -vE '^[[:space:]]*list[[:space:]]+walledgarden_(fqdn|port)_list' /etc/config/opennds > "$uci_tmp"
        cat >> "$uci_tmp" <<EOF
    # TaraSec identity bootstrap only; normal Internet remains captive.
    list walledgarden_fqdn_list '$fqdn_list'
    list walledgarden_port_list '443'
EOF
        install -m 0644 "$uci_tmp" /etc/config/opennds
        rm -f "$uci_tmp"
    fi

    if [ -r /etc/opennds/opennds.conf ]; then
        local nds_tmp
        nds_tmp="$(mktemp)"
        grep -vE '^[[:space:]]*walledgarden_(fqdn|port)_list[[:space:]]' /etc/opennds/opennds.conf > "$nds_tmp"
        cat >> "$nds_tmp" <<EOF
# TaraSec identity bootstrap only; normal Internet remains captive.
walledgarden_fqdn_list $fqdn_list
walledgarden_port_list 443
EOF
        install -m 0644 "$nds_tmp" /etc/opennds/opennds.conf
        rm -f "$nds_tmp"
    fi

    # dnsmasq reads nftset directives only at startup. Cycling the NetworkManager
    # hotspot also causes openNDS/systemd to stop and start. Do not immediately
    # issue a second restart or it can race the first openNDS shutdown/startup.
    if [ "$dnsmasq_changed" -eq 1 ] && command -v nmcli >/dev/null 2>&1; then
        local connection=""
        connection="$(nmcli -g GENERAL.CONNECTION device show "$hotspot_if" 2>/dev/null | head -1 || true)"
        if [ -n "$connection" ] && [ "$connection" != "--" ]; then
            echo "Restarting hotspot DNS for identity bootstrap..."
            nmcli connection down "$connection" >/dev/null
            nmcli connection up "$connection" >/dev/null
            for _ in $(seq 1 30); do
                if ip link show "$hotspot_if" 2>/dev/null | grep -q 'state UP' && \
                   systemctl is-active --quiet opennds; then
                    break
                fi
                sleep 1
            done
            if ! systemctl is-active --quiet opennds; then
                echo "openNDS has not returned yet; asking systemd to start it once..."
                systemctl start opennds
            fi
        else
            echo "WARNING: could not identify active NetworkManager hotspot connection; DNS mapping will take effect on its next restart." >&2
            systemctl restart opennds
        fi
    else
        # No hotspot/DNS cycle occurred, so reload openNDS explicitly to pick up
        # changed walled-garden directives.
        if systemctl is-active --quiet opennds; then
            systemctl restart opennds
        else
            systemctl start opennds
        fi
    fi

    if ! systemctl is-active --quiet opennds; then
        echo "ERROR: openNDS did not become active after identity-bootstrap update." >&2
        systemctl status opennds --no-pager -l >&2 || true
        return 1
    fi

    echo "Pre-login identity access configured."
}

update_identity_walled_garden

if systemctl is-active --quiet apache2; then
    systemctl reload apache2
fi

echo "Verifying pricing endpoint file..."
test -r /var/www/html/hotspot/tarasec_hotspot_info.php

echo "TaraSec hotspot runtime update complete."
