#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
root="$(cd "$(dirname "$0")" && pwd)"
# Enrollment is an owner action; unattended upgrades retain their existing policy.
enroll=false
skip_enrollment=false
enroll_args=()
gatekeeper_args=()
gatekeeper_web_root=/var/www/html
while [[ $# -gt 0 ]]; do
    case "$1" in
        --gatekeeper-url|--web-root)
            [[ $# -ge 2 ]] || { echo "Missing value for $1" >&2; exit 1; }
            enroll=true; gatekeeper_args+=("$1" "$2");
            if [[ $1 == --web-root ]]; then gatekeeper_web_root=$2; fi
            shift 2 ;;
        --enroll-google) enroll=true; shift ;;
        --email|--approval-url|--nickname)
            [[ $# -ge 2 ]] || { echo "Missing value for $1" >&2; exit 1; }
            enroll=true; enroll_args+=("$1" "$2"); shift 2 ;;
        --sync) enroll=true; enroll_args+=("--sync"); shift ;;
        --no-enrollment) skip_enrollment=true; shift ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done
if ! $enroll && ! $skip_enrollment && [[ -t 0 ]]; then
    read -r -p 'Google manager email (Enter to keep current enrollment): ' manager_email
    if [[ -n $manager_email ]]; then enroll=true; enroll_args+=(--email "$manager_email"); fi
fi
# Self-enrollment creates a unique node credential if absent and authenticates
# updates using the existing credential. It never closes SSH or changes grants
# on another node. Failed enrollment leaves identity intact for a safe retry.
if $enroll; then
    python3 "$root/enroll_google_manager.py" "${enroll_args[@]}"
    # Explicit manager enrollment also enables local Gatekeeper Google sign-in.
    # The helper skips nodes without Gatekeeper and retains legacy client config.
    if ! command -v setfacl >/dev/null; then apt-get install -y acl; fi
    if [[ -f "$gatekeeper_web_root/gatekeeper/google_callback.php" ]]; then
        # Deploy the local-manager authorization path before activating its config.
        # Compiled misc-only bundles must deploy html/ separately first.
        if [[ -d "$root/../html/gatekeeper" ]]; then
            for file in google_start.php google_callback.php google_local_managers.php; do
                php -l "$root/../html/gatekeeper/$file" >/dev/null
                install -o root -g root -m 0644 "$root/../html/gatekeeper/$file" "$gatekeeper_web_root/gatekeeper/$file"
            done
        fi
        grep -q 'gatekeeperLocalManager' "$gatekeeper_web_root/gatekeeper/google_callback.php" || {
            echo 'Deploy current html/gatekeeper/google_*.php before Gatekeeper enrollment.' >&2; exit 1;
        }
    fi
    python3 "$root/enroll_gatekeeper_google.py" "${gatekeeper_args[@]}"
fi
test -r /etc/tarasec/agent-node.token || {
    echo "No node identity. Run this installer with --enroll-google." >&2; exit 1;
}
grep -Eq '^AGENT_PUBLIC_NICKNAME=' /etc/tarasecfw.conf || {
    echo "Missing AGENT_PUBLIC_NICKNAME in /etc/tarasecfw.conf" >&2; exit 1;
}
# Validate the complete bundle before stopping or replacing any installed worker.
for file in agent_approval_worker.py ssh_google_window.py ssh_security_evidence.py ssh_approval_poll.py local_manager_policy.py enroll_google_manager.py enroll_gatekeeper_google.py; do
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
for path in /usr/local/lib/tarasec /etc/tarasec-server-manager.conf /etc/tarasec/access.conf; do
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
install -o root -g root -m 0755 "$root/agent_approval_worker.py" "$root/ssh_google_window.py" "$root/ssh_approval_poll.py" "$root/local_manager_policy.py" "$root/enroll_google_manager.py" "$root/enroll_gatekeeper_google.py" /usr/local/lib/tarasec/
# Without enrollment, preserve existing grants and never infer a manager roster.
# Explicit enrollment publishes hashes only; raw emails remain on the node.
# /etc/tarasec is shared with Gatekeeper: preserve existing permissions/ACLs.
# Resetting it to 0700 masks Apache traversal access and hides Google sign-in.
# New directories remain private; web-readable configuration is set up separately.
if [[ ! -d /etc/tarasec ]]; then
    install -d -o root -g root -m 0700 /etc/tarasec
fi
if [[ ! -e /etc/tarasec/access.conf ]]; then
    install -o root -g root -m 0600 "$root/access.conf.example" /etc/tarasec/access.conf
    echo 'Configure local manager emails/hashes in /etc/tarasec/access.conf before accepting approvals.'
fi
install -o root -g root -m 0644 "$root/server-manager-prompt.md" /usr/local/lib/tarasec/
install -o root -g root -m 0644 "$root/server-manager-knowledge.md" /usr/local/lib/tarasec/
if [[ ! -e /etc/tarasec-server-manager.conf ]]; then
    install -o root -g root -m 0600 "$root/tarasec-server-manager.conf.example" /etc/tarasec-server-manager.conf
    echo "Installed conservative /etc/tarasec-server-manager.conf; review it before enabling containment."
fi
# AI_AGENT_MODE was an assessment label, never an execution/reboot permission.
# Remove obsolete assignments after the policy backup above; preserve real flags.
python3 - <<'CLEAN_POLICY'
from pathlib import Path
import re
path = Path('/etc/tarasec-server-manager.conf')
text = path.read_text()
updated = re.sub(r'^\s*AI_AGENT_MODE\s*=[^\n]*(?:\n|$)', '', text, flags=re.M)
if updated != text:
    path.write_text(updated)
CLEAN_POLICY
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
