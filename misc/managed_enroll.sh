#!/usr/bin/env bash
set -euo pipefail

ENROLL_URL="${TARASEC_ENROLL_URL:-https://tarasec.org/script/managedEnroll.php}"
TOKEN="${TARASEC_ENROLL_TOKEN:-}"
COUNTRY="${TARASEC_COUNTRY:-}"
OWNER_NAME="${TARASEC_OWNER_NAME:-}"
OWNER_EMAIL="${TARASEC_OWNER_EMAIL:-}"
OWNER_PHONE="${TARASEC_OWNER_PHONE:-}"
STATUS_FILE=/etc/tarasec-managed.conf
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

usage() {
  cat <<EOF
Usage: sudo $0 --token ONE_TIME_TOKEN [--country KE|PH] [--name NAME] [--email EMAIL] [--phone PHONE] [--server URL]

Enrollment explicitly enables TaraSec managed services and registers the hotspot owner
with TaraSec for support, installation counting and payment onboarding follow-up.

  - TaraSec management VPN (NetBird)
  - TaraSec SOC monitoring (Wazuh agent)
  - automatic payment integration offer

No reusable NetBird, Wazuh, or payment-provider secret is shipped in this script.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --token) TOKEN="${2:-}"; shift 2;;
    --country) COUNTRY="${2:-}"; shift 2;;
    --name) OWNER_NAME="${2:-}"; shift 2;;
    --email) OWNER_EMAIL="${2:-}"; shift 2;;
    --phone) OWNER_PHONE="${2:-}"; shift 2;;
    --server) ENROLL_URL="${2:-}"; shift 2;;
    -h|--help) usage; exit 0;;
    *) echo "Unknown argument: $1" >&2; usage; exit 2;;
  esac
done

