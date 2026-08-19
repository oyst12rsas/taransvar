#!/bin/bash
set -euo pipefail

usage() {
    echo "Usage:"
    echo "  sudo $0 db"
    echo "  sudo $0 sensor <DB_SERVER>"
    exit 1
}

[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
[[ $# -ge 1 ]] || usage

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROLE="$1"

install_rsyslog_conf() {
    local src="$1"
    local dst="$2"
    install -m 0644 "$src" "$dst"
    rsyslogd -N1
    systemctl restart rsyslog
}

case "$ROLE" in
    db)
        install_rsyslog_conf \
            "$SCRIPT_DIR/rsyslog/30-tarasec-db-receiver.conf.example" \
            "/etc/rsyslog.d/30-tarasec-db-receiver.conf"

        install -m 0644 "$SCRIPT_DIR/systemd/tarasec-ai.service" /etc/systemd/system/tarasec-ai.service
        install -m 0644 "$SCRIPT_DIR/systemd/tarasec-ai.timer" /etc/systemd/system/tarasec-ai.timer
        systemctl daemon-reload
        systemctl enable --now tarasec-ai.timer

        echo "Installed DB-server telemetry receiver on TCP/5514 and tarasec-ai.timer."
        echo "Check: systemctl status rsyslog tarasec-ai.timer --no-pager"
        echo "Next AI run: systemctl list-timers tarasec-ai.timer --no-pager"
        ;;

    sensor)
        [[ $# -eq 2 ]] || usage
        DB_SERVER="$2"
        TMP="$(mktemp)"
        trap 'rm -f "$TMP"' EXIT
        sed "s/DB_SERVER/${DB_SERVER//\//\\/}/g" \
            "$SCRIPT_DIR/rsyslog/30-tarasec-forward.conf.example" > "$TMP"
        install_rsyslog_conf "$TMP" /etc/rsyslog.d/30-tarasec-forward.conf
        echo "Installed reliable TaraSec telemetry forwarding to ${DB_SERVER}:5514."
        echo "Check: logger 'TARASEC_TEST SRC=192.0.2.1 DST=198.51.100.1 PROTO=TCP SPT=44444 DPT=22 DROP'"
        ;;

    *) usage ;;
esac
