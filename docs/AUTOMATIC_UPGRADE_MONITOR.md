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
commands or raw errors. `upgrade_status.pm` attaches this evidence to each normal
status report even when software.json is absent. Older reporters show unknown
(yellow), rather than implying automatic maintenance is healthy.

Deploy the runtime component through the normal upgrader on reporting nodes
(`/root/taransvar/perl`), and the Gatekeeper component on monitor hosts. Both
upgrade_status.pm and automatic_upgrade_status.py must be deployed together.
The display change alone can identify missing evidence but cannot inspect a
remote machine's timer until that machine reports it. Node enrollment remains
an explicit owner action: sudo ./tarasec upgrade --enable-daily.
