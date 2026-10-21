# Required reboot with idle sessions

The operations worker checks pending OS reboots locally before model scheduling.
Exhausting the AI budget does not disable these checks.

An open session no longer blocks a reboot by itself. The default inactivity
threshold is 900 seconds (15 minutes), configurable with
`reboot_session_idle_seconds` in `/etc/tarasec/operations-agent.json` (minimum 60).
TTY input access time and the session's monotonic login timestamp must both
show inactivity for that interval. Sessions without a TTY require loginctl's
idle hint and monotonic idle timestamp. Recent input, a new login, failed
inspection, or unavailable activity timestamps defer reboot. Per-session idle
seconds and separate recent-activity/unknown-evidence blockers appear in the
worker's `reboot.session_activity` evidence. Activity is checked again immediately
before dispatch.

Existing mode authorization, kernel/module/initramfs readiness, active demo and
traffic checks, daily reboot limits and post-boot verification remain required.
This does not reboot on every pull or permit inspection-mode reboots.

## Apply on an existing node

The installed worker lives outside the checkout, so a Git pull alone does not
replace it. Pull the merged change, stop scheduling and wait for any current
worker to finish, then reinstall:

```sh
git pull --ff-only
sudo systemctl stop tarasec-operations-agent.timer
sudo systemctl is-active tarasec-operations-agent.service
```

Wait until the service reports `inactive` before continuing:

```sh
sudo bash misc/install_operations_agent.sh
sudo systemctl start tarasec-operations-agent.timer
```

Do not interrupt an active worker command. Restart the timer only if it was enabled before.
On its next tick, an enabled Demo/Production worker will reboot if the OS
requires it and the activity/readiness checks pass. This disconnects idle SSH
sessions. Check the changed boot ID, running target kernel and fresh post-boot
receipt before treating the reboot issue as resolved.

Source regression tests cover idle/recent/new sessions, activity at dispatch,
missing evidence, threshold boundaries, exhausted model budget, kernel readiness
and post-boot verification. Live node acceptance remains separate.

## Serial consoles and missing logind terminal metadata

Serial consoles such as `ttyS0`, `ttyAMA0`, and virtual consoles `hvc0` are valid
terminals. When logind omits TTY metadata for an SSH session, the checker resolves
its exact systemd session scope, reads cgroup process membership, rechecks each
process's membership and matches its kernel controlling-terminal device number
to a terminal descriptor. It takes the most recent input time across the verified
terminals, bounded by the login age. A new login or recent input still blocks.
Missing processes, ambiguous scope, inaccessible devices and sessions with no
verified terminal or idle timestamps remain unknown and block reboot. Per-session
reason and terminal fields make unresolved checks visible. Checks repeat before
dispatch; a successful inspection is not proof a reboot has occurred.
