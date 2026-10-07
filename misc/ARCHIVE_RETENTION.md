# Verified database archival and cleanup

Install from the repository on the machine whose local MariaDB `taransvar`
database is being archived. Install neither client on the VM host solely because
it hosts these guests. The archive receiver only needs SFTP storage; it does not
need a MariaDB server or TaraSec node installation.

```sh
clear
git pull --ff-only
sudo bash misc/install_archive_retention.sh
```

New installations are **disabled**. Configure the root-owned files
`/etc/tarasec-traffic-archive.conf` and
`/etc/tarasec-statuslog-archive.conf` before enabling:

```ini
ENABLED=0
DB_NAME=taransvar
RETENTION_DAYS=30
BATCH_ROWS=5000
MAX_BATCHES=12
ARCHIVE_HOST=archive.example.org
ARCHIVE_USER=archive-upload
ARCHIVE_PORT=22
ARCHIVE_KEY=/root/.ssh/tarasec_archive_ed25519
ARCHIVE_ROOT=/uploads
```

Use `MAX_BATCHES=4` for status logs. The configuration is trusted root-owned
shell assignment syntax, read by the installed wrapper; use literal values,
not expressions or references to other environment variables. Existing
settings survive reinstalls. The previously deployed traffic/status-log
installers without an `ENABLED` field migrate as enabled, preserving their
retention and batch limits. The installer can infer the old SFTP endpoint from
the installed script, but never uses that endpoint as a new-machine default.

Prepare an individual upload key on each sender. Authorize its public key on
the SFTP receiver, preferably restricted to that sender's stable source IP.
An SFTP-only account should use a root-owned chroot and a writable upload
directory. Ensure the archive disk is mounted before SSH starts. Check free
space on the receiver. If the VM shares a local network with its sender, that
network suffices; remote senders need a verified path such as NetBird.

Connect once interactively and compare the receiver's host-key fingerprint with
one obtained independently on the receiver. Scheduled jobs use
`StrictHostKeyChecking=yes`, and never accept a new host key automatically.
`ARCHIVE_HOST` supports IPv4 addresses and DNS names. The configured remote
directory must already exist. Keys and network addresses belong in local
configuration, not in a shared repository.

Enable one table or both:

```sh
clear
sudo bash misc/install_archive_retention.sh --enable --table traffic
sudo bash misc/install_archive_retention.sh --enable --table statuslog
# Or: sudo bash misc/install_archive_retention.sh --enable
```

Enabling runs one batch of up to 5,000 eligible records through archive,
download, restore and deletion before enabling that table's timer. If no rows
are eligible, the run completes without testing an upload; verify SFTP
separately. An upload/check failure leaves the timer disabled. Status-log
installation adds an online `(created, ip)` index; it does not change dbVersion.
This local maintenance index is idempotent and independent of schema migrations.
Do not silently fall back to a blocking table rebuild if online index creation
fails. A simultaneous archive job causes the enable test to fail rather than
mistaking a skipped run for verification.

| Table | Retention | Eligibility | Default run limit |
| --- | --- | --- | --- |
| traffic | 30 days by default; configurable from 7 days | created older than cutoff; lastSeen older than cutoff or NULL | 12 x 5,000 rows |
| partnerRouterStatusLog | 30 days | created older than cutoff | 4 x 5,000 rows |

For a one-week traffic policy, set `RETENTION_DAYS=7` in
`/etc/tarasec-traffic-archive.conf`. Keep status-log policy separate. Records
created in the retained period and flows active in that period are preserved.
NULL lastSeen uses created as the age criterion. Reducing retention drains the
newly eligible backlog gradually through the existing bounded batches; it does
not immediately delete all older data in one operation. Existing local policies
are not reset by an upgrade.

The traffic timer runs after 15 minutes; the status-log timer after five minutes.
Subsequent runs are scheduled about 15 minutes after service completion. Both
share a nonblocking lock. Retention is based on UTC database timestamps, not
the archive creation date. Status-log DATETIME values are assumed to have been
written in UTC; verify this on deployments with a different database timezone.
Missing or changed schemas, missing indexes and incoming foreign keys stop
cleanup rather than widening the policy.

Each batch is copied to a temporary database. The compressed SQL is uploaded
under a partial name, renamed, downloaded and compared byte-for-byte, then
restored in another temporary database. Row counts and table checksums must
match. Only live rows still matching **every** archived column are deleted in
a transaction. Status TEXT is compared byte-for-byte rather than using a
case-insensitive collation. Rows updated during archival are preserved.

Archives contain the SQL, SHA256SUMS and a manifest identifying source, table,
row count and cutoff. Directory names include the sender hostname. They retain
network metadata and status messages: restrict receiver access accordingly.
The process does not expire remote archives or copy them to another disk.
Source-host failure and archive-disk failure therefore need separate backup
planning. InnoDB deletion normally frees pages for reuse, not filesystem space;
automatic OPTIMIZE/table rebuilds are not performed.

## Disable and re-enable

```sh
clear
sudo bash misc/install_archive_retention.sh --disable
# One table only:
# sudo bash misc/install_archive_retention.sh --disable --table traffic
```

This writes `ENABLED=0`, disables the selected timers and stops active services.
Existing remote archives and pending evidence remain. A transaction already
committed cannot be undone by disabling. Editing `ENABLED=0` directly stops
future invocations; use `--disable` to stop an active service as well.
The installer preserves disabled settings on an ordinary reinstall.

```sh
clear
sudo systemctl list-timers 'tarasec-*-archive.timer'
sudo journalctl -u tarasec-traffic-archive.service --no-pager -n 40
sudo journalctl -u tarasec-statuslog-archive.service --no-pager -n 40
```

Failures preserve `/var/lib/tarasec-{traffic,statuslog}-archive/pending`, including
`state.txt` with exact temporary database names and remote directory. Subsequent
runs refuse to overwrite it. Review the error, archive and live state before
recovery; never delete live rows solely because a remote directory exists.
After an interrupted deletion, determine whether the transaction committed.
Only remove the specifically identified temporary databases and local pending
files after diagnosis; verified remote archives can stay. Then re-enable and
verify another batch. There is deliberately no blind automatic reset command.

## Relationship to delete-only retention

`misc/install_retention.sh` installs a separate historical telemetry deletion
policy without remote archival. The archive installer refuses to enable while
that timer is active or enabled. Do not enable the delete-only timer later on
the same database if the archive-before-deletion guarantee is required.
See [RETENTION.md](RETENTION.md). Queue, assistance, threat, identity, accounting
and other tables are outside this archival implementation. In particular a
historical `pendingWget.handled` timestamp can accompany a failed response and
must not be treated as proof of delivery.

The initial traffic and status-log batches, restore checks and deployed timers
were verified on the owner's Ubuntu 24.04/MariaDB testbed. Other installations
must verify their own access, storage and database behavior. Recommended by AI;
not yet reviewed or authorized by the TaraSec team.
