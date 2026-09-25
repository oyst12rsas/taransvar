#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
root="$(cd "$(dirname "$0")" && pwd)"
test -r /etc/tarasec/agent-node.token || { echo "Missing /etc/tarasec/agent-node.token" >&2; exit 1; }
grep -Eq '^AGENT_PUBLIC_NICKNAME=' /etc/tarasecfw.conf || {
    echo "Missing AGENT_PUBLIC_NICKNAME in /etc/tarasecfw.conf" >&2; exit 1;
}
install -d -o root -g root -m 0755 /usr/local/lib/tarasec
install -o root -g root -m 0755 "$root/ssh_security_evidence.py" /usr/local/lib/tarasec/
install -o root -g root -m 0755 "$root/agent_approval_worker.py" /usr/local/lib/tarasec/
install -o root -g root -m 0644 "$root/systemd/tarasec-agent-approvals.service" /etc/systemd/system/
install -o root -g root -m 0644 "$root/systemd/tarasec-agent-approvals.timer" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now tarasec-agent-approvals.timer
echo "Agent timer installed; check journalctl -u tarasec-agent-approvals.service"
