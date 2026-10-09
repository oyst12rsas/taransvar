#!/bin/bash
set -euo pipefail
[[ $(id -u) == 0 ]] || { echo 'Run with sudo'; exit 1; }
src=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
install -d -o root -g root -m 0755 /usr/local/lib/tarasec
install -o root -g root -m 0755 "$src/manager_ssh.py" /usr/local/lib/tarasec/manager_ssh.py
sudoers=$(mktemp)
trap 'rm -f "$sudoers"' EXIT
cat > "$sudoers" <<'RULE'
www-data ALL=(root) NOPASSWD: /usr/local/lib/tarasec/manager_ssh.py status *, /usr/local/lib/tarasec/manager_ssh.py open *
RULE
visudo -cf "$sudoers"
install -o root -g root -m 0440 "$sudoers" /etc/sudoers.d/tarasec-manager-ssh
echo 'Installed. Owner opt-in: SSH_MANAGER_TIMED_OPEN=on in /etc/tarasecfw.conf.'
echo 'Requires a closed TaraSec IPv4 SSH barrier and a live administrative sshd listener.'
