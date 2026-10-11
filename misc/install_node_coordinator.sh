#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 && $# -eq 1 ]] || { echo "Usage: sudo bash install_node_coordinator.sh ABSOLUTE_REPORTER_PATH"; exit 1; }
source_dir="$(cd "$(dirname "$0")" && pwd)"
reporter="$1"
for helper in operations_agent.py; do
    test -f "/usr/local/lib/tarasec-operations/$helper"
done
test -f /usr/local/lib/tarasec/agent_approval_worker.py
test -f /usr/local/lib/tarasec/ssh_google_window.py
install -d -o root -g root -m 0755 /usr/local/lib/tarasec
install -d -o root -g root -m 0700 /etc/tarasec
backup=$(mktemp -d /var/lib/tarasec-coordinator-backup.XXXXXX)
chmod 0700 "$backup"
crontab -l > "$backup/root.cron"
python3 - "$source_dir" "$reporter" "$backup" <<'PY'
import json, re, sys
from pathlib import Path
sys.path.insert(0, sys.argv[1])
from coordinator_reporter import trusted
reporter = Path(sys.argv[2])
trusted(reporter)
if not reporter.is_absolute() or reporter.name != 'crontasks.pl':
    raise SystemExit('Expected absolute crontasks.pl path')
config = Path('/etc/tarasec/node-coordinator.json')
payload = {'reporter': str(reporter)}
if config.exists() and json.loads(config.read_text()) != payload:
    raise SystemExit('Existing coordinator configuration differs; stopping')
config.write_text(json.dumps(payload) + '\n')
config.chmod(0o600)
backup = Path(sys.argv[3])
lines = (backup/'root.cron').read_text().splitlines(keepends=True)
removed = [line for line in lines if re.match(r'^\s*\*\s+\*\s+\*\s+\*\s+\*\s+', line)
           and str(reporter) in line and re.search(r'\bcron\b', line)]
other = [line for line in lines if not line.lstrip().startswith('#')
         and 'crontasks.pl' in line and line not in removed]
if other:
    raise SystemExit('Unrecognized reporter cron entry; stopping without changing cron')
(backup/'new.cron').write_text(''.join(line for line in lines if line not in removed))
print('Minute cron entries to replace:', len(removed))
PY
install -o root -g root -m 0755 "$source_dir/node_coordinator.py" "$source_dir/coordinator_reporter.py" "$source_dir/ssh_approval_poll.py" /usr/local/lib/tarasec/
cp -a /usr/local/lib/tarasec-operations/operations_deployment.py "$backup/operations_deployment.py"
install -o root -g root -m 0755 "$source_dir/operations_deployment.py" /usr/local/lib/tarasec-operations/
install -o root -g root -m 0644 "$source_dir/systemd/tarasec-node-coordinator.service" "$source_dir/systemd/tarasec-minute-reporter.service" "$source_dir/systemd/tarasec-ssh-approval-poll.service" /etc/systemd/system/
timers=(tarasec-operations-agent.timer tarasec-agent-approvals.timer tarasec-ssh-approval-poll.timer)
for unit in "${timers[@]}"; do
    if systemctl is-enabled --quiet "$unit" 2>/dev/null; then echo "$unit" >> "$backup/enabled-timers"; fi
    if systemctl is-active --quiet "$unit"; then echo "$unit" >> "$backup/active-timers"; fi
done
systemctl daemon-reload
crontab "$backup/new.cron"
for unit in "${timers[@]}"; do systemctl disable --now "$unit" 2>/dev/null || true; done
if ! systemctl enable --now tarasec-node-coordinator.service; then
    systemctl disable --now tarasec-node-coordinator.service || true
    crontab "$backup/root.cron"
    if [[ -f "$backup/enabled-timers" ]]; then while read -r unit; do systemctl enable "$unit"; done < "$backup/enabled-timers"; fi
    if [[ -f "$backup/active-timers" ]]; then while read -r unit; do systemctl start "$unit"; done < "$backup/active-timers"; fi
    echo "Coordinator startup failed; scheduling restored. Backup: $backup"
    exit 1
fi
echo "Coordinator installed. Recovery backup: $backup"
echo "Verify central receipt and five-second SSH approval delivery. SSH expiry timers and activity observer remain independent."
