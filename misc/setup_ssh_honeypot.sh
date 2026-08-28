#!/bin/bash
set -euo pipefail

CONF="${1:-/etc/tarasec.conf}"
REPO_DIR="${TARASEC_REPO_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
HONEYPOT_SRC="$REPO_DIR/misc/tarasec_ssh_honeypot.py"
SERVICE_SRC="$REPO_DIR/misc/tarasec-ssh-honeypot.service"
SSHD_DROPIN_DIR="/etc/ssh/sshd_config.d"
SSHD_DROPIN="$SSHD_DROPIN_DIR/90-tarasec.conf"
ROLLBACK_DIR="/var/lib/tarasec/ssh-rollback"
ROLLBACK_SCRIPT="/usr/local/lib/tarasec/ssh-rollback.sh"
ROLLBACK_SERVICE="tarasec-ssh-rollback.service"
ROLLBACK_TIMER="tarasec-ssh-rollback.timer"

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
SSH_FAILSAFE="${SSH_FAILSAFE:-on}"
SSH_FAILSAFE_MINUTES="${SSH_FAILSAFE_MINUTES:-10}"

case "$SSH_PORT" in ''|*[!0-9]*) echo "Invalid SSH_PORT=$SSH_PORT" >&2; exit 1 ;; esac
case "$SSH_HONEYPOT_PORT" in ''|*[!0-9]*) echo "Invalid SSH_HONEYPOT_PORT=$SSH_HONEYPOT_PORT" >&2; exit 1 ;; esac
case "$SSH_FAILSAFE_MINUTES" in ''|*[!0-9]*) echo "Invalid SSH_FAILSAFE_MINUTES=$SSH_FAILSAFE_MINUTES" >&2; exit 1 ;; esac
if [ "$SSH_PORT" -lt 1 ] || [ "$SSH_PORT" -gt 65535 ]; then echo "SSH_PORT must be 1..65535" >&2; exit 1; fi
if [ "$SSH_HONEYPOT_PORT" -lt 1 ] || [ "$SSH_HONEYPOT_PORT" -gt 65535 ]; then echo "SSH_HONEYPOT_PORT must be 1..65535" >&2; exit 1; fi
if [ "$SSH_FAILSAFE_MINUTES" -lt 1 ] || [ "$SSH_FAILSAFE_MINUTES" -gt 120 ]; then echo "SSH_FAILSAFE_MINUTES must be 1..120" >&2; exit 1; fi
if [ "${SSH_HONEYPOT,,}" = "on" ] && [ "$SSH_PORT" -eq "$SSH_HONEYPOT_PORT" ]; then
    echo "Real SSH and honeypot cannot use the same port ($SSH_PORT)." >&2
    exit 1
fi
if ! command -v sshd >/dev/null 2>&1; then echo "OpenSSH server is not installed." >&2; exit 1; fi

restart_sshd() {
    if systemctl list-unit-files ssh.service >/dev/null 2>&1; then
        systemctl restart ssh.service
    elif systemctl list-unit-files sshd.service >/dev/null 2>&1; then
        systemctl restart sshd.service
    else
        echo "Could not identify ssh.service/sshd.service." >&2
        return 1
    fi
}

is_on() { case "${1,,}" in 1|yes|true|on) return 0 ;; *) return 1 ;; esac; }

mkdir -p "$SSHD_DROPIN_DIR" "$ROLLBACK_DIR" /usr/local/lib/tarasec

# Snapshot both sshd and firewall state before touching a VPS management path.
if [ -f "$SSHD_DROPIN" ]; then
    cp -a "$SSHD_DROPIN" "$ROLLBACK_DIR/90-tarasec.conf.previous"
else
    rm -f "$ROLLBACK_DIR/90-tarasec.conf.previous"
fi
if command -v iptables-save >/dev/null 2>&1; then
    iptables-save > "$ROLLBACK_DIR/iptables.previous"
else
    rm -f "$ROLLBACK_DIR/iptables.previous"
fi
if command -v ip6tables-save >/dev/null 2>&1; then
    ip6tables-save > "$ROLLBACK_DIR/ip6tables.previous"
else
    rm -f "$ROLLBACK_DIR/ip6tables.previous"
fi

cat > "$ROLLBACK_SCRIPT" <<'EOF'
#!/bin/bash
set -e
DROPIN="/etc/ssh/sshd_config.d/90-tarasec.conf"
STATE="/var/lib/tarasec/ssh-rollback"

