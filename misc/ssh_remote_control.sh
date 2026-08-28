#!/bin/bash
# TaraSec remote SSH control helper.
# Run this from an owner-approved DB/management server.
# Usage:
#   ./ssh_remote_control.sh 100.68.67.5 status
#   ./ssh_remote_control.sh 100.68.67.5 close
#   ./ssh_remote_control.sh 100.68.67.5 open
# Optional token: export TARASEC_SSH_REMOTE_TOKEN='...'

set -euo pipefail

TARGET="${1:-}"
ACTION="${2:-status}"

if [ -z "$TARGET" ]; then
    echo "Usage: $0 <node-ip-or-hostname> <open|close|status>" >&2
    exit 2
fi

case "$ACTION" in
    open|close|status) ;;
    *) echo "Action must be open, close or status" >&2; exit 2 ;;
esac

URL="http://${TARGET}/script/sshControl.php"
ARGS=(--fail --silent --show-error --connect-timeout 5 --max-time 10 -X POST --data-urlencode "action=$ACTION")

if [ -n "${TARASEC_SSH_REMOTE_TOKEN:-}" ]; then
    ARGS+=(-H "X-TaraSec-Token: ${TARASEC_SSH_REMOTE_TOKEN}")
fi

curl "${ARGS[@]}" "$URL"
echo
