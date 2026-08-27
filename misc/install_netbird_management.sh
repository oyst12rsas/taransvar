#!/bin/bash
set -euo pipefail

# Install and enroll the NetBird agent as TaraSec's management plane.
# A TaraSec hotspot is not considered fully installed unless NetBird enrollment
# succeeds. Hotspot client traffic must continue to use the normal WAN; wt0 is
# management only and must never become the default Internet route.
#
# Enrollment credentials are deliberately not committed to Git. Supply them by
# one of these mechanisms, in priority order:
#   1. Environment variables NB_SETUP_KEY and optional NB_MANAGEMENT_URL
#   2. /etc/tarasec/netbird.env
#   3. /root/taransvar/netbird.env
#
# netbird.env format:
#   NB_SETUP_KEY='...'
#   NB_MANAGEMENT_URL='https://...'

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

load_env_file() {
    local f="$1"
    if [ -f "$f" ]; then
        echo "Loading NetBird enrollment configuration from $f"
        # shellcheck disable=SC1090
        set -a
        . "$f"
        set +a
    fi
}

# Explicit environment wins. Only load a file when the key is not already set.
if [ -z "${NB_SETUP_KEY:-}" ]; then
    load_env_file /etc/tarasec/netbird.env
fi
if [ -z "${NB_SETUP_KEY:-}" ]; then
    load_env_file /root/taransvar/netbird.env
fi

if ! command -v curl >/dev/null 2>&1; then
    apt-get update
    apt-get install -y curl ca-certificates
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

# Ensure the daemon is enabled wherever the package exposes a systemd unit.
if systemctl list-unit-files 2>/dev/null | grep -q '^netbird\.service'; then
    systemctl enable --now netbird.service
fi

# If already connected, do not require a setup key again.
if netbird status 2>/dev/null | grep -qiE 'Management: Connected|Signal: Connected'; then
    echo "NetBird is already enrolled and connected."
else
    if [ -z "${NB_SETUP_KEY:-}" ]; then
        echo "ERROR: TaraSec hotspot requires automatic NetBird enrollment, but no setup key was supplied." >&2
        echo "Provide NB_SETUP_KEY in the environment or in /etc/tarasec/netbird.env." >&2
        exit 1
    fi

    args=(up --setup-key "$NB_SETUP_KEY")
    if [ -n "${NB_MANAGEMENT_URL:-}" ]; then
        args+=(--management-url "$NB_MANAGEMENT_URL")
    fi

    echo "Enrolling this TaraSec hotspot in NetBird management..."
    netbird "${args[@]}"
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
echo "wt0/wt* is reserved for TaraSec management and is not a hotspot WAN/LAN interface."
