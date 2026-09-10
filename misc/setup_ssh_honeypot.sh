#!/bin/bash
set -euo pipefail

CONF="${1:-/etc/tarasecfw.conf}"
REPO_DIR="${TARASEC_REPO_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
HONEYPOT_SRC="$REPO_DIR/misc/tarasec_ssh_honeypot.py"
SERVICE_SRC="$REPO_DIR/misc/tarasec-ssh-honeypot.service"
SSHD_DROPIN_DIR="/etc/ssh/sshd_config.d"
SSHD_DROPIN="$SSHD_DROPIN_DIR/90-tarasec.conf"
ROLLBACK_DIR="/var/lib/tarasec/ssh-rollback"
ROLLBACK_SCRIPT="/usr/local/lib/tarasec/ssh-rollback.sh"
ROLLBACK_SERVICE="tarasec-ssh-rollback.service"
ROLLBACK_TIMER="tarasec-ssh-rollback.timer"
HONEYPOT_SERVICE="tarasec-ssh-honeypot.service"

if [ "$(id -u)" -ne 0 ]; then echo "Run as root: sudo bash $0 [$CONF]" >&2; exit 1; fi
if [ ! -r "$CONF" ]; then echo "Missing firewall configuration: $CONF" >&2; exit 1; fi

# shellcheck disable=SC1090
source "$CONF"
SSH_PORT="${SSH_PORT:-48222}"
SSH_HONEYPOT="${SSH_HONEYPOT:-on}"
SSH_HONEYPOT_PORT="${SSH_HONEYPOT_PORT:-22}"
SSH_HONEYPOT_PORTS="${SSH_HONEYPOT_PORTS:-$SSH_HONEYPOT_PORT}"
SSH_HONEYPOT_AUTH_MODE="${SSH_HONEYPOT_AUTH_MODE:-accept-all}"
SSH_HONEYPOT_PASSWORD_HASH="${SSH_HONEYPOT_PASSWORD_HASH:-}"
SSH_HONEYPOT_DEMO_PORT="${SSH_HONEYPOT_DEMO_PORT:-0}"
SSH_HONEYPOT_DEMO_DB_URL="${SSH_HONEYPOT_DEMO_DB_URL:-}"
SSH_HONEYPOT_DEMO_NODE_TOKEN="${SSH_HONEYPOT_DEMO_NODE_TOKEN:-}"
SSH_FAILSAFE="${SSH_FAILSAFE:-on}"
SSH_FAILSAFE_MINUTES="${SSH_FAILSAFE_MINUTES:-10}"

case "$SSH_PORT" in ''|*[!0-9]*) echo "Invalid SSH_PORT=$SSH_PORT" >&2; exit 1 ;; esac
case "$SSH_HONEYPOT_PORT" in ''|*[!0-9]*) echo "Invalid SSH_HONEYPOT_PORT=$SSH_HONEYPOT_PORT" >&2; exit 1 ;; esac
case "$SSH_FAILSAFE_MINUTES" in ''|*[!0-9]*) echo "Invalid SSH_FAILSAFE_MINUTES=$SSH_FAILSAFE_MINUTES" >&2; exit 1 ;; esac
if [ "$SSH_PORT" -lt 1 ] || [ "$SSH_PORT" -gt 65535 ]; then echo "SSH_PORT must be 1..65535" >&2; exit 1; fi
if [ "$SSH_HONEYPOT_PORT" -lt 1 ] || [ "$SSH_HONEYPOT_PORT" -gt 65535 ]; then echo "SSH_HONEYPOT_PORT must be 1..65535" >&2; exit 1; fi
if [ "$SSH_FAILSAFE_MINUTES" -lt 1 ] || [ "$SSH_FAILSAFE_MINUTES" -gt 120 ]; then echo "SSH_FAILSAFE_MINUTES must be 1..120" >&2; exit 1; fi
case "$SSH_HONEYPOT_AUTH_MODE" in
    accept-all|reject-all) ;;
    password)
        [[ "$SSH_HONEYPOT_PASSWORD_HASH" =~ ^[0-9A-Fa-f]{64}$ ]] || {
            echo "password mode requires SSH_HONEYPOT_PASSWORD_HASH as SHA-256" >&2; exit 1;
        }
        ;;
    *) echo "SSH_HONEYPOT_AUTH_MODE must be accept-all, reject-all or password" >&2; exit 1 ;;
esac

