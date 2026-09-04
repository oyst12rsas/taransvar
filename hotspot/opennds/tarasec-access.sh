#!/bin/bash
# TaraSec central hotspot helper.
# Default mode is the openNDS access decision used by ThemeSpec/access refresh.
# --json returns the complete central access response for local accounting.
# --account posts reset-safe byte deltas to the central accounting endpoint.
set -u

CONFIG="${TARASEC_ACCESS_CONFIG:-/etc/tarasec/access.env}"
[ -r "$CONFIG" ] || { echo "deauth"; exit 0; }
# shellcheck disable=SC1090
. "$CONFIG"

MODE="decision"
case "${1:-}" in
  --json)
    MODE="json"
    shift
    ;;
  --account)
    MODE="account"
    shift
    ;;
esac

if [ "$MODE" = "account" ]; then
  SESSION_ID="${1:-}"
  DELTA_UP_BYTES="${2:-}"
  DELTA_DOWN_BYTES="${3:-}"
  [[ "$SESSION_ID" =~ ^[0-9]+$ ]] && [ "$SESSION_ID" -gt 0 ] || exit 2
  [[ "$DELTA_UP_BYTES" =~ ^[0-9]+$ ]] || exit 2
  [[ "$DELTA_DOWN_BYTES" =~ ^[0-9]+$ ]] || exit 2

  json=$(curl -fsS --connect-timeout 3 --max-time 10 \
    -H "X-TaraSec-Gateway-Key: ${TARASEC_GATEWAY_KEY:-}" \
    -H "X-TaraSec-Token: ${TARASEC_GATEWAY_TOKEN:-}" \
    --data-urlencode "session_id=$SESSION_ID" \
    --data-urlencode "delta_up_bytes=$DELTA_UP_BYTES" \
    --data-urlencode "delta_down_bytes=$DELTA_DOWN_BYTES" \
    "${TARASEC_ACCOUNTING_URL:-https://tarasec.org/api/v1/subscriber/accounting-api.php}" 2>/dev/null) || {
      logger -t tarasec-accounting "central accounting failed for session $SESSION_ID"
      exit 1
  }
  printf '%s\n' "$json"
  printf '%s' "$json" | grep -Eq '"ok"[[:space:]]*:[[:space:]]*true'
  exit $?
fi

CLIENT_MAC="${1:-}"
CLIENT_IP="${2:-}"
[ -n "$CLIENT_MAC" ] || { [ "$MODE" = "json" ] && echo '{}'; [ "$MODE" = "decision" ] && echo "deauth"; exit 0; }

json=$(curl -fsS --connect-timeout 3 --max-time 7 \
  -H "X-TaraSec-Gateway-Key: ${TARASEC_GATEWAY_KEY:-}" \
  -H "X-TaraSec-Token: ${TARASEC_GATEWAY_TOKEN:-}" \
  --data-urlencode "device_key=$CLIENT_MAC" \
  --data-urlencode "client_ip=$CLIENT_IP" \
  "${TARASEC_ACCESS_URL:-https://tarasec.org/api/v1/subscriber/access-api.php}" 2>/dev/null) || {
    logger -t tarasec-access "access lookup failed for $CLIENT_MAC/$CLIENT_IP"
    if [ "$MODE" = "json" ]; then
      echo '{}'
      exit 1
    fi
    echo "deauth"
    exit 0
}

if [ "$MODE" = "json" ]; then
  printf '%s\n' "$json"
  exit 0
fi

if printf '%s' "$json" | grep -Eq '"allow"[[:space:]]*:[[:space:]]*true'; then
  logger -t tarasec-access "authorized $CLIENT_MAC/$CLIENT_IP"
  echo "auth"
else
  logger -t tarasec-access "denied $CLIENT_MAC/$CLIENT_IP"
  echo "deauth"
fi
