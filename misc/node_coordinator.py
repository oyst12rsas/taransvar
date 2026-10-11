#!/usr/bin/env python3
"""One monotonic scheduler; systemd runs each bounded helper independently."""
import os
import subprocess
import time

JOBS = {
    "tarasec-ssh-approval-poll.service": 5,
    "tarasec-minute-reporter.service": 60,
    "tarasec-agent-approvals.service": 60,
    "tarasec-operations-agent.service": 60,
}

class Schedule:
    def __init__(self):
        self.deadlines = dict.fromkeys(JOBS, 0)

    def tick(self, now, launch):
        for unit, interval in JOBS.items():
            if now >= self.deadlines[unit]:
                self.deadlines[unit] = now + interval
                try:
                    launch(unit)
                except (OSError, subprocess.SubprocessError) as error:
                    print("Dispatch failed: " + unit + ": " + type(error).__name__, flush=True)

def launch(unit):
    # An active oneshot is not restarted: no overlapping helper processes.
    subprocess.run(["/usr/bin/systemctl", "start", "--no-block", unit],
                   timeout=3, check=True)

def main():
    if os.geteuid() != 0:
        raise RuntimeError("Run as root")
    schedule = Schedule()
    while True:
        schedule.tick(time.monotonic(), launch)
        time.sleep(1)

if __name__ == "__main__":
    main()
