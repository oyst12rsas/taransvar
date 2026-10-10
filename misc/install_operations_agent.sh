#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root'; exit 1; }
source_dir="$(cd "$(dirname "$0")" && pwd)"
install -d -o root -g root -m 0755 /usr/local/lib/tarasec-operations
install -d -o root -g root -m 0700 /etc/tarasec /var/lib/tarasec-operations
install -o root -g root -m 0755 "$source_dir/operations_agent.py" "$source_dir/operations_activity.py" "$source_dir/operations_diagnostics.py" "$source_dir/operations_actions.py" "$source_dir/operations_prompt.py" "$source_dir/operations_model_probe.py" "$source_dir/operations_collectors.py" "$source_dir/operations_model.py" "$source_dir/install_operations_reporter_bridge.py" "$source_dir/operations_schedule.py" "$source_dir/operations_reporting.py" "$source_dir/operations_deployment.py" "$source_dir/operations_tools.py" "$source_dir/configure_operations_journal.py" "$source_dir/configure_operations_direct_probe.py" /usr/local/lib/tarasec-operations/
cat > /usr/local/lib/tarasec-operations/activity-probe <<'EOF'
#!/bin/sh
exec /usr/bin/python3 /usr/local/lib/tarasec-operations/operations_activity.py --read
EOF
chmod 0755 /usr/local/lib/tarasec-operations/activity-probe
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
systemctl daemon-reload
echo 'Installed, not enabled. Configure and test the dedicated model connection first.'
echo 'See docs/AI_OPERATIONS_MANUAL.md for pilot enrollment and activity-probe contract.'