# Restore the firewall first so the recovery SSH path is available before sshd
# is restarted. Do not make an unavailable IPv6 restore prevent IPv4 recovery.
if [ -s "$STATE/iptables.previous" ] && command -v iptables-restore >/dev/null 2>&1; then
    iptables-restore < "$STATE/iptables.previous"
fi
if [ -s "$STATE/ip6tables.previous" ] && command -v ip6tables-restore >/dev/null 2>&1; then
    ip6tables-restore < "$STATE/ip6tables.previous" || true
fi

if [ -f "$STATE/90-tarasec.conf.previous" ]; then
    cp -a "$STATE/90-tarasec.conf.previous" "$DROPIN"
else
    rm -f "$DROPIN"
fi
sshd -t
if systemctl list-unit-files ssh.service >/dev/null 2>&1; then
    systemctl restart ssh.service
else
    systemctl restart sshd.service
fi
logger -t tarasec "TARASEC_SSH_ROLLBACK restored previous firewall and sshd configuration"
EOF
chmod 0755 "$ROLLBACK_SCRIPT"

cat > "/etc/systemd/system/$ROLLBACK_SERVICE" <<EOF
[Unit]
Description=TaraSec SSH and firewall migration rollback

[Service]
Type=oneshot
ExecStart=$ROLLBACK_SCRIPT
EOF
cat > "/etc/systemd/system/$ROLLBACK_TIMER" <<EOF
[Unit]
Description=TaraSec SSH and firewall migration rollback timer

[Timer]
OnActiveSec=${SSH_FAILSAFE_MINUTES}min
Unit=$ROLLBACK_SERVICE
AccuracySec=5s

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
if is_on "$SSH_FAILSAFE"; then
    systemctl stop "$ROLLBACK_TIMER" 2>/dev/null || true
    systemctl start "$ROLLBACK_TIMER"
    echo "Failsafe rollback armed for ${SSH_FAILSAFE_MINUTES} minutes."
fi

# Phase 1: keep the old management port while adding the new real SSH port.
cat > "$SSHD_DROPIN" <<EOF
# Managed by TaraSec. Owner policy remains in $CONF.
Port $SSH_HONEYPOT_PORT
Port $SSH_PORT
EOF
if ! sshd -t; then echo "Two-port sshd configuration test failed; rollback remains armed." >&2; exit 1; fi
restart_sshd
if ! ss -ltn | awk '{print $4}' | grep -Eq "[:.]$SSH_PORT$"; then
    echo "New SSH port $SSH_PORT is not listening; rollback remains armed." >&2
    exit 1
fi
echo "Phase 1 OK: sshd is listening on both TCP/$SSH_HONEYPOT_PORT and TCP/$SSH_PORT."

# Phase 2: remove sshd from the future honeypot port only after local validation.
cat > "$SSHD_DROPIN" <<EOF
# Managed by TaraSec. Owner policy remains in $CONF.
Port $SSH_PORT
EOF
if ! sshd -t; then echo "Final sshd configuration test failed; rollback remains armed." >&2; exit 1; fi
restart_sshd
if ! ss -ltn | awk '{print $4}' | grep -Eq "[:.]$SSH_PORT$"; then
    echo "Final SSH port $SSH_PORT is not listening; rollback remains armed." >&2
    exit 1
fi
echo "Real SSH configured for TCP/$SSH_PORT."

install -m 0755 "$HONEYPOT_SRC" /usr/local/lib/tarasec/tarasec_ssh_honeypot.py
install -m 0644 "$SERVICE_SRC" /etc/systemd/system/tarasec-ssh-honeypot.service
mkdir -p /etc/systemd/system/tarasec-ssh-honeypot.service.d
cat > /etc/systemd/system/tarasec-ssh-honeypot.service.d/10-port.conf <<EOF
[Service]
Environment=TARASEC_SSH_HONEYPOT_PORT=$SSH_HONEYPOT_PORT
EOF
systemctl daemon-reload

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

if is_on "$SSH_FAILSAFE"; then
    echo "IMPORTANT: SSH/firewall rollback is still ARMED."
    echo "Apply the TaraSec firewall, then confirm a NEW SSH connection on TCP/$SSH_PORT."
    echo "Only after successful reconnection cancel rollback with:"
    echo "  systemctl stop $ROLLBACK_TIMER"
else
    echo "WARNING: SSH_FAILSAFE is disabled by owner policy."
fi

echo
echo "SSH policy staged."
echo "  Real SSH: TCP/$SSH_PORT"
echo "  Honeypot:  $SSH_HONEYPOT on TCP/$SSH_HONEYPOT_PORT"
echo "  Rollback:  sshd + IPv4/IPv6 firewall snapshot"
