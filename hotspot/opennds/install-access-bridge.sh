#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

[[ ${EUID:-$(id -u)} -eq 0 ]] || exec sudo -E bash "$0" "$@"

command -v perl >/dev/null || { echo "perl is required" >&2; exit 1; }
command -v opennds >/dev/null || { echo "openNDS is required" >&2; exit 1; }

apt-get update -y
apt-get install -y libdbi-perl libdbd-mysql-perl

install -d -m 0755 /usr/local/lib/tarasec /usr/lib/opennds
install -m 0644 "$REPO_ROOT/hotspot/perl/func.pm" /usr/local/lib/tarasec/func.pm
install -m 0755 "$SCRIPT_DIR/access_policy.pl" /usr/lib/opennds/access_policy.pl
install -m 0755 "$SCRIPT_DIR/custombinauth_tarasec.sh" /usr/lib/opennds/custombinauth.sh

# Extend the enforcement table in place. access remains the source of truth;
# these columns are only the final limits to enforce for the IP in that row.
perl -I/usr/local/lib/tarasec -Mfunc -e '
  my $dbh = getConnection();
  my @sql = (
    q{ALTER TABLE access ADD COLUMN IF NOT EXISTS sessionMinutes INT UNSIGNED NOT NULL DEFAULT 0 AFTER hasaccess},
    q{ALTER TABLE access ADD COLUMN IF NOT EXISTS uploadKbit INT UNSIGNED NOT NULL DEFAULT 0 AFTER sessionMinutes},
    q{ALTER TABLE access ADD COLUMN IF NOT EXISTS downloadKbit INT UNSIGNED NOT NULL DEFAULT 0 AFTER uploadKbit},
    q{ALTER TABLE access ADD COLUMN IF NOT EXISTS uploadQuotaKB BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER downloadKbit},
    q{ALTER TABLE access ADD COLUMN IF NOT EXISTS downloadQuotaKB BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER uploadQuotaKB}
  );
  for my $sql (@sql) { $dbh->do($sql) or die $dbh->errstr; }
  $dbh->disconnect;
'

systemctl restart opennds
sleep 3
systemctl is-active --quiet opennds || {
    journalctl -u opennds -n 60 --no-pager >&2 || true
    exit 1
}

echo "TaraSec access-table quota bridge installed."
echo "access.hasaccess is authoritative; zero quota/rate values mean no per-client limit."
