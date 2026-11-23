#!/usr/bin/env python3
"""Bring a freshly imported base schema up to the checkout's DB version.

db/taransvar.sql is stamped dbVersion 49 by db/postcreate.sql, but it already
contains some objects added by later migrations (for example traffic.lastSeen
and the pending-work indexes). Replaying misc/install.sql from 50 therefore
hits "already exists" errors, which stops checkDbVersion() in lib_cron.pm, and
upgrade_db.pl only accepts databases from version 89.

This helper is for the installer's fresh-schema path only. It applies each
missing migration statement by statement as the local MariaDB root user,
ignores only "object already exists" errors, stops on any other error and
records each completed version so a rerun resumes where it stopped.
"""
import argparse
import re
import subprocess
import sys
from pathlib import Path

# MariaDB errors meaning the base schema already has the object.
ALREADY_PRESENT = {
    1050,  # Table already exists
    1060,  # Duplicate column name
    1061,  # Duplicate key name
    1022,  # Duplicate key (constraint) on write
    1826,  # Duplicate foreign key constraint name
    1359,  # Trigger already exists
}
VERSION_MARKER = re.compile(r'(?i)^update\s+setup\s+set\s+dbVersion\s*=\s*\d+$')


def migrations(sql_text):
    """Return {version: [statements]} from install.sql's '#version N' sections."""
    parts = re.split(r'^#version\s+(\d+)\b.*$', sql_text, flags=re.M)
    result = {}
    for i in range(1, len(parts), 2):
        body = '\n'.join(line for line in parts[i + 1].splitlines()
                         if not line.strip().startswith(('#', '--')))
        statements = [s.strip() for s in body.split(';') if s.strip()]
        result[int(parts[i])] = [s for s in statements if not VERSION_MARKER.match(s)]
    return result


def mysql(database, statement):
    return subprocess.run(['mysql', '-N', '-B', database, '-e', statement],
                          capture_output=True, text=True)


def error_code(stderr):
    match = re.search(r'ERROR (\d+)', stderr)
    return int(match[1]) if match else None


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument('--database', default='taransvar')
    parser.add_argument('--sql', default=str(Path(__file__).resolve().with_name('install.sql')))
    args = parser.parse_args()

    found = migrations(Path(args.sql).read_text())
    if not found:
        raise SystemExit('No numbered migrations found in ' + args.sql)
    latest = max(found)
    probe = mysql(args.database, 'SELECT dbVersion FROM setup')
    rows = probe.stdout.split()
    if probe.returncode != 0 or len(rows) != 1:
        raise SystemExit('Expected exactly one setup row in %s; no migration applied.' % args.database)
    current = int(rows[0])
    if current >= latest:
        print('DB schema version %d is current.' % current)
        return 0

    skipped = 0
    for version in range(current + 1, latest + 1):
        for statement in found.get(version, []):
            result = mysql(args.database, statement)
            if result.returncode == 0:
                continue
            if error_code(result.stderr) in ALREADY_PRESENT:
                skipped += 1
                continue
            sys.stderr.write('Migration %d failed; database stays at version %d.\n%s\n%s\n'
                             % (version, version - 1, statement[:200], result.stderr.strip()))
            return 1
        mark = mysql(args.database, 'UPDATE setup SET dbVersion = %d' % version)
        if mark.returncode != 0:
            sys.stderr.write('Could not record DB version %d: %s\n' % (version, mark.stderr.strip()))
            return 1
    print('DB schema migrated %d -> %d (%d statements already present in the base schema).'
          % (current, latest, skipped))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
