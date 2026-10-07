# Database and diagnostic-log retention

Install on each TaraSec gateway/node and the DB server, where its local
`taransvar` database resides. Do not install on a VM host merely because it
hosts those guests. This is independent of the packet-processing cron loop.

```sh
sudo apt-get install -y libdbi-perl libdbd-mysql-perl logrotate
sudo bash misc/install_retention.sh
```

The installer previews eligible rows before enabling a 15-minute systemd timer.
It copies its dependencies into `/usr/local/lib/tarasec-retention`, so moving the
checkout does not break the job. Re-run the installer after pulling an update.
An existing `/etc/tarasec-retention.conf` is preserved.

Default policy:

| Data | Keep | Conditions |
| --- | --- | --- |
| traffic | 30 days | Both created and lastSeen must be old |
| syslogThreat | 90 days | Handled; no SSH demo or evidence references |
| syslog | 30 days | No remaining threat or local observation evidence reference |
| dhcpEvent | 30 days | Handled |
| dmesg, partnerRouterStatusLog, aiResponse, loginAttempt | 30 days | Timestamp must be old |

`syslog.handled` is not a processing queue in the current code; pending processing
is represented by `syslogThreat.handled`, and raw rows referenced by those threats
are preserved. Accounts, credentials, permissions, unit/client state, infections,
hack reports, incident/demo records, assistance, delivery queues, payments and
usage accounting are not deleted. Declared foreign-key references, including
owner-added cascade relationships, also protect parent rows. Missing optional
tables are skipped; a malformed known evidence table stops cleanup.

The task scans primary-key windows of at most 1,000 rows, round-robin across the
eligible tables, with at most 20 windows per table and a 45-second run budget.
Persistent cursors eventually revisit retained rows. Preview always starts from
the beginning, changes no rows or progress, and reports only the scanned windows,
not the full backlog. No additional indexes, schema upgrades or table rebuilds
are required. MariaDB statements have a five-second timeout; MySQL uses lock
timeouts and the service's overall timeout. Errors fail the service and are
visible in its journal. The next run can safely repeat a partially completed scan.

```sh
sudo perl /usr/local/lib/tarasec-retention/db_retention.pl
sudo systemctl start tarasec-retention.service
sudo journalctl -u tarasec-retention.service --no-pager -n 50
sudo systemctl list-timers tarasec-retention.timer
```

Edit the policy to change retention; `ENABLED=0` disables DB deletions. Disable
the timer to stop both database cleanup and log rotation:

```sh
sudo systemctl disable --now tarasec-retention.timer
```

The explicit diagnostic files in `retention.logrotate` rotate daily or above
20 MiB when checked, keeping seven compressed rotations. Persistent writers use
copytruncate: a small amount of diagnostic output can be lost during rotation.
This is not a strict instantaneous size limit. Ingestion captures, unprocessed
files, SSH credentials and system-managed logs are not touched. Old DHCP capture
archives and other paths outside the list are not removed.

Deleting old database rows normally makes InnoDB pages reusable; it does not
guarantee a smaller database file or immediate extra space in `df`. Table
rebuilds (`OPTIMIZE TABLE`) are deliberately not automatic: they can require
substantial spare disk space and affect live queries. Inspect actual table sizes,
backups and `innodb_file_per_table` before planning that separate operation.

This task deletes historical telemetry rather than archiving it. If history must
be retained remotely (for example on barracuda), configure and verify an export
and upload workflow before enabling deletion. No remote archive is implied by
installing this task. Existing backups are the recovery path for deleted rows.
