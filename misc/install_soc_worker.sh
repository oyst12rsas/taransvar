#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

install -o root -g root -m 0755 \
  "$ROOT_DIR/misc/worker_soc_events.pl" \
  /usr/local/sbin/tarasec-soc-events.pl

install -o root -g root -m 0644 \
  "$ROOT_DIR/misc/systemd/tarasec-soc-events.service" \
  /etc/systemd/system/tarasec-soc-events.service

mkdir -p /var/lib/tarasec

systemctl daemon-reload
systemctl enable --now tarasec-soc-events.service
systemctl --no-pager --full status tarasec-soc-events.service
