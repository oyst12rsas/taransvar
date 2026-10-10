# Node coordinator

The owner-authorized migration replaces only the existing minute reporter cron
line and the operations/security/approval polling timers. It preserves the
existing reporter, local configuration, other cron jobs, activity observer,
SSH boot guard and independently scheduled SSH window expiry.

Run `sudo bash misc/install_node_coordinator.sh /root/taransvar/perl/crontasks.pl`
from the reviewed checkout. The reporter and its parents must be root owned
and not group/world writable. Security and operations workers must already
be enrolled. Their current policies, model configuration and execution limits
remain in force.

One persistent scheduler uses monotonic deadlines: approved jobs every five
seconds, existing reporter/security checks/operations ticks every minute.
Each helper has its own systemd service. Starting an already active oneshot
does not overlap it. Missed intervals are skipped rather than queued. Slow
model requests cannot block the reporter or SSH approval polling. The helper
polls the approval API, not an AI model. Remote approval delivery remains
subject to network latency; this is not instantaneous push.

No periodic full AI searches or automatic package-upgrade capability is added
by this migration. Updates still use the operations worker's owner policy.
The shared bounded package-maintenance action remains separate work.

Installation records root cron and previous timer states in a root-only
`/var/lib/tarasec-coordinator-backup.*` directory. Immediate startup failure
restores scheduling. Before declaring migration verified, inspect all helper
journals, fresh central DB receipt, approved SSH reopening and automatic expiry.
The first reporter may encounter the old cron instance's flock; the next minute
should succeed. Reinstalling either older worker installer may re-enable its
timer; disable duplicate timers again or reinstall the coordinator afterward.

Recovery: stop and disable tarasec-node-coordinator.service, restore root cron
with `crontab BACKUP/root.cron`, enable timers in BACKUP/enabled-timers and start
those in BACKUP/active-timers. Do not disable SSH expiry timers or the boot guard.
