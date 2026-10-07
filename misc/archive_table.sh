#!/usr/bin/env bash
# Local MariaDB telemetry -> verified SFTP archive -> unchanged-row deletion.
set -euo pipefail
umask 077
export LC_ALL=C
[[ ${ENABLED:-0} == 0 || ${ENABLED:-0} == 1 ]] || { echo 'ENABLED must be 0 or 1.' >&2; exit 1; }
if [[ ${ENABLED:-0} == 0 ]]; then echo 'Archive cleanup is disabled; no database changes.'; exit 0; fi
case ${1:-} in
    traffic)
        TABLE=traffic; PREFIX=traffic; DATE_COLUMN=lastSeen
        EXPECTED='trafficId,ipFrom,ipTo,whoIsId,portFrom,portTo,created,count,isLan,tag,lastSeen'
        INDEX=idx_traffic_active_lastseen; STATE=/var/lib/tarasec-traffic-archive
        MAX_BATCHES=${MAX_BATCHES:-12} ;;
    statuslog)
        TABLE=partnerRouterStatusLog; PREFIX=statuslog; DATE_COLUMN=created
        EXPECTED='ip,created,status'; INDEX=idx_statuslog_created
        STATE=/var/lib/tarasec-statuslog-archive; MAX_BATCHES=${MAX_BATCHES:-4} ;;
    *) echo 'Usage: archive_table.sh traffic|statuslog' >&2; exit 2 ;;
esac
DB_NAME=${DB_NAME:-taransvar}
RETENTION_DAYS=${RETENTION_DAYS:-30}
BATCH_ROWS=${BATCH_ROWS:-5000}
ARCHIVE_USER=${ARCHIVE_USER:-archive-upload}
ARCHIVE_PORT=${ARCHIVE_PORT:-22}
ARCHIVE_KEY=${ARCHIVE_KEY:-/root/.ssh/tarasec_archive_ed25519}
ARCHIVE_ROOT=${ARCHIVE_ROOT:-/uploads}
ARCHIVE_HOST=${ARCHIVE_HOST:-}
[[ $DB_NAME =~ ^[A-Za-z0-9_]+$ && $ARCHIVE_HOST =~ ^[A-Za-z0-9][A-Za-z0-9.-]*$ && $ARCHIVE_USER =~ ^[A-Za-z_][A-Za-z0-9_-]*$ ]] || {
    echo 'Configure DB_NAME, ARCHIVE_HOST (IPv4 or DNS), and ARCHIVE_USER.' >&2; exit 1;
}
[[ $ARCHIVE_ROOT =~ ^/[A-Za-z0-9_./-]+$ && $ARCHIVE_ROOT != *..* ]] || { echo 'Invalid ARCHIVE_ROOT.' >&2; exit 1; }
for value in "$RETENTION_DAYS" "$BATCH_ROWS" "$MAX_BATCHES" "$ARCHIVE_PORT"; do
    [[ $value =~ ^[1-9][0-9]{0,4}$ ]] || { echo 'Invalid numeric configuration.' >&2; exit 1; }
done
(( RETENTION_DAYS >= 30 && RETENTION_DAYS <= 3650 && BATCH_ROWS <= 5000 && MAX_BATCHES <= 12 && ARCHIVE_PORT <= 65535 )) || {
    echo 'Configuration exceeds supported limits.' >&2; exit 1;
}
test -r "$ARCHIVE_KEY"
install -d -m 0700 "$STATE" /var/lib/tarasec-traffic-archive
exec 9>/var/lib/tarasec-traffic-archive/run.lock
flock -n 9 || { echo 'Another archive job is active; skipping this run.'; exit 0; }
if [[ -e $STATE/pending ]]; then
    echo "Unfinished batch at $STATE/pending. No new work or deletion attempted." >&2; exit 1;
fi
trap 'echo "FAILED: inspect the journal and any pending batch in $STATE/pending before retrying." >&2' ERR
sql() { mariadb --database="$DB_NAME" --batch --skip-column-names "$@"; }
ACTUAL=$(sql -e "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='$TABLE'")
[[ $ACTUAL == "$EXPECTED" ]] || { echo "$TABLE schema changed; refusing cleanup." >&2; exit 1; }
ENGINE=$(sql -e "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='$TABLE'")
[[ $ENGINE == InnoDB ]] || { echo "$TABLE must use InnoDB." >&2; exit 1; }
INDEX_COLUMNS=$(sql -e "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='$TABLE' AND INDEX_NAME='$INDEX'")
[[ $INDEX_COLUMNS == "$DATE_COLUMN" || $INDEX_COLUMNS == "$DATE_COLUMN",* ]] || { echo "Expected date index $INDEX is missing." >&2; exit 1; }
REFERENCES=$(sql -e "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA='$DB_NAME' AND REFERENCED_TABLE_NAME='$TABLE'")
[[ $REFERENCES == 0 ]] || { echo "$TABLE has incoming foreign keys; cleanup requires review." >&2; exit 1; }
CUTOFF=$(sql -e "SET time_zone='+00:00'; SELECT DATE_FORMAT(NOW() - INTERVAL $RETENTION_DAYS DAY,'%Y-%m-%d %H:%i:%s')")
ELIGIBLE="\`$DATE_COLUMN\` < '$CUTOFF'"
# Preserve newly created traffic, even if a bad clock produced an old lastSeen.
[[ $TABLE != traffic ]] || ELIGIBLE+=" AND \`created\` < '$CUTOFF'"
SOURCE_LABEL=$(hostname | tr -c 'A-Za-z0-9_-' '_')
SOURCE_LABEL=${SOURCE_LABEL%_}
echo "Starting $TABLE: cutoff=$CUTOFF UTC; batch=$BATCH_ROWS; maximum_batches=$MAX_BATCHES"
for ((batch=1; batch<=MAX_BATCHES; batch++)); do
    TOKEN=$(tr -d '-' < /proc/sys/kernel/random/uuid)
    SOURCE_DB="ts_archive_stage_$TOKEN"; RESTORE_DB="ts_archive_restore_$TOKEN"
    STAMP="$(date -u +%Y%m%dT%H%M%SZ)_$TOKEN"
    WORK="$STATE/pending"; REMOTE="$ARCHIVE_ROOT/${SOURCE_LABEL}_${PREFIX}_$STAMP"
    FILE="$PREFIX.sql.gz"
    mkdir "$WORK"
    printf 'source_db=%s\nrestore_db=%s\nremote=%s\ncutoff_utc=%s\n' "$SOURCE_DB" "$RESTORE_DB" "$REMOTE" "$CUTOFF" > "$WORK/state.txt"
    sql <<SQL
