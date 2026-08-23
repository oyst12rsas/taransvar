#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF=/etc/tarasec-managed-server.conf

[[ $EUID -eq 0 ]] || { echo "Run with sudo/root." >&2; exit 1; }

if [[ ! -f "$CONF" ]]; then
  install -m 0600 "$ROOT/managed-server.conf.example" "$CONF"
  echo "Created $CONF. Fill in the dedicated NetBird and Wazuh service credentials, then run this script again."
  exit 2
fi
chmod 600 "$CONF"

# Refuse to claim readiness while required secrets/IDs are blank.
required=(NETBIRD_API_BASE NETBIRD_MANAGEMENT_URL NETBIRD_API_TOKEN NETBIRD_HOTSPOT_GROUP_ID WAZUH_API_BASE WAZUH_MANAGER WAZUH_API_USER WAZUH_API_PASSWORD)
for key in "${required[@]}"; do
  value="$(awk -F= -v k="$key" '$1==k{sub(/^[^=]*=/,""); print; exit}' "$CONF")"
  if [[ -z "$value" ]]; then
    echo "Missing $key in $CONF" >&2
    exit 2
  fi
done

cat <<EOF
Managed enrollment server configuration looks complete.

Database setup (global/back-office database only):
  mysql < $ROOT/managed_enrollment_schema.sql

Create a one-time customer enrollment token after the schema is installed:
  sudo perl $ROOT/create_managed_enrollment_token.pl owner@example.com 24

The public web endpoint is:
  /script/managedEnroll.php

Security notes:
  - NETBIRD_API_TOKEN should belong to a dedicated NetBird service user.
  - WAZUH_API_USER should have only the RBAC permissions required to create agents/get their keys.
  - keep $CONF mode 0600 and never copy it to a hotspot gateway.
EOF
