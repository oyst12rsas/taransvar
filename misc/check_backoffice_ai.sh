#!/bin/bash
set -u

fail=0

check() {
    local label="$1"
    shift
    if "$@" >/dev/null 2>&1; then
        printf "OK   %s\n" "$label"
    else
        printf "FAIL %s\n" "$label"
        fail=1
    fi
}

echo "TaraSec backoffice telemetry / AI health"
check "rsyslog active" systemctl is-active --quiet rsyslog
check "TCP/5514 listening" sh -c "ss -ltn | grep -qE '[:.]5514[[:space:]]'"
check "tarasec-ai.timer active" systemctl is-active --quiet tarasec-ai.timer
check "tarasec-ai.service installed" systemctl cat tarasec-ai.service

if [[ -f /var/log/tarasec/remote.log ]]; then
    age=$(( $(date +%s) - $(stat -c %Y /var/log/tarasec/remote.log) ))
    echo "INFO remote telemetry archive age: ${age}s"
else
    echo "INFO /var/log/tarasec/remote.log not created yet"
fi

systemctl list-timers tarasec-ai.timer --no-pager 2>/dev/null || true

echo
echo "Useful checks:"
echo "  journalctl -u tarasec-ai.service -n 50 --no-pager"
echo "  tail -n 20 /var/log/tarasec/remote.log"
echo "  ss -lun | grep ':514 '        # taralink semantic receiver"
echo "  ss -ltn | grep ':5514 '       # reliable rsyslog receiver"

exit "$fail"
