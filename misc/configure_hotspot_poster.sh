#!/usr/bin/env bash
set -euo pipefail
CONF=/etc/tarasec-poster.conf
[[ $EUID -eq 0 ]] || { echo "Run with sudo/root." >&2; exit 1; }

read -r -p 'Hotspot/site name: ' SITE_NAME
read -r -p 'Site address: ' SITE_ADDRESS
read -r -p 'Wi-Fi SSID: ' SSID
read -r -p 'Wi-Fi security [open/WPA] (default open): ' WIFI_SECURITY
WIFI_SECURITY="${WIFI_SECURITY:-open}"
WIFI_PASSWORD=""
if [[ "${WIFI_SECURITY^^}" == "WPA" ]]; then read -r -s -p 'Wi-Fi password: ' WIFI_PASSWORD; echo; fi
read -r -p 'Portal URL (for example http://192.168.50.1/): ' PORTAL_URL
read -r -p 'Manual payment heading [Pay manually]: ' PAYMENT_HEADING
PAYMENT_HEADING="${PAYMENT_HEADING:-Pay manually}"
read -r -p 'Manual payment instruction (for example: Send PHP 1000 to GCash 09... for 1 month access): ' PAYMENT_INSTRUCTIONS
read -r -p 'TaraSec automatic-payment service fee percent [10]: ' SERVICE_FEE
SERVICE_FEE="${SERVICE_FEE:-10}"
read -r -p 'TaraSec payment onboarding URL [https://tarasec.org/]: ' CONTACT_URL
CONTACT_URL="${CONTACT_URL:-https://tarasec.org/}"

umask 077
cat > "$CONF" <<EOF
SITE_NAME=$SITE_NAME
SITE_ADDRESS=$SITE_ADDRESS
SSID=$SSID
WIFI_SECURITY=$WIFI_SECURITY
WIFI_PASSWORD=$WIFI_PASSWORD
PORTAL_URL=$PORTAL_URL
PAYMENT_HEADING=$PAYMENT_HEADING
PAYMENT_INSTRUCTIONS=$PAYMENT_INSTRUCTIONS
TARASEC_PAYMENT_FEE_PERCENT=$SERVICE_FEE
TARASEC_PAYMENT_CONTACT_URL=$CONTACT_URL
EOF
chmod 600 "$CONF"
echo "Poster configured. Open /hotspot-poster.php and print it."
