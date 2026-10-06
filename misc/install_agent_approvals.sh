#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
root="$(cd "$(dirname "$0")" && pwd)"
test -r /etc/tarasec/agent-node.token || { echo "Missing /etc/tarasec/agent-node.token" >&2; exit 1; }
grep -Eq '^AGENT_PUBLIC_NICKNAME=' /etc/tarasecfw.conf || {
    echo "Missing AGENT_PUBLIC_NICKNAME in /etc/tarasecfw.conf" >&2; exit 1;
}
install -d -o root -g root -m 0755 /usr/local/lib/tarasec
install -d -o root -g root -m 0750 /var/log/tarasec
install -o root -g root -m 0755 "$root/ssh_security_evidence.py" /usr/local/lib/tarasec/
install -o root -g root -m 0755 "$root/agent_approval_worker.py" /usr/local/lib/tarasec/
install -o root -g root -m 0755 "$root/ssh_google_window.py" /usr/local/lib/tarasec/
install -o root -g root -m 0644 "$root/systemd/tarasec-ssh-google.service" /etc/systemd/system/
install -o root -g root -m 0644 "$root/server-manager-prompt.md" /usr/local/lib/tarasec/
install -o root -g root -m 0644 "$root/server-manager-knowledge.md" /usr/local/lib/tarasec/
if [[ ! -e /etc/tarasec-server-manager.conf ]]; then
    install -o root -g root -m 0600 "$root/tarasec-server-manager.conf.example" /etc/tarasec-server-manager.conf
    echo "Installed conservative /etc/tarasec-server-manager.conf; review it before enabling containment."
fi
install -o root -g root -m 0644 "$root/systemd/tarasec-agent-approvals.service" /etc/systemd/system/
install -o root -g root -m 0644 "$root/systemd/tarasec-agent-approvals.timer" /etc/systemd/system/
systemctl daemon-reload
if /usr/bin/python3 -c 'import sys; sys.path.insert(0, "/usr/local/lib/tarasec"); import ssh_google_window as w; sys.exit(0 if w.settings(w.POLICY).get("SSH_GOOGLE_REOPEN_ENABLED", "no").lower() in ("yes", "true", "on", "1") else 1)'; then
    for unit in ssh.service sshd.service ssh.socket; do
        install -d -m 0755 "/etc/systemd/system/$unit.d"
        cat > "/etc/systemd/system/$unit.d/tarasec-google-ssh.conf" <<'UNIT'
[Unit]
Requires=tarasec-ssh-google.service
After=tarasec-ssh-google.service
UNIT
    done
    systemctl daemon-reload
    systemctl enable --now tarasec-ssh-google.service
fi
systemctl enable --now tarasec-agent-approvals.timer
echo "Agent timer installed; check journalctl -u tarasec-agent-approvals.service"
