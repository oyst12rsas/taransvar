#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
root="$(cd "$(dirname "$0")" && pwd)"
test -r /etc/tarasec/agent-node.token || { echo "Missing /etc/tarasec/agent-node.token" >&2; exit 1; }
grep -Eq '^AGENT_PUBLIC_NICKNAME=' /etc/tarasecfw.conf || {
    echo "Missing AGENT_PUBLIC_NICKNAME in /etc/tarasecfw.conf" >&2; exit 1;
}
# Validate the complete bundle before stopping or replacing any installed worker.
for file in agent_approval_worker.py ssh_google_window.py ssh_security_evidence.py ssh_approval_poll.py; do
    python3 - "$root/$file" <<'CHECK'
from pathlib import Path
import sys
path = Path(sys.argv[1])
compile(path.read_text(), str(path), "exec")
CHECK
done
for tool in python3 iptables ip6tables systemd-run; do command -v "$tool" >/dev/null; done
backup=$(mktemp -d /var/backups/tarasec-agent-approvals.XXXXXX)
chmod 0700 "$backup"
for path in /usr/local/lib/tarasec /etc/tarasec-server-manager.conf; do
    [[ ! -e $path ]] || cp -a --parents "$path" "$backup/"
done
poll_was_active=false
systemctl is-active --quiet tarasec-ssh-approval-poll.timer && poll_was_active=true
was_active=false
systemctl is-active --quiet tarasec-agent-approvals.timer && was_active=true
restore_timer() {
    if $was_active; then systemctl start tarasec-agent-approvals.timer; fi
    if $poll_was_active; then systemctl start tarasec-ssh-approval-poll.timer; fi
}
trap restore_timer EXIT
systemctl stop tarasec-ssh-approval-poll.timer 2>/dev/null || true
systemctl stop tarasec-ssh-approval-poll.service 2>/dev/null || true
systemctl stop tarasec-agent-approvals.timer 2>/dev/null || true
systemctl stop tarasec-agent-approvals.service 2>/dev/null || true
install -d -o root -g root -m 0755 /usr/local/lib/tarasec
install -d -o root -g root -m 0750 /var/log/tarasec
install -o root -g root -m 0755 "$root/ssh_security_evidence.py" /usr/local/lib/tarasec/
install -o root -g root -m 0755 "$root/agent_approval_worker.py" "$root/ssh_google_window.py" "$root/ssh_approval_poll.py" /usr/local/lib/tarasec/
install -o root -g root -m 0644 "$root/server-manager-prompt.md" /usr/local/lib/tarasec/
install -o root -g root -m 0644 "$root/server-manager-knowledge.md" /usr/local/lib/tarasec/
if [[ ! -e /etc/tarasec-server-manager.conf ]]; then
    install -o root -g root -m 0600 "$root/tarasec-server-manager.conf.example" /etc/tarasec-server-manager.conf
    echo "Installed conservative /etc/tarasec-server-manager.conf; review it before enabling containment."
fi
install -o root -g root -m 0644 "$root/systemd/tarasec-agent-approvals.service" /etc/systemd/system/
install -o root -g root -m 0644 "$root/systemd/tarasec-agent-approvals.timer" /etc/systemd/system/
install -o root -g root -m 0644 "$root/systemd/tarasec-ssh-approval-poll.service" /etc/systemd/system/
install -o root -g root -m 0644 "$root/systemd/tarasec-ssh-approval-poll.timer" /etc/systemd/system/
systemctl daemon-reload
# A coordinator already owns scheduling on enrolled coordinator installations.
if systemctl is-enabled --quiet tarasec-node-coordinator.service 2>/dev/null; then
    was_active=false
    poll_was_active=false
    systemctl disable --now tarasec-ssh-approval-poll.timer 2>/dev/null || true
    echo "Coordinator scheduling retained; standalone approval timer not enabled."
else
    systemctl enable --now tarasec-agent-approvals.timer
    systemctl enable --now tarasec-ssh-approval-poll.timer
    echo "Approved jobs checked five seconds after each completed poll; assessments remain on the minute timer."
fi
echo "Agent timer installed; check journalctl -u tarasec-agent-approvals.service"

echo "Next: review and verify your node security at https://tarasec.org/safety/#deployment"
echo "Hardening priorities: https://tarasec.org/safety/#hardening-priorities"
echo "Ask your AI assistant to check the next missing control, test it and fix in-scope routine failures."

printf "Program backup: %s\n" "$backup"
echo "Google SSH remains owner-configured; see docs/google-ssh.md before enabling. Existing enabled policy is retained."
