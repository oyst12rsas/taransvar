#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root' >&2; exit 1; }
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
target=/root/taransvar/perl
test -r "$target/func.pm" || { echo 'Deploy the TaraSec Perl runtime first' >&2; exit 1; }
# Validate with the deployed dependencies before replacing the cron runtime.
perl -I"$SCRIPT_DIR" -I"$target" -c "$SCRIPT_DIR/crontasks.pl"
perl -I"$SCRIPT_DIR" -c "$SCRIPT_DIR/receiver_event_policy.pm"
# A pre-upgrade cron process does not know the new batch lock. Let it finish
# while holding its startup lock through replacement, preventing mixed versions.
exec 9>/tmp/crontasks.lock
flock -w 120 9 || { echo 'Cron did not finish; no files changed. Retry during maintenance.' >&2; exit 1; }
backup=$(mktemp -d /var/backups/tarasec-receiver-reports.XXXXXX)
chmod 0700 "$backup"
for name in crontasks.pl receiver_event_policy.pm; do
    [[ ! -e "$target/$name" ]] || cp -a "$target/$name" "$backup/"
done
systemctl stop tarasec-receiver-reports.service 2>/dev/null || true
install -m 0644 "$SCRIPT_DIR/receiver_event_policy.pm" "$target/"
install -m 0755 "$SCRIPT_DIR/crontasks.pl" "$target/"
install -m 0644 "$SCRIPT_DIR/systemd/tarasec-receiver-reports.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable tarasec-receiver-reports.service
systemctl restart tarasec-receiver-reports.service
echo "Receiver reporting installed; backup: $backup"
echo 'Check: journalctl -u tarasec-receiver-reports.service --no-pager -n 40'
echo 'Cron and delivery now share a batch lock; normal maintenance remains enabled.'
