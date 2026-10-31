# Automatic maintenance in the existing upgrade dot

The TaraSec upgrades dot combines release notices with observed automatic
maintenance health. No extra dot is added. Its tooltip and Issues entry explain
both release and scheduler evidence.

- Red: daily enrollment absent, timer stopped/disabled, service failure, or
  blocked scheduler. Existing important release notices remain red.
- Yellow: missing/older-than-five-minute report, never completed/overdue daily
  run (36 hours), unknown result, or waiting for rollout/activity prerequisites.
- Green: timer enabled and active, recent successful maintenance run, and no
  release notice or scheduler problem. A currently running oneshot job is
  explicitly labelled as running, not as completed.

`automatic_upgrade_status.py` reads only enrollment presence, bounded systemd
properties and selected scheduler metadata. It reports no tokens, credentials,
commands or raw errors. `tarasec-upgrade-monitor.timer` runs the collector separately each minute and
atomically writes `/var/lib/tarasec/upgrade/automatic-status.json`.
`upgrade_status.pm` only reads that bounded snapshot; it never starts a process
or waits for systemd during a status report, even when software.json is absent. Older reporters show unknown
(yellow), rather than implying automatic maintenance is healthy.

Deploy the runtime component through the normal upgrader on reporting nodes
(`/root/taransvar/perl`), and the Gatekeeper component on monitor hosts. Both
upgrade_status.pm and automatic_upgrade_status.py must be deployed together.
The display change alone can identify missing evidence but cannot inspect a
remote machine's timer until that machine reports it. Node enrollment remains
an explicit owner action: sudo ./tarasec upgrade --enable-daily.

## Deploy the isolated collector

On each reporting node from the updated checkout:

```bash
clear
# Machine: Roquefort (repeat on other reporting nodes)
cd ~/taransvar &&
git pull --ff-only origin main &&
sudo bash misc/install_upgrade_monitor.sh
```

The installer backs up the reporter module, installs a separate collector timer,
and runs it successfully before replacing the reporter module. It does not
enroll automatic maintenance or change its scheduling. The standard runtime
upgrade also invokes this installer. A collector failure leaves normal reporting
independent; its snapshot becomes stale and the existing dot turns yellow.
