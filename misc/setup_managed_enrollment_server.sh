#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF=/etc/tarasec-managed-server.conf
RUNTIME=/root/taransvar/perl
SYSTEMD=/etc/systemd/system

[[ $EUID -eq 0 ]] || { echo "Run with sudo/root." >&2; exit 1; }

if [[ ! -f "$CONF" ]]; then
  install -m 0600 "$ROOT/managed-server.conf.example" "$CONF"
  echo "Created $CONF. Fill dedicated NetBird/Wazuh/global-forward credentials, then run again."
  exit 2
fi
chmod 600 "$CONF"

required=(NETBIRD_API_BASE NETBIRD_MANAGEMENT_URL NETBIRD_API_TOKEN NETBIRD_HOTSPOT_GROUP_ID WAZUH_API_BASE WAZUH_MANAGER WAZUH_API_USER WAZUH_API_PASSWORD GLOBAL_DB_REGISTER_URL GLOBAL_DB_SHARED_SECRET)
for key in "${required[@]}"; do
  value="$(awk -F= -v k="$key" '$1==k{sub(/^[^=]*=/,""); print; exit}' "$CONF")"
  if [[ -z "$value" ]]; then echo "Missing $key in $CONF" >&2; exit 2; fi
done

mkdir -p "$RUNTIME"
install -m 0755 "$ROOT/managed_owner_forward.pl" "$RUNTIME/managed_owner_forward.pl"
install -m 0644 "$ROOT/systemd/tarasec-managed-owner-forward.service" "$SYSTEMD/tarasec-managed-owner-forward.service"
install -m 0644 "$ROOT/systemd/tarasec-managed-owner-forward.timer" "$SYSTEMD/tarasec-managed-owner-forward.timer"
systemctl daemon-reload
systemctl enable --now tarasec-managed-owner-forward.timer

cat <<EOF
Managed enrollment server configuration looks complete.

Database setup on the TaraSec back-office DB:
  mysql < $ROOT/managed_enrollment_schema.sql

The managedOwnerRegistration table plus globalManagedRegister.php must also be
installed on the global DB server, with:
  /etc/tarasec-global-register.conf

Create a one-time customer enrollment token:
  sudo perl $ROOT/create_managed_enrollment_token.pl owner@example.com 24

Public enrollment endpoint:
  /script/managedEnroll.php

Global forwarding worker:
  tarasec-managed-owner-forward.timer
  journalctl -u tarasec-managed-owner-forward.service

Security:
  - NETBIRD_API_TOKEN: dedicated NetBird service user
  - WAZUH_API_USER: minimum agent-create/key permissions
  - GLOBAL_DB_SHARED_SECRET: long random secret, different from all other keys
  - keep $CONF mode 0600; never copy it to hotspot gateways
EOF
