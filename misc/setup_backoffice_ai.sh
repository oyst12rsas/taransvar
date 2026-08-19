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

install_unit() {
    local name="$1"
    install -m 0644 "$SCRIPT_DIR/systemd/$name" "/etc/systemd/system/$name"
}

case "$ROLE" in
    db)
        install -d -m 0750 /var/log/tarasec
        install_rsyslog_conf \
            "$SCRIPT_DIR/rsyslog/30-tarasec-db-receiver.conf.example" \
            "/etc/rsyslog.d/30-tarasec-db-receiver.conf"

        install_unit tarasec-ai.service
        install_unit tarasec-ai.timer
        install_unit tarasec-ai-dispatch.service
        install_unit tarasec-ai-dispatch.timer
        install_unit tarasec-remote-normalizer.service

        systemctl daemon-reload
        systemctl enable --now tarasec-ai.timer
        systemctl enable --now tarasec-ai-dispatch.timer
        systemctl enable --now tarasec-remote-normalizer.service

        echo "Installed DB-server reliable telemetry receiver, normalizer and AI services."
        echo "TCP/5514 archive: /var/log/tarasec/remote.log"
        echo "Check: systemctl status rsyslog tarasec-remote-normalizer.service tarasec-ai.timer tarasec-ai-dispatch.timer --no-pager"
        echo "Timers: systemctl list-timers 'tarasec-ai*' --no-pager"
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
