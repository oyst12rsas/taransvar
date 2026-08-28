#!/bin/bash
set -euo pipefail

CONF="${1:-/etc/tarasec.conf}"
REPO_DIR="${TARASEC_REPO_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
HONEYPOT_SRC="$REPO_DIR/misc/tarasec_ssh_honeypot.py"
SERVICE_SRC="$REPO_DIR/misc/tarasec-ssh-honeypot.service"
SSHD_DROPIN_DIR="/etc/ssh/sshd_config.d"
SSHD_DROPIN="$SSHD_DROPIN_DIR/90-tarasec.conf"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0 [$CONF]" >&2
    exit 1
fi

if [ ! -r "$CONF" ]; then
    echo "Missing owner configuration: $CONF" >&2
    exit 1
fi

# shellcheck disable=SC1090
source "$CONF"

SSH_PORT="${SSH_PORT:-48222}"
SSH_HONEYPOT="${SSH_HONEYPOT:-on}"
SSH_HONEYPOT_PORT="${SSH_HONEYPOT_PORT:-22}"

case "$SSH_PORT" in
    ''|*[!0-9]*) echo "Invalid SSH_PORT=$SSH_PORT" >&2; exit 1 ;;
esac
case "$SSH_HONEYPOT_PORT" in
    ''|*[!0-9]*) echo "Invalid SSH_HONEYPOT_PORT=$SSH_HONEYPOT_PORT" >&2; exit 1 ;;
esac
if [ "$SSH_PORT" -lt 1 ] || [ "$SSH_PORT" -gt 65535 ]; then
    echo "SSH_PORT must be 1..65535" >&2
    exit 1
fi
if [ "$SSH_HONEYPOT_PORT" -lt 1 ] || [ "$SSH_HONEYPOT_PORT" -gt 65535 ]; then
    echo "SSH_HONEYPOT_PORT must be 1..65535" >&2
    exit 1
fi
if [ "$SSH_HONEYPOT" = "on" ] && [ "$SSH_PORT" -eq "$SSH_HONEYPOT_PORT" ]; then
    echo "Real SSH and honeypot cannot use the same port ($SSH_PORT)." >&2
    exit 1
fi

if ! command -v sshd >/dev/null 2>&1; then
    echo "OpenSSH server is not installed." >&2
    exit 1
fi

mkdir -p "$SSHD_DROPIN_DIR"
cat > "$SSHD_DROPIN" <<EOF
# Managed by TaraSec. Owner policy remains in $CONF.
Port $SSH_PORT
EOF

if ! sshd -t; then
    echo "sshd configuration test failed; removing TaraSec drop-in." >&2
    rm -f "$SSHD_DROPIN"
    exit 1
fi

echo "Real SSH configured for TCP/$SSH_PORT."

install -d -m 0755 /usr/local/lib/tarasec
install -m 0755 "$HONEYPOT_SRC" /usr/local/lib/tarasec/tarasec_ssh_honeypot.py
install -m 0644 "$SERVICE_SRC" /etc/systemd/system/tarasec-ssh-honeypot.service

# Put the configured decoy port into the service without editing the Python file.
mkdir -p /etc/systemd/system/tarasec-ssh-honeypot.service.d
cat > /etc/systemd/system/tarasec-ssh-honeypot.service.d/10-port.conf <<EOF
[Service]
Environment=TARASEC_SSH_HONEYPOT_PORT=$SSH_HONEYPOT_PORT
EOF

systemctl daemon-reload

# Reload/restart ssh only after sshd -t succeeded. Existing sessions normally
# survive a daemon restart, but the operator must use SSH_PORT for new sessions.
if systemctl list-unit-files ssh.service >/dev/null 2>&1; then
    systemctl restart ssh.service
elif systemctl list-unit-files sshd.service >/dev/null 2>&1; then
    systemctl restart sshd.service
else
    echo "Could not identify ssh.service/sshd.service." >&2
    exit 1
fi

case "${SSH_HONEYPOT,,}" in
    1|yes|true|on)
        systemctl enable --now tarasec-ssh-honeypot.service
        echo "TaraSec SSH honeypot enabled on TCP/$SSH_HONEYPOT_PORT."
        ;;
    *)
        systemctl disable --now tarasec-ssh-honeypot.service 2>/dev/null || true
        echo "TaraSec SSH honeypot disabled by owner policy."
        ;;
esac

echo
echo "SSH policy applied."
echo "  Real SSH: TCP/$SSH_PORT"
echo "  Honeypot:  $SSH_HONEYPOT on TCP/$SSH_HONEYPOT_PORT"
echo "Apply misc/firewall.sh as well so source restrictions match /etc/tarasec.conf."
