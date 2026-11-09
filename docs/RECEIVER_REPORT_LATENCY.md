# Prompt receiver reporting (pending live acceptance)

A testbed trace showed approximately 40 seconds between normalized SSH rejection
and receipt by the source gateway, then another 11 seconds before central owner
confirmation. This is server-side latency; it does not establish an Android poll
interval change. The cron worker previously ran WHOIS, pending HTTP work and
status/startup maintenance before rejection delivery.

`tarasec-receiver-reports.service` runs `crontasks.pl threats`, which bypasses
that maintenance and checks pending receiver events with a one-second idle
interval. Cron retains a fallback before its slower tasks. Both paths take the
same nonblocking batch lock; a second worker cannot deliver the same batch
concurrently. Pending records are processed oldest first instead of newest first.
HTTP failures remain pending. Network/DB delays and repeated destination failures
can still increase latency; one second is an idle interval, not an end-to-end SLA.

SSH connect, client-version, session-close and demo-validation-error records remain
archived and normalized but are not attacks/rejections. Historical lifecycle rows
are marked informational when consumed. Real failed logins still report as
`ssh_fail`; accepted honeypot credentials remain reportable under
`attack_severity_7`, rather than being mislabeled as failures.

## Installation for a later test

Do not deploy this draft until the operator is available to measure it. On the
central receiver and any gateway/sensor running the legacy cron reporting path,
from a checkout of this branch with the usual Perl/DB runtime already deployed:

```sh
sudo bash misc/install_receiver_reports.sh
```

The installer syntax-checks against deployed dependencies, waits up to 120 seconds
for the old cron worker to finish, backs up replaced files, and starts the new
service. It does not change firewall/SSH policy, identity, tokens or DB endpoints.
Avoid manually running cron in `force` mode during installation.

On the central receiver also deploy the updated normalizer and shared policy:

```sh
sudo install -m 0644 misc/receiver_event_policy.pm /root/taransvar/perl/
sudo install -m 0755 misc/remote_syslog_normalizer.pl /root/taransvar/perl/
sudo systemctl restart tarasec-remote-normalizer.service
```

Use an idle test window: the existing normalizer seeks to the end of the archive
on restart and does not replay records accumulated while stopped. The DB role of
`setup_backoffice_ai.sh` installs both components for new deployments as well.

## Verification

```sh
prove misc/test_receiver_event_policy.t misc/test_receiver_reporting.t
sudo systemctl status tarasec-receiver-reports.service --no-pager
sudo journalctl -u tarasec-receiver-reports.service -n 40 --no-pager
```

Start a fresh Demo 2 and record rejection time, normalization time, gateway
`hackReport.created`, central owner confirmation, and app transition time. Check
that only actual failed login events are labeled `ssh_fail`, source ports match,
and genuine failed deliveries stay pending. Do not replay historical rejection
records into an active demo or manufacture confirmations.

The existing central-relay provenance mismatch and Demo 2 evidence matching rules
are separate concerns; this change does not relax sender/owner matching to make a
demo pass. End-to-end Demo 2 timing, MySQL behavior and systemd deployment remain
untested until the operator performs that acceptance run.

## Recovery

Stop and disable `tarasec-receiver-reports.service`; the updated cron retains its
reporting fallback. To revert files, use the installer-reported backup and the
previous normalizer from the prior checkout. Keep the policy module present while
either updated script uses it. Restart the normalizer during an idle window.
