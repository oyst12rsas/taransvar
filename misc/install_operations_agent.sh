#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root'; exit 1; }
source_dir="$(cd "$(dirname "$0")" && pwd)"
install -d -o root -g root -m 0755 /usr/local/lib/tarasec-operations
# /etc/tarasec is shared with Gatekeeper: preserve existing permissions/ACLs.
# Resetting it to 0700 masks Apache traversal access and hides Google sign-in.
# New directories remain private; web-readable configuration is set up separately.
if [[ ! -d /etc/tarasec ]]; then
    install -d -o root -g root -m 0700 /etc/tarasec
fi
install -d -o root -g root -m 0700 /var/lib/tarasec-operations
install -o root -g root -m 0755 "$source_dir/operations_agent.py" "$source_dir/operations_activity.py" "$source_dir/operations_observer.py" "$source_dir/operations_diagnostics.py" "$source_dir/operations_actions.py" "$source_dir/operations_prompt.py" "$source_dir/operations_model_probe.py" "$source_dir/operations_collectors.py" "$source_dir/operations_model.py" "$source_dir/operations_model_errors.py" "$source_dir/install_operations_reporter_bridge.py" "$source_dir/operations_policy.py" "$source_dir/operations_reboot.py" "$source_dir/operations_schedule.py" "$source_dir/operations_reporting.py" "$source_dir/operations_issues.py" "$source_dir/operations_manual.py" "$source_dir/operations_manual_issues.pl" "$source_dir/operations_deployment.py" "$source_dir/operations_upgrade.py" "$source_dir/operations_tools.py" "$source_dir/configure_operations_journal.py" "$source_dir/configure_operations_direct_probe.py" /usr/local/lib/tarasec-operations/
cat > /usr/local/lib/tarasec-operations/activity-probe <<'EOF'
#!/bin/sh
exec /usr/bin/python3 /usr/local/lib/tarasec-operations/operations_activity.py --read
EOF
chmod 0755 /usr/local/lib/tarasec-operations/activity-probe
cat > /usr/local/lib/tarasec-operations/reboot-verification-probe <<'EOF'
#!/bin/sh
exec /usr/bin/python3 /usr/local/lib/tarasec-operations/operations_reboot.py
EOF
chmod 0755 /usr/local/lib/tarasec-operations/reboot-verification-probe
for kind in demo traffic; do
    cat > "/usr/local/lib/tarasec-operations/$kind-collector" <<EOF
#!/bin/sh
exec /usr/bin/python3 /usr/local/lib/tarasec-operations/operations_collectors.py $kind
EOF
    chmod 0755 "/usr/local/lib/tarasec-operations/$kind-collector"
done
if [[ ! -e /etc/tarasec/operations-activity.json ]]; then
    install -o root -g root -m 0600 "$source_dir/operations-activity.json.example" /etc/tarasec/operations-activity.json
fi
cat > /etc/systemd/system/tarasec-operations-activity.service <<'EOF'
[Unit]
Description=TaraSec continuous demo and traffic observer
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
UMask=0077
RuntimeDirectory=tarasec-operations
RuntimeDirectoryMode=0700
ExecStart=/usr/bin/python3 /usr/local/lib/tarasec-operations/operations_activity.py
Restart=on-failure
RestartSec=10
[Install]
WantedBy=multi-user.target
EOF
install -o root -g root -m 0644 "$source_dir/../docs/AI_OPERATIONS_MANUAL.md" /usr/local/lib/tarasec-operations/
if [[ ! -e /etc/tarasec/operations-agent.json ]]; then
    install -o root -g root -m 0600 "$source_dir/operations-agent.json.example" /etc/tarasec/operations-agent.json
fi
if [[ ! -e /etc/tarasec/operations-procedures.json ]]; then
    printf '{"procedures":{}}\n' > /etc/tarasec/operations-procedures.json
    chmod 0600 /etc/tarasec/operations-procedures.json
fi
cat > /etc/systemd/system/tarasec-operations-agent.service <<'EOF'
[Unit]
Description=TaraSec model-driven operations pilot
After=network-online.target
Wants=network-online.target
[Service]
Type=oneshot
UMask=0077
ExecStart=/usr/bin/python3 /usr/local/lib/tarasec-operations/operations_agent.py --tick
TimeoutStartSec=15min
EOF
cat > /etc/systemd/system/tarasec-operations-agent.timer <<'EOF'
[Unit]
Description=Resume TaraSec operations pilot
[Timer]
OnBootSec=2min
OnUnitInactiveSec=1min
[Install]
WantedBy=timers.target
EOF
# Record this deployment's source reference; this is not production release approval.
python3 - "$source_dir" <<'PYREF'
import hashlib, json, os, pathlib, re
source = pathlib.Path(__import__('sys').argv[1])
revision = os.environ.get('TARASEC_OPERATIONS_REVISION', '')
if not re.fullmatch('[0-9a-f]{40}', revision):
    revision = None
reporter = source / 'crontasks.pl'
reference = {'revision': revision, 'reference_kind': 'deployed_pilot_source',
    'reporter_sha256': hashlib.sha256(reporter.read_bytes()).hexdigest() if reporter.is_file() else None}
path = pathlib.Path('/usr/local/lib/tarasec-operations/deployment-reference.json')
path.write_text(json.dumps(reference))
path.chmod(0o644)
PYREF
python3 "$source_dir/configure_log_retention.py"
install -o root -g root -m 0755 "$source_dir/server_ai_report_client.py" /usr/local/lib/tarasec-operations/
cat > /usr/local/lib/tarasec-operations/run-server-ai-report <<'EOF'
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
chmod 0755 /usr/local/lib/tarasec-operations/run-server-ai-report
cat > /etc/systemd/system/tarasec-server-ai-report.service <<'EOF'
[Unit]
Description=Submit TaraSec disk and memory report for advisory analysis
Wants=network-online.target
After=network-online.target
ConditionPathExists=|/etc/tarasec/agent-enrollment.json
ConditionPathExists=|/etc/tarasec/server-ai-report.env

[Service]
Type=oneshot
EnvironmentFile=-/etc/tarasec/server-ai-report.env
ExecStart=/usr/local/lib/tarasec-operations/run-server-ai-report
TimeoutStartSec=6min
UMask=0077
NoNewPrivileges=true
ProtectSystem=strict
PrivateTmp=true
EOF
cat > /etc/systemd/system/tarasec-server-ai-report.timer <<'EOF'
[Unit]
Description=Schedule TaraSec disk and memory reports

[Timer]
OnCalendar=hourly
RandomizedDelaySec=5min
Persistent=true

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now tarasec-server-ai-report.timer
echo 'Agent files installed; existing timer state retained. The standard upgrader next checks the model connection and activates the agent.'
echo 'See docs/AI_OPERATIONS_MANUAL.md for pilot enrollment and activity-probe contract.'