expand_honeypot_ports() {
    local spec="${SSH_HONEYPOT_PORTS// /,}" item first last port
    local -A seen=()
    SSH_HONEYPOT_PORT_LIST=()
    IFS=',' read -ra items <<< "$spec"
    for item in "${items[@]}"; do
        [ -z "$item" ] && continue
        if [[ "$item" =~ ^([0-9]+)-([0-9]+)$ ]]; then
            first=$((10#${BASH_REMATCH[1]})); last=$((10#${BASH_REMATCH[2]}))
            [ "$first" -le "$last" ] || { echo "Descending honeypot range: $item" >&2; exit 1; }
            for ((port=first; port<=last; port++)); do
                [ "$port" -le 65535 ] || { echo "Invalid honeypot port: $port" >&2; exit 1; }
                [ "$port" -ne "$SSH_PORT" ] || { echo "Honeypot port collides with real SSH: $port" >&2; exit 1; }
                if [ -z "${seen[$port]:-}" ]; then seen[$port]=1; SSH_HONEYPOT_PORT_LIST+=("$port"); fi
                [ "${#SSH_HONEYPOT_PORT_LIST[@]}" -le 64 ] || { echo "At most 64 honeypot ports are allowed" >&2; exit 1; }
            done
        elif [[ "$item" =~ ^[0-9]+$ ]] && [ "$((10#$item))" -ge 1 ] && [ "$((10#$item))" -le 65535 ]; then
            port=$((10#$item))
            [ "$port" -ne "$SSH_PORT" ] || { echo "Honeypot port collides with real SSH: $port" >&2; exit 1; }
            if [ -z "${seen[$port]:-}" ]; then seen[$port]=1; SSH_HONEYPOT_PORT_LIST+=("$port"); fi
            [ "${#SSH_HONEYPOT_PORT_LIST[@]}" -le 64 ] || { echo "At most 64 honeypot ports are allowed" >&2; exit 1; }
        else
            echo "Invalid SSH_HONEYPOT_PORTS entry: $item" >&2; exit 1
        fi
    done
    [ "${#SSH_HONEYPOT_PORT_LIST[@]}" -gt 0 ] || { echo "No honeypot ports configured" >&2; exit 1; }
}
expand_honeypot_ports
if [ "$SSH_HONEYPOT_DEMO_PORT" != "0" ]; then
    [[ "$SSH_HONEYPOT_DEMO_PORT" =~ ^[0-9]+$ ]] && [ "$SSH_HONEYPOT_DEMO_PORT" -ge 1 ] && [ "$SSH_HONEYPOT_DEMO_PORT" -le 65535 ] || { echo "Invalid SSH_HONEYPOT_DEMO_PORT" >&2; exit 1; }
    [[ " ${SSH_HONEYPOT_PORT_LIST[*]} " == *" $SSH_HONEYPOT_DEMO_PORT "* ]] || { echo "Demo port must be included in SSH_HONEYPOT_PORTS" >&2; exit 1; }
    # Plain HTTP is permitted only to a literal NetBird/CGNAT address.
    # This preserves the observed NetBird sender identity used for Demo 2
    # correlation without allowing credentials over arbitrary public HTTP.
    [[ "$SSH_HONEYPOT_DEMO_DB_URL" == https://* || "$SSH_HONEYPOT_DEMO_DB_URL" =~ ^http://100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.[0-9]{1,3}\.[0-9]{1,3}([/:]|$) ]] || {
        echo "Demo mode requires HTTPS or HTTP to a literal NetBird address in 100.64.0.0/10" >&2
        exit 1
    }
    [ "${#SSH_HONEYPOT_DEMO_NODE_TOKEN}" -ge 32 ] || { echo "Demo mode requires a node token of at least 32 characters" >&2; exit 1; }
fi
if ! command -v sshd >/dev/null 2>&1; then echo "OpenSSH server is not installed." >&2; exit 1; fi

# Ubuntu 22.10+ may use systemd ssh.socket activation. In that mode the
# socket generator derives ListenStream entries from sshd_config during
# daemon-reload, so merely restarting ssh.service leaves the old port bound.
restart_sshd() {
    if systemctl list-unit-files ssh.socket >/dev/null 2>&1 \
       && systemctl is-enabled --quiet ssh.socket 2>/dev/null; then
        systemctl daemon-reload
        systemctl stop ssh.service 2>/dev/null || true
        systemctl restart ssh.socket
        systemctl start ssh.service
    elif systemctl list-unit-files ssh.service >/dev/null 2>&1; then
        systemctl restart ssh.service
    elif systemctl list-unit-files sshd.service >/dev/null 2>&1; then
        systemctl restart sshd.service
    else
        echo "Could not identify ssh.socket/ssh.service/sshd.service." >&2
        return 1
    fi
}
is_on() { case "${1,,}" in 1|yes|true|on) return 0 ;; *) return 1 ;; esac; }

mkdir -p "$SSHD_DROPIN_DIR" "$ROLLBACK_DIR" /usr/local/lib/tarasec
if [ -f "$SSHD_DROPIN" ]; then
    # A previous interrupted run may have left our temporary two-port sshd
    # phase in place. Never promote that transitional file to the known-good
    # rollback state: doing so can restore OpenSSH onto the honeypot port and
    # prevent the simulator from starting after a reboot.
    if grep -Fxq "Port $SSH_HONEYPOT_PORT" "$SSHD_DROPIN" \
       && grep -Fxq "Port $SSH_PORT" "$SSHD_DROPIN" \
       && grep -Fq 'Managed by TaraSec' "$SSHD_DROPIN"; then
        echo "Existing two-port TaraSec migration state detected; preserving the earlier rollback snapshot."
    else
        cp -a "$SSHD_DROPIN" "$ROLLBACK_DIR/90-tarasec.conf.previous"
    fi
else
    rm -f "$ROLLBACK_DIR/90-tarasec.conf.previous"
fi
if command -v iptables-save >/dev/null 2>&1; then iptables-save > "$ROLLBACK_DIR/iptables.previous"; else rm -f "$ROLLBACK_DIR/iptables.previous"; fi
if command -v ip6tables-save >/dev/null 2>&1; then ip6tables-save > "$ROLLBACK_DIR/ip6tables.previous"; else rm -f "$ROLLBACK_DIR/ip6tables.previous"; fi

# A rerun may start while the TaraSec honeypot already owns TCP/22.
# Snapshot that state and stop it before the temporary two-port sshd phase.
if systemctl is-active --quiet "$HONEYPOT_SERVICE" 2>/dev/null; then
    touch "$ROLLBACK_DIR/honeypot.was-active"
else
    rm -f "$ROLLBACK_DIR/honeypot.was-active"
fi
systemctl stop "$HONEYPOT_SERVICE" 2>/dev/null || true

cat > "$ROLLBACK_SCRIPT" <<'EOF'
#!/bin/bash
set -e
DROPIN="/etc/ssh/sshd_config.d/90-tarasec.conf"
STATE="/var/lib/tarasec/ssh-rollback"
HONEYPOT_SERVICE="tarasec-ssh-honeypot.service"
if [ -s "$STATE/iptables.previous" ] && command -v iptables-restore >/dev/null 2>&1; then iptables-restore < "$STATE/iptables.previous"; fi
if [ -s "$STATE/ip6tables.previous" ] && command -v ip6tables-restore >/dev/null 2>&1; then ip6tables-restore < "$STATE/ip6tables.previous" || true; fi
if [ -f "$STATE/90-tarasec.conf.previous" ]; then cp -a "$STATE/90-tarasec.conf.previous" "$DROPIN"; else rm -f "$DROPIN"; fi
sshd -t
if systemctl list-unit-files ssh.socket >/dev/null 2>&1 && systemctl is-enabled --quiet ssh.socket 2>/dev/null; then
    systemctl daemon-reload
    systemctl stop ssh.service 2>/dev/null || true
    systemctl restart ssh.socket
    systemctl start ssh.service
elif systemctl list-unit-files ssh.service >/dev/null 2>&1; then
    systemctl restart ssh.service
else
    systemctl restart sshd.service
fi
if [ -f "$STATE/honeypot.was-active" ]; then
    systemctl start "$HONEYPOT_SERVICE" 2>/dev/null || true
else
    systemctl stop "$HONEYPOT_SERVICE" 2>/dev/null || true
fi
logger -t tarasec "TARASEC_SSH_ROLLBACK restored previous firewall, sshd and honeypot state"
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
if is_on "$SSH_FAILSAFE"; then systemctl stop "$ROLLBACK_TIMER" 2>/dev/null || true; systemctl start "$ROLLBACK_TIMER"; echo "Failsafe rollback armed for ${SSH_FAILSAFE_MINUTES} minutes."; fi

cat > "$SSHD_DROPIN" <<EOF
# Managed by TaraSec. Firewall/SSH policy is in $CONF.
Port $SSH_HONEYPOT_PORT
Port $SSH_PORT
EOF
if ! sshd -t; then echo "Two-port sshd configuration test failed; rollback remains armed." >&2; exit 1; fi
restart_sshd
if ! ss -ltn | awk '{print $4}' | grep -Eq "[:.]$SSH_PORT$"; then echo "New SSH port $SSH_PORT is not listening; rollback remains armed." >&2; exit 1; fi
echo "Phase 1 OK: sshd is listening on both TCP/$SSH_HONEYPOT_PORT and TCP/$SSH_PORT."

cat > "$SSHD_DROPIN" <<EOF
# Managed by TaraSec. Firewall/SSH policy is in $CONF.
Port $SSH_PORT
EOF
if ! sshd -t; then echo "Final sshd configuration test failed; rollback remains armed." >&2; exit 1; fi
restart_sshd
if ! ss -ltn | awk '{print $4}' | grep -Eq "[:.]$SSH_PORT$"; then echo "Final SSH port $SSH_PORT is not listening; rollback remains armed." >&2; exit 1; fi
echo "Real SSH configured for TCP/$SSH_PORT."

if ! python3 -c 'import paramiko' >/dev/null 2>&1; then
    apt-get update
    apt-get install -y python3-paramiko
fi
install -d -m 0750 /var/lib/tarasec-ssh-honeypot
if [ ! -s /var/lib/tarasec-ssh-honeypot/ssh_host_ed25519_key ]; then
    ssh-keygen -q -t ed25519 -N '' -f /var/lib/tarasec-ssh-honeypot/ssh_host_ed25519_key
fi
install -m 0755 "$HONEYPOT_SRC" /usr/local/lib/tarasec/tarasec_ssh_honeypot.py
install -m 0644 "$SERVICE_SRC" /etc/systemd/system/tarasec-ssh-honeypot.service
mkdir -p /etc/systemd/system/tarasec-ssh-honeypot.service.d
cat > /etc/systemd/system/tarasec-ssh-honeypot.service.d/10-port.conf <<EOF
[Service]
Environment=TARASEC_SSH_HONEYPOT_PORT=$SSH_HONEYPOT_PORT
Environment="TARASEC_SSH_HONEYPOT_PORTS=$SSH_HONEYPOT_PORTS"
Environment=TARASEC_SSH_HONEYPOT_AUTH_MODE=$SSH_HONEYPOT_AUTH_MODE
Environment=TARASEC_SSH_HONEYPOT_PASSWORD_HASH=$SSH_HONEYPOT_PASSWORD_HASH
Environment=TARASEC_SSH_HONEYPOT_DEMO_PORT=$SSH_HONEYPOT_DEMO_PORT
Environment=TARASEC_SSH_HONEYPOT_DEMO_DB_URL=$SSH_HONEYPOT_DEMO_DB_URL
Environment=TARASEC_SSH_HONEYPOT_DEMO_NODE_TOKEN=$SSH_HONEYPOT_DEMO_NODE_TOKEN
EOF
systemctl daemon-reload
case "${SSH_HONEYPOT,,}" in
    1|yes|true|on) systemctl enable --now "$HONEYPOT_SERVICE"; echo "TaraSec SSH honeypot enabled on TCP ports: $SSH_HONEYPOT_PORTS." ;;
    *) systemctl disable --now "$HONEYPOT_SERVICE" 2>/dev/null || true; echo "TaraSec SSH honeypot disabled by firewall policy." ;;
esac

if [ -n "${DBSERVER:-}" ] && [ -r "$REPO_DIR/misc/setup_backoffice_ai.sh" ]; then
    bash "$REPO_DIR/misc/setup_backoffice_ai.sh" sensor "$DBSERVER"
    echo "Honeypot telemetry forwarding configured for DB server $DBSERVER (TCP/5514)."
else
    echo "WARNING: DBSERVER is unset; events remain in the local system journal only."
fi

if is_on "$SSH_FAILSAFE"; then
    echo "IMPORTANT: SSH/firewall rollback is still ARMED."
    echo "Apply the TaraSec firewall, then confirm a NEW SSH connection on TCP/$SSH_PORT."
    echo "Only after successful reconnection cancel rollback with: systemctl stop $ROLLBACK_TIMER"
else
    echo "WARNING: SSH_FAILSAFE is disabled by firewall policy."
fi

echo
echo "SSH policy staged from $CONF."
echo "  Real SSH: TCP/$SSH_PORT"
echo "  Honeypot:  $SSH_HONEYPOT on TCP ports $SSH_HONEYPOT_PORTS ($SSH_HONEYPOT_AUTH_MODE)"
echo "  Rollback:  sshd + IPv4/IPv6 firewall + honeypot state snapshot"
