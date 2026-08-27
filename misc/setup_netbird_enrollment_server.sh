#!/bin/bash
set -euo pipefail

# Configure the TaraSec web server to issue one-off NetBird setup keys to new
# hotspots. Run this ONLY on the TaraSec enrollment/API server.
#
# Required environment variables:
#   NB_API_URL
#   NB_API_TOKEN
#   NB_BOOTSTRAP_GROUP_ID
# Optional:
#   NB_MANAGEMENT_URL
#
# Example:
#   sudo NB_API_URL='https://api.netbird.io' \
#        NB_API_TOKEN='...' \
#        NB_BOOTSTRAP_GROUP_ID='...' \
#        bash misc/setup_netbird_enrollment_server.sh

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo -E bash $0" >&2
    exit 1
fi

: "${NB_API_URL:?NB_API_URL is required}"
: "${NB_API_TOKEN:?NB_API_TOKEN is required}"
: "${NB_BOOTSTRAP_GROUP_ID:?NB_BOOTSTRAP_GROUP_ID is required}"
NB_MANAGEMENT_URL="${NB_MANAGEMENT_URL:-}"

if ! command -v curl >/dev/null 2>&1; then
    apt-get update
    apt-get install -y curl ca-certificates
fi

# PHP endpoint uses curl_init(). Install the module where apt is available.
if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    apt-get install -y php-curl
fi

install -d -m 0750 /etc/tarasec
umask 077
cat > /etc/tarasec/netbird-enrollment.env <<EOF
NB_API_URL=$NB_API_URL
NB_API_TOKEN=$NB_API_TOKEN
NB_BOOTSTRAP_GROUP_ID=$NB_BOOTSTRAP_GROUP_ID
NB_MANAGEMENT_URL=$NB_MANAGEMENT_URL
EOF
chmod 0640 /etc/tarasec/netbird-enrollment.env

# Allow the common Apache/PHP service account to read the enrollment config
# without making the NetBird API token world-readable.
if id www-data >/dev/null 2>&1; then
    chown root:www-data /etc/tarasec/netbird-enrollment.env
fi

echo "Testing NetBird API authentication and bootstrap group visibility..."
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
HTTP="$(curl -sS -o "$TMP" -w '%{http_code}' \
    -H 'Accept: application/json' \
    -H "Authorization: Token $NB_API_TOKEN" \
    "$NB_API_URL/api/groups/$NB_BOOTSTRAP_GROUP_ID" || true)"

if [ "$HTTP" -lt 200 ] 2>/dev/null || [ "$HTTP" -ge 300 ] 2>/dev/null; then
    echo "ERROR: NetBird API/group check failed with HTTP ${HTTP:-unknown}." >&2
    cat "$TMP" >&2 || true
    exit 1
fi

echo "NetBird API configuration is valid."
echo "Enrollment config installed at /etc/tarasec/netbird-enrollment.env"
echo
echo "SECURITY REQUIREMENT:"
echo "  NB_BOOTSTRAP_GROUP_ID must be restricted by NetBird policy."
echo "  New hotspots must not receive general hotspot-to-hotspot or production access"
echo "  until TaraSec has completed owner/device registration and promotion."
