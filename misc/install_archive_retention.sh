#!/usr/bin/env bash
set -euo pipefail
[[ $EUID == 0 ]] || { echo 'Run with sudo bash misc/install_archive_retention.sh.' >&2; exit 1; }
ACTION=install; TABLES=(traffic statuslog)
while (($#)); do
    case $1 in
        --enable) ACTION=enable ;;
        --disable) ACTION=disable ;;
        --table)
            case ${2:-} in traffic|statuslog) TABLES=("$2"); shift ;; all) TABLES=(traffic statuslog); shift ;; *) echo 'Use --table traffic|statuslog|all.' >&2; exit 2 ;; esac ;;
        *) echo 'Usage: install_archive_retention.sh [--enable|--disable] [--table traffic|statuslog|all]' >&2; exit 2 ;;
    esac
    shift
done
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
for cmd in python3 systemctl flock; do command -v "$cmd" >/dev/null; done
install -d -m 0700 /var/lib/tarasec-traffic-archive
# Explicit disable must stop current work as well as future runs. A killed batch
# keeps its pending evidence; do not discard it automatically when re-enabling.
if [[ $ACTION == disable ]]; then
    for table in "${TABLES[@]}"; do
        unit="tarasec-$table-archive"
        if systemctl cat "$unit.timer" >/dev/null 2>&1; then systemctl disable --now "$unit.timer"; fi
        if systemctl cat "$unit.service" >/dev/null 2>&1; then systemctl stop "$unit.service"; fi
    done
fi
exec 9>/var/lib/tarasec-traffic-archive/run.lock
flock -n 9 || { echo 'Archive cleanup is running; retry after completion.' >&2; exit 1; }
for table in "${TABLES[@]}"; do
    # Preserve existing settings; infer the already-deployed local endpoint only
    # from an installed script. No testbed address is a new-node default.
    python3 - "$table" "$ACTION" <<'PY'
from pathlib import Path
import re,sys
table,action=sys.argv[1:]
path=Path(f'/etc/tarasec-{table}-archive.conf')
existing=path.exists()
text=path.read_text() if existing else ''
old=Path(f'/usr/local/sbin/tarasec-{table}-archive')
host=''
if old.exists():
    match=re.search(r'archive-upload@([A-Za-z0-9.-]+)',old.read_text())
    if match:host=match.group(1)
defaults={'ENABLED':'1' if existing else '0','DB_NAME':'taransvar','RETENTION_DAYS':'30',
          'BATCH_ROWS':'5000','MAX_BATCHES':'12' if table=='traffic' else '4',
          'ARCHIVE_HOST':host,'ARCHIVE_USER':'archive-upload','ARCHIVE_PORT':'22',
          'ARCHIVE_KEY':'/root/.ssh/tarasec_archive_ed25519','ARCHIVE_ROOT':'/uploads'}
for key,value in defaults.items():
    if not re.search(r'^'+key+r'=',text,re.M):text+=f'\n{key}={value}\n'
if action in ('enable','disable'):
    text=re.sub(r'^ENABLED=.*$',f"ENABLED={int(action=='enable')}",text,flags=re.M)
tmp=path.with_name(path.name+'.new')
tmp.write_text(text.lstrip('\n'));tmp.chmod(0o600);tmp.replace(path)
PY
done
if [[ $ACTION == disable ]]; then
    echo 'Archive cleanup disabled. Existing archives and pending batches retained.'
    exit 0
fi
for cmd in mariadb mariadb-dump sftp gzip sha256sum install; do command -v "$cmd" >/dev/null; done
bash -n "$ROOT/misc/archive_table.sh"
install -d -m 0750 /usr/local/lib/tarasec-archive
install -m 0750 "$ROOT/misc/archive_table.sh" /usr/local/lib/tarasec-archive/archive_table.sh
for table in "${TABLES[@]}"; do
    unit="tarasec-$table-archive"
    cat > "/usr/local/sbin/$unit" <<WRAPPER
#!/usr/bin/env bash
set -euo pipefail
set -a
source /etc/$unit.conf
set +a
exec /usr/local/lib/tarasec-archive/archive_table.sh $table
WRAPPER
    chmod 0750 "/usr/local/sbin/$unit"
    cat > "/etc/systemd/system/$unit.service" <<SERVICE
[Unit]
Description=Archive and prune TaraSec $table history
Wants=network-online.target
After=network-online.target mariadb.service
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/$unit
TimeoutStartSec=10min
Nice=10
IOSchedulingClass=idle
UMask=0077
SERVICE
    delay=15min; [[ $table != statuslog ]] || delay=5min
    cat > "/etc/systemd/system/$unit.timer" <<TIMER
[Unit]
Description=Run TaraSec $table archival every 15 minutes
[Timer]
OnActiveSec=$delay
OnUnitInactiveSec=15min
AccuracySec=1min
Unit=$unit.service
[Install]
WantedBy=timers.target
TIMER
done
systemctl daemon-reload
flock -u 9
exec 9>&-
for table in "${TABLES[@]}"; do
    unit="tarasec-$table-archive"
    # Read root-owned policy in a subshell, matching the installed wrapper.
    enabled=$(bash -c 'source "$1"; printf "%s" "${ENABLED:-0}"' bash "/etc/$unit.conf")
    if [[ $enabled == 0 ]]; then
        systemctl disable --now "$unit.timer"
        systemctl stop "$unit.service"
        echo "$table installed disabled. Configure /etc/$unit.conf, then use --enable --table $table."
        continue
    fi
    [[ $enabled == 1 ]] || { echo 'ENABLED must be 0 or 1.' >&2; exit 1; }
    if systemctl is-active --quiet tarasec-retention.timer || systemctl is-enabled --quiet tarasec-retention.timer; then
        echo 'The delete-only retention timer is enabled. Choose one workflow before enabling archival.' >&2
        exit 1
    fi
    test ! -e "/var/lib/$unit/pending"
    systemctl disable --now "$unit.timer"
    # Execute inside a subshell so each table retains its own settings.
    (
        set -a; source "/etc/$unit.conf"; set +a
        test -n "${ARCHIVE_HOST:-}"
        test -r "${ARCHIVE_KEY:-/root/.ssh/tarasec_archive_ed25519}"
        [[ ${DB_NAME:-taransvar} =~ ^[A-Za-z0-9_]+$ ]]
        if [[ $table == statuslog ]]; then
            mariadb --database="${DB_NAME:-taransvar}" <<'SQL'
SET SESSION lock_wait_timeout=10;
ALTER TABLE partnerRouterStatusLog
  ADD INDEX IF NOT EXISTS idx_statuslog_created (created, ip),
  ALGORITHM=NOCOPY, LOCK=NONE;
SQL
        fi
        FIRST_OUTPUT=$(mktemp)
        trap 'rm -f "$FIRST_OUTPUT"' EXIT
        MAX_BATCHES=1 /usr/local/lib/tarasec-archive/archive_table.sh "$table" | tee "$FIRST_OUTPUT"
        # A concurrent job may win the shared lock; a skipped run is not a test.
        grep -q '^Archive run completed\.$' "$FIRST_OUTPUT"
    )
    systemctl enable --now "$unit.timer"
    echo "$table installed and enabled; policy preserved in /etc/$unit.conf."
done
systemctl --no-pager list-timers 'tarasec-*-archive.timer'
