#!/bin/bash
# Install the hourly operator AI report (log and access evidence) on this node.
#
#   sudo bash misc/install_server_ai_report.sh          # manual install, any mode
#   sudo bash misc/install_server_ai_report.sh --auto   # used by install.sh
#
# --auto installs only on demo nodes (and unconfigured nodes, which keep demo
# behaviour). Production nodes print the manual command and change nothing.
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run with sudo.' >&2; exit 1; }
case "${1:-}" in ''|--auto) ;; *) echo 'Usage: sudo bash misc/install_server_ai_report.sh [--auto]' >&2; exit 2 ;; esac
source_dir="$(cd "$(dirname "$0")" && pwd)"
mode_file=/etc/tarasec-deployment-mode

if [[ "${1:-}" == --auto ]]; then
    mode=unconfigured
    if [[ -e $mode_file ]]; then mode="$(tr -d '[:space:]' < "$mode_file" 2>/dev/null || echo unreadable)"; fi
    case "$mode" in
        demo|unconfigured) ;;
        production)
            echo 'Operator AI reports are not installed automatically on production nodes.'
            echo "To enable them: sudo bash $source_dir/install_server_ai_report.sh"
            exit 0 ;;
        *)
            echo "Skipping operator AI reports: $mode_file is not 'demo' or 'production'." >&2
            exit 0 ;;
    esac
fi

python3 -m py_compile "$source_dir/server_ai_report_client.py" "$source_dir/server_ai_evidence.py"
lib=/usr/local/lib/tarasec-operations
install -d -o root -g root -m 0755 "$lib"
install -o root -g root -m 0755 "$source_dir/server_ai_report_client.py" "$lib/"
install -o root -g root -m 0644 "$source_dir/server_ai_evidence.py" "$lib/"
cat > "$lib/run-server-ai-report" <<'EOF'
#!/bin/sh
set -eu
if [ ! -f /etc/tarasec/server-ai-report.env ] || [ ! -f /etc/tarasec/server-ai-node.token ]; then
    unset SSL_CERT_FILE
    exec /usr/bin/python3 /usr/local/lib/tarasec-operations/server_ai_report_client.py --enroll --local
fi
: "${SERVER_AI_URL:?Dispatcher URL is required}"
: "${SERVER_AI_NODE_ID:?Dispatcher node ID is required}"
SERVER_AI_NODE_TOKEN="$(cat /etc/tarasec/server-ai-node.token)"
export SERVER_AI_NODE_TOKEN
exec /usr/bin/python3 /usr/local/lib/tarasec-operations/server_ai_report_client.py \
    --url "$SERVER_AI_URL" --node "$SERVER_AI_NODE_ID" --local
EOF
chmod 0755 "$lib/run-server-ai-report"
cat > /etc/systemd/system/tarasec-server-ai-report.service <<'EOF'
[Unit]
Description=Submit TaraSec log and access report for advisory analysis
Wants=network-online.target
After=network-online.target
ConditionPathExists=|/etc/tarasec/agent-enrollment.json
ConditionPathExists=|/etc/tarasec/server-ai-report.env

[Service]
Type=oneshot
EnvironmentFile=-/etc/tarasec/server-ai-report.env
ExecStart=/usr/local/lib/tarasec-operations/run-server-ai-report
StateDirectory=tarasec-server-ai-report
StateDirectoryMode=0700
TimeoutStartSec=6min
UMask=0077
NoNewPrivileges=true
ProtectSystem=strict
PrivateTmp=true
EOF
cat > /etc/systemd/system/tarasec-server-ai-report.timer <<'EOF'
[Unit]
Description=Schedule TaraSec log and access reports

[Timer]
OnCalendar=hourly
RandomizedDelaySec=5min
Persistent=true

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now tarasec-server-ai-report.timer
echo 'Operator AI reports installed: hourly log and access summaries (see docs/SERVER_AI_REPORT_TOPICS.md).'
echo "Preview what is sent: sudo python3 $lib/server_ai_report_client.py --local --dry-run"
