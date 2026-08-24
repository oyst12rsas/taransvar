#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
WAZUH_ETC="${WAZUH_ETC:-/var/ossec/etc}"

if [ ! -d "$WAZUH_ETC/decoders" ] || [ ! -d "$WAZUH_ETC/rules" ]; then
  echo "Wazuh manager directories not found under $WAZUH_ETC" >&2
  exit 1
fi

install -o root -g wazuh -m 0640 \
  "$ROOT_DIR/misc/wazuh/tarasec_decoders.xml" \
  "$WAZUH_ETC/decoders/tarasec_decoders.xml"

install -o root -g wazuh -m 0640 \
  "$ROOT_DIR/misc/wazuh/tarasec_rules.xml" \
  "$WAZUH_ETC/rules/tarasec_rules.xml"

/var/ossec/bin/wazuh-analysisd -t
systemctl restart wazuh-manager
systemctl --no-pager --full status wazuh-manager
