#!/bin/bash
set -euo pipefail

# Install and enroll the NetBird agent as TaraSec's management plane.
# A TaraSec hotspot is not considered fully installed unless NetBird enrollment
# succeeds. Hotspot client traffic must continue to use the normal WAN; wt0 is
# management only and must never become the default Internet route.
#
# Normal production flow:
#   1. Generate/persist a unique TaraSec device ID.
#   2. Contact the TaraSec HTTPS enrollment service.
#   3. Receive a one-off NetBird setup key assigned to the restricted bootstrap
#      group.
#   4. Enroll immediately and discard the setup key.
#
# Local/test override is still supported with NB_SETUP_KEY.

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

TARASEC_DIR="${TARASEC_DIR:-/etc/tarasec}"
DEVICE_ID_FILE="$TARASEC_DIR/device-id"
ENROLL_URL="${TARASEC_ENROLL_URL:-https://tarasec.org/script/hotspotNetbirdEnroll.php}"

mkdir -p "$TARASEC_DIR"
chmod 700 "$TARASEC_DIR"

load_env_file() {
    local f="$1"
    if [ -f "$f" ]; then
        echo "Loading TaraSec/NetBird configuration from $f"
        # shellcheck disable=SC1090
        set -a
        . "$f"
        set +a
    fi
}

# Optional local configuration can override enrollment URL or provide an
# explicit test setup key. Production hotspots normally need neither.
load_env_file /etc/tarasec/netbird.env
load_env_file /root/taransvar/netbird.env
ENROLL_URL="${TARASEC_ENROLL_URL:-$ENROLL_URL}"

if ! command -v curl >/dev/null 2>&1 || ! command -v python3 >/dev/null 2>&1; then
    apt-get update
    apt-get install -y curl ca-certificates python3
fi

if [ ! -s "$DEVICE_ID_FILE" ]; then
    umask 077
    if [ -r /proc/sys/kernel/random/uuid ]; then
        cat /proc/sys/kernel/random/uuid > "$DEVICE_ID_FILE"
    else
        python3 - <<'PY' > "$DEVICE_ID_FILE"
import uuid
print(uuid.uuid4())
PY
    fi
    chmod 600 "$DEVICE_ID_FILE"
fi

DEVICE_ID="$(tr -d '[:space:]' < "$DEVICE_ID_FILE")"
if ! printf '%s' "$DEVICE_ID" | grep -Eq '^[A-Fa-f0-9-]{32,64}$'; then
    echo "ERROR: Invalid TaraSec device ID in $DEVICE_ID_FILE" >&2
    exit 1
fi

if ! command -v netbird >/dev/null 2>&1; then
    echo "Installing NetBird agent automatically..."
    curl -fsSL https://pkgs.netbird.io/install.sh | sh
else
    echo "NetBird is already installed: $(netbird version 2>/dev/null || true)"
fi

if ! command -v netbird >/dev/null 2>&1; then
    echo "ERROR: NetBird installation did not provide the netbird command." >&2
    exit 1
fi

if systemctl list-unit-files 2>/dev/null | grep -q '^netbird\.service'; then
    systemctl enable --now netbird.service
fi

is_connected() {
    netbird status 2>/dev/null | grep -qiE 'Management: Connected|Signal: Connected'
}

if is_connected; then
    echo "NetBird is already enrolled and connected."
else
    TMP_RESPONSE="$(mktemp /tmp/tarasec-netbird-enroll.XXXXXX)"
    chmod 600 "$TMP_RESPONSE"
    cleanup() {
        rm -f "$TMP_RESPONSE"
        unset NB_SETUP_KEY || true
    }
    trap cleanup EXIT

    # Test/repair override. Normal production installs request a fresh one-off
    # key from TaraSec instead of embedding any reusable credential.
    if [ -z "${NB_SETUP_KEY:-}" ]; then
        echo "Requesting one-time NetBird enrollment credential from TaraSec..."

        HOSTNAME_VALUE="$(hostname 2>/dev/null || echo unknown)"
        ARCH_VALUE="$(uname -m 2>/dev/null || echo unknown)"

        REQUEST_JSON="$(python3 - "$DEVICE_ID" "$HOSTNAME_VALUE" "$ARCH_VALUE" <<'PY'
import json, sys
print(json.dumps({
    "device_id": sys.argv[1],
    "hostname": sys.argv[2],
    "arch": sys.argv[3],
}))
PY
)"

        HTTP_CODE="$(curl -sS \
            --connect-timeout 10 \
            --max-time 30 \
            -o "$TMP_RESPONSE" \
            -w '%{http_code}' \
            -H 'Accept: application/json' \
            -H 'Content-Type: application/json' \
            --data-binary "$REQUEST_JSON" \
            "$ENROLL_URL" || true)"

        if [ "$HTTP_CODE" -lt 200 ] 2>/dev/null || [ "$HTTP_CODE" -ge 300 ] 2>/dev/null; then
            echo "ERROR: TaraSec enrollment service returned HTTP ${HTTP_CODE:-unknown}." >&2
            python3 - "$TMP_RESPONSE" <<'PY' >&2 || true
import json, sys
try:
    d=json.load(open(sys.argv[1]))
    print(d.get("error", "Enrollment failed"))
except Exception:
    pass
PY
            exit 1
        fi

        mapfile -t ENROLL_VALUES < <(python3 - "$TMP_RESPONSE" <<'PY'
import json, sys
try:
    d=json.load(open(sys.argv[1]))
except Exception as e:
    print("")
    print("")
    raise SystemExit(0)
if not d.get("ok"):
    print("")
    print("")
else:
    print(d.get("setup_key", ""))
    print(d.get("management_url", ""))
PY
)

        NB_SETUP_KEY="${ENROLL_VALUES[0]:-}"
        if [ -z "${NB_MANAGEMENT_URL:-}" ]; then
            NB_MANAGEMENT_URL="${ENROLL_VALUES[1]:-}"
        fi

        if [ -z "$NB_SETUP_KEY" ]; then
            echo "ERROR: TaraSec enrollment response did not contain a setup key." >&2
            exit 1
        fi
    else
        echo "Using explicitly supplied NetBird setup key (test/repair override)."
    fi

    args=(up --setup-key "$NB_SETUP_KEY")
    if [ -n "${NB_MANAGEMENT_URL:-}" ]; then
        args+=(--management-url "$NB_MANAGEMENT_URL")
    fi

    echo "Enrolling this TaraSec hotspot in NetBird management..."
    netbird "${args[@]}"

    # One-off credential is no longer needed. Do not persist it or print it.
    unset NB_SETUP_KEY
    : > "$TMP_RESPONSE"
fi

echo
echo "=== NetBird status ==="
status="$(netbird status 2>&1 || true)"
echo "$status"

if ! printf '%s\n' "$status" | grep -qiE 'Management: Connected|Signal: Connected'; then
    echo "ERROR: NetBird is installed, but management connectivity could not be verified." >&2
    exit 1
fi

# Guard against accidentally turning NetBird into the customer Internet path.
if ip -4 route show default 2>/dev/null | grep -qE ' dev (wt[0-9]+|netbird[0-9]*)($| )'; then
    echo "ERROR: A NetBird interface is the default route. TaraSec requires the normal WAN for customer Internet traffic." >&2
    exit 1
fi

echo
echo "NetBird management plane is installed, enrolled, and connected."
echo "TaraSec device ID: $DEVICE_ID"
echo "wt0/wt* is reserved for TaraSec management and is not a hotspot WAN/LAN interface."
