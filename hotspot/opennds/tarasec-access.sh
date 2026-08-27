#!/bin/bash
# TaraSec openNDS access hook.
# Called by ThemeSpec with client MAC/IP. It asks the central access API and
# returns openNDS auth/deauth rather than granting access unconditionally.
set -u

CONFIG="${TARASEC_ACCESS_CONFIG:-/etc/tarasec/access.env}"
[ -r "$CONFIG" ] || { echo "deauth"; exit 0; }
# shellcheck disable=SC1090
. "$CONFIG"

CLIENT_MAC="${1:-}"
CLIENT_IP="${2:-}"
[ -n "$CLIENT_MAC" ] || { echo "deauth"; exit 0; }

json=$(curl -fsS --connect-timeout 3 --max-time 7 \
  -H "X-TaraSec-Gateway-Key: ${TARASEC_GATEWAY_KEY:-}" \
  -H "X-TaraSec-Token: ${TARASEC_GATEWAY_TOKEN:-}" \
  --data-urlencode "device_key=$CLIENT_MAC" \
  --data-urlencode "client_ip=$CLIENT_IP" \
  "${TARASEC_ACCESS_URL:-https://tarasec.org/hotspot/opennds/access-api.php}" 2>/dev/null) || {
    logger -t tarasec-access "access lookup failed for $CLIENT_MAC/$CLIENT_IP"
    echo "deauth"
    exit 0
}

if printf '%s' "$json" | grep -Eq '"allow"[[:space:]]*:[[:space:]]*true'; then
  logger -t tarasec-access "authorized $CLIENT_MAC/$CLIENT_IP"
  echo "auth"
else
  logger -t tarasec-access "denied $CLIENT_MAC/$CLIENT_IP"
  echo "deauth"
fi