[[ $EUID -eq 0 ]] || { echo "Run with sudo/root." >&2; exit 1; }
[[ -n "$TOKEN" ]] || { usage; exit 2; }
[[ "$ENROLL_URL" == https://* ]] || { echo "Enrollment URL must use HTTPS." >&2; exit 1; }

# Interactive installs ask the owner. Automated/OEM installs can pass the same
# values through TARASEC_OWNER_* environment variables or command-line options.
if [[ -z "$OWNER_NAME" && -t 0 ]]; then read -r -p 'Hotspot owner / contact name: ' OWNER_NAME; fi
if [[ -z "$OWNER_EMAIL" && -t 0 ]]; then read -r -p 'Owner email address: ' OWNER_EMAIL; fi
if [[ -z "$OWNER_PHONE" && -t 0 ]]; then read -r -p 'Owner phone number (include country code): ' OWNER_PHONE; fi
[[ -n "$OWNER_NAME" ]] || { echo "Owner name is required." >&2; exit 2; }
[[ -n "$OWNER_EMAIL" ]] || { echo "Owner email is required." >&2; exit 2; }
[[ -n "$OWNER_PHONE" ]] || { echo "Owner phone number is required." >&2; exit 2; }

echo
echo "TaraSec will register this contact for managed hotspot support, installation"
echo "counting, SOC operations and payment-integration follow-up:"
echo "  Name:  $OWNER_NAME"
echo "  Email: $OWNER_EMAIL"
echo "  Phone: $OWNER_PHONE"
if [[ -t 0 ]]; then
  read -r -p 'Continue with registration? [y/N] ' CONFIRM
  [[ "$CONFIRM" =~ ^[Yy]$ ]] || { echo "Enrollment cancelled."; exit 1; }
fi

apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y curl jq ca-certificates gnupg

HOSTNAME_SAFE="$(hostname | tr -cd 'A-Za-z0-9_.-' | cut -c1-200)"
MACHINE_ID=""
[[ -r /etc/machine-id ]] && MACHINE_ID="$(tr -d '\n' </etc/machine-id | cut -c1-128)"

PAYLOAD="$(jq -cn \
  --arg token "$TOKEN" \
  --arg hostname "$HOSTNAME_SAFE" \
  --arg country "${COUNTRY^^}" \
  --arg machine_id "$MACHINE_ID" \
  --arg owner_name "$OWNER_NAME" \
  --arg owner_email "$OWNER_EMAIL" \
  --arg owner_phone "$OWNER_PHONE" \
  '{enrollment_token:$token,hostname:$hostname,country:$country,machine_id:$machine_id,owner_name:$owner_name,owner_email:$owner_email,owner_phone:$owner_phone}')"

HTTP_CODE="$(curl --fail-with-body -sS -o "$TMP" -w '%{http_code}' \
  -H 'Content-Type: application/json' --data "$PAYLOAD" "$ENROLL_URL" || true)"
if [[ "$HTTP_CODE" != "200" ]] || ! jq -e '.ok == true' "$TMP" >/dev/null 2>&1; then
  echo "TaraSec enrollment failed (HTTP $HTTP_CODE)." >&2
  jq -r '.error // "Unknown enrollment error"' "$TMP" 2>/dev/null >&2 || true
  exit 1
fi

INSTALLATION_ID="$(jq -r '.installation_id' "$TMP")"
INSTALLATION_UUID="$(jq -r '.installation_uuid' "$TMP")"
NB_URL="$(jq -r '.netbird.management_url' "$TMP")"
NB_KEY="$(jq -r '.netbird.setup_key' "$TMP")"
WAZUH_MANAGER="$(jq -r '.soc.manager' "$TMP")"
WAZUH_AGENT_ID="$(jq -r '.soc.agent_id' "$TMP")"
WAZUH_AGENT_NAME="$(jq -r '.soc.agent_name' "$TMP")"
WAZUH_AGENT_KEY="$(jq -r '.soc.agent_key' "$TMP")"
PAYMENT_AVAILABLE="$(jq -r '.payment.available' "$TMP")"
PAYMENT_CONTACT_URL="$(jq -r '.payment.contact_url' "$TMP")"

install_netbird() {
  if ! command -v netbird >/dev/null 2>&1; then
    curl -fsSL https://pkgs.netbird.io/install.sh | sh
  fi
  netbird up --management-url "$NB_URL" --setup-key "$NB_KEY"
  systemctl enable netbird >/dev/null 2>&1 || true
}

install_wazuh() {
  if ! dpkg-query -W -f='${Status}' wazuh-agent 2>/dev/null | grep -q 'ok installed'; then
    curl -s https://packages.wazuh.com/key/GPG-KEY-WAZUH | gpg --dearmor --yes -o /usr/share/keyrings/wazuh.gpg
    chmod 644 /usr/share/keyrings/wazuh.gpg
    echo 'deb [signed-by=/usr/share/keyrings/wazuh.gpg] https://packages.wazuh.com/4.x/apt/ stable main' > /etc/apt/sources.list.d/wazuh.list
    apt-get update
    WAZUH_MANAGER="$WAZUH_MANAGER" DEBIAN_FRONTEND=noninteractive apt-get install -y wazuh-agent
  fi

  /var/ossec/bin/manage_agents -i "$WAZUH_AGENT_KEY" >/dev/null

  python3 - "$WAZUH_MANAGER" <<'PY'
from pathlib import Path
import re, sys
p=Path('/var/ossec/etc/ossec.conf')
s=p.read_text()
manager=sys.argv[1]
pat=r'(<client>.*?<server>.*?<address>)(.*?)(</address>)'
ns,n=re.subn(pat, lambda m:m.group(1)+manager+m.group(3), s, count=1, flags=re.S)
if n:
    p.write_text(ns)
else:
    raise SystemExit('Unable to locate Wazuh client/server/address in ossec.conf')
PY
  systemctl enable --now wazuh-agent
}

install_netbird
NB_KEY=''
install_wazuh
WAZUH_AGENT_KEY=''
: > "$TMP"

# Deliberately do not store owner email/phone/name in this local status file.
# TaraSec/back-office keeps the registration; the hotspot only needs its
# installation identity and service status.
umask 077
cat > "$STATUS_FILE" <<EOF
MANAGED=1
INSTALLATION_ID=$INSTALLATION_ID
INSTALLATION_UUID=$INSTALLATION_UUID
ENROLLMENT_SERVER=$ENROLL_URL
COUNTRY=${COUNTRY^^}
NETBIRD_MANAGEMENT_URL=$NB_URL
WAZUH_MANAGER=$WAZUH_MANAGER
WAZUH_AGENT_ID=$WAZUH_AGENT_ID
WAZUH_AGENT_NAME=$WAZUH_AGENT_NAME
PAYMENT_AVAILABLE=$PAYMENT_AVAILABLE
PAYMENT_CONFIGURED=0
PAYMENT_CONTACT_URL=$PAYMENT_CONTACT_URL
EOF
chmod 600 "$STATUS_FILE"

cat <<EOF

TaraSec managed services enrolled successfully.
  Installation:       $INSTALLATION_ID ($INSTALLATION_UUID)
  Owner registration: sent to TaraSec; queued for global DB synchronization
  Management VPN:     NetBird connected
  SOC monitoring:     Wazuh agent $WAZUH_AGENT_ID
  Automatic payments: $([[ "$PAYMENT_AVAILABLE" == "true" ]] && echo 'AVAILABLE - owner can request setup' || echo 'not offered')

Local status page: /managed-services.php
EOF