SET time_zone='+00:00';
SET SESSION innodb_lock_wait_timeout=10;
SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED;
CREATE DATABASE $SOURCE_DB;
CREATE TABLE $SOURCE_DB.$TABLE LIKE $DB_NAME.$TABLE;
INSERT INTO $SOURCE_DB.$TABLE
SELECT * FROM $DB_NAME.$TABLE FORCE INDEX ($INDEX)
WHERE $ELIGIBLE ORDER BY \`$DATE_COLUMN\` LIMIT $BATCH_ROWS;
SQL
    ROWS=$(sql -e "SELECT COUNT(*) FROM $SOURCE_DB.$TABLE")
    if [[ $ROWS == 0 ]]; then
        sql -e "DROP DATABASE $SOURCE_DB"; rm -rf -- "$WORK"
        echo 'No remaining eligible rows.'; break
    fi
    mariadb-dump --single-transaction --quick --hex-blob "$SOURCE_DB" "$TABLE" | gzip > "$WORK/$FILE"
    (cd "$WORK"; sha256sum "$FILE" > SHA256SUMS)
    printf 'source_host=%s\nsource_database=%s\ntable=%s\nrows=%s\nretention_days=%s\ncutoff_utc=%s\n' "$(hostname)" "$DB_NAME" "$TABLE" "$ROWS" "$RETENTION_DAYS" "$CUTOFF" > "$WORK/manifest.txt"
    sftp -f -b - -P "$ARCHIVE_PORT" -o BatchMode=yes -o StrictHostKeyChecking=yes \
        -o IdentitiesOnly=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=3 \
        -i "$ARCHIVE_KEY" "$ARCHIVE_USER@$ARCHIVE_HOST" <<SFTP
mkdir $REMOTE
put $WORK/$FILE $REMOTE/$FILE.part
rename $REMOTE/$FILE.part $REMOTE/$FILE
put $WORK/SHA256SUMS $REMOTE/SHA256SUMS
put $WORK/manifest.txt $REMOTE/manifest.txt
get $REMOTE/$FILE $WORK/downloaded.sql.gz
get $REMOTE/SHA256SUMS $WORK/downloaded.SHA256SUMS
SFTP
    cmp "$WORK/$FILE" "$WORK/downloaded.sql.gz"
    cmp "$WORK/SHA256SUMS" "$WORK/downloaded.SHA256SUMS"
    gzip -t "$WORK/downloaded.sql.gz"
    sql -e "CREATE DATABASE $RESTORE_DB"
    gzip -dc "$WORK/downloaded.sql.gz" | mariadb "$RESTORE_DB"
    RESTORED=$(sql -e "SELECT COUNT(*) FROM $RESTORE_DB.$TABLE")
    SOURCE_CHECKSUM=$(sql -e "CHECKSUM TABLE $SOURCE_DB.$TABLE EXTENDED" | awk '{print $2}')
    RESTORE_CHECKSUM=$(sql -e "CHECKSUM TABLE $RESTORE_DB.$TABLE EXTENDED" | awk '{print $2}')
    [[ $ROWS == "$RESTORED" && $SOURCE_CHECKSUM =~ ^[0-9]+$ && $SOURCE_CHECKSUM == "$RESTORE_CHECKSUM" ]]
    IFS=',' read -ra COLUMNS <<< "$EXPECTED"
    MATCH=''
    for column in "${COLUMNS[@]}"; do
        [[ -z $MATCH ]] || MATCH+=' AND '
        if [[ $column == status ]]; then MATCH+='BINARY t.`status` <=> BINARY s.`status`'
        else MATCH+="t.\`$column\` <=> s.\`$column\`"; fi
    done
    DELETE_ELIGIBLE="t.\`$DATE_COLUMN\` < '$CUTOFF'"
    [[ $TABLE != traffic ]] || DELETE_ELIGIBLE+=" AND t.\`created\` < '$CUTOFF'"
    echo "Verified $ROWS archived and restored rows; deleting unchanged live copies."
    DELETED=$(sql <<SQL
SET time_zone='+00:00';
SET SESSION innodb_lock_wait_timeout=10;
START TRANSACTION;
DELETE t FROM $SOURCE_DB.$TABLE s INNER JOIN $DB_NAME.$TABLE t ON $MATCH
WHERE $DELETE_ELIGIBLE;
SELECT ROW_COUNT();
COMMIT;
SQL
)
    echo "Batch $batch: table=$TABLE archived=$ROWS deleted=$DELETED skipped=$((ROWS-DELETED)) remote=$REMOTE"
    sql -e "DROP DATABASE $RESTORE_DB; DROP DATABASE $SOURCE_DB;"
    rm -rf -- "$WORK"; sleep 1
done
echo 'Archive run completed.'
