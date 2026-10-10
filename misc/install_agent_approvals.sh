#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
root="$(cd "$(dirname "$0")" && pwd)"
command -v openssl >/dev/null || { echo "Install openssl for node identity generation" >&2; exit 1; }
grep -Eq '^AGENT_PUBLIC_NICKNAME=' /etc/tarasecfw.conf || {
    printf 'AGENT_PUBLIC_NICKNAME="Node %s"\n' "$(openssl rand -hex 4)" >> /etc/tarasecfw.conf
}
install -d -o root -g root -m 0755 /usr/local/lib/tarasec
install -d -o root -g root -m 0750 /var/log/tarasec
install -o root -g root -m 0755 "$root/ssh_security_evidence.py" /usr/local/lib/tarasec/
install -o root -g root -m 0755 "$root/agent_approval_worker.py" /usr/local/lib/tarasec/
install -o root -g root -m 0755 "$root/node_enrollment.py" /usr/local/lib/tarasec/
command -v openssl >/dev/null || { echo "Install openssl for node identity generation" >&2; exit 1; }
install -o root -g root -m 0644 "$root/server-manager-prompt.md" /usr/local/lib/tarasec/
install -o root -g root -m 0644 "$root/server-manager-knowledge.md" /usr/local/lib/tarasec/
if [[ ! -e /etc/tarasec-server-manager.conf ]]; then
    install -o root -g root -m 0600 "$root/tarasec-server-manager.conf.example" /etc/tarasec-server-manager.conf
    echo "Installed conservative /etc/tarasec-server-manager.conf; review it before enabling containment."
fi
install -o root -g root -m 0644 "$root/systemd/tarasec-agent-approvals.service" /etc/systemd/system/
install -o root -g root -m 0644 "$root/systemd/tarasec-agent-approvals.timer" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now tarasec-agent-approvals.timer
echo "Agent timer installed; check journalctl -u tarasec-agent-approvals.service"
echo "New nodes register automatically; approve the matching fingerprint in the central operator page."

echo "Next: review and verify your node security at https://tarasec.org/safety/#deployment"
echo "Hardening priorities: https://tarasec.org/safety/#hardening-priorities"
echo "Ask your AI assistant to check the next missing control, test it and fix in-scope routine failures."
