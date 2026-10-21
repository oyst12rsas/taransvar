#!/bin/bash
# Enroll the central DB server's own observer without transferring a secret.
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root'; exit 1; }
[[ $# -eq 1 ]] || { echo 'Usage: configure_operations_local_activity.sh WEB_ROOT'; exit 1; }
source_dir="$(cd "$(dirname "$0")" && pwd)"
# The feed checks the central DB role, complete schema and source-bound token.
bash "$source_dir/install_operations_activity_feed.sh" 127.0.0.1 "$1"
bash "$source_dir/configure_operations_activity.sh" \
  http://127.0.0.1/script/operationsActivity.php \
  /etc/tarasec-operations-feed/node-127.0.0.1.key --loopback-http
