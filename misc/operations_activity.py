#!/usr/bin/env python3
"""Continuously aggregate reviewed demo and traffic collectors; never infer idle."""
import argparse
import json
from pathlib import Path
import time
from operations_agent import run, save, trusted


def combine(previous, demo, traffic, now, boot_id):
    def fresh(sample):
        stamp = sample.get('checked_at')
        return (isinstance(stamp, (int, float)) and not isinstance(stamp, bool)
                and 0 <= now - stamp <= 30 and sample.get('complete') is True)
    valid = (fresh(demo) and fresh(traffic) and isinstance(demo.get('active_demo'), bool)
             and isinstance(traffic.get('meaningful_traffic'), bool)
             and traffic.get('reporting_exclusion_verified') is True)
    sample = {'checked_at': now, 'complete': valid, 'active_demo': demo.get('active_demo'),
              'meaningful_traffic': traffic.get('meaningful_traffic'), 'boot_id': boot_id,
              'quiet_for_seconds': 0}
    idle = valid and not sample['active_demo'] and not sample['meaningful_traffic']
    old_stamp = previous.get('checked_at', 0)
    consecutive = (previous.get('complete') is True and previous.get('active_demo') is False
                   and previous.get('meaningful_traffic') is False
                   and previous.get('boot_id') == boot_id and 0 <= now - old_stamp <= 30)
    if idle:
        since = previous.get('quiet_since', now) if consecutive else now
        sample['quiet_since'] = since
        sample['quiet_for_seconds'] = max(0, now - since)
    return sample


def collect(command):
    result = run([str(trusted(command))], timeout=5)
    if result['exit_code'] != 0:
        return {}
    value = json.loads(result['output'])
    return value if isinstance(value, dict) else {}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--read', action='store_true')
    args = parser.parse_args()
    path = Path('/run/tarasec-operations/activity.json')
    if args.read:
        try:
            sample = json.loads(trusted(path).read_text())
            if not 0 <= time.time() - sample['checked_at'] <= 30:
                raise ValueError('Stale observer')
            print(json.dumps(sample))
        except (OSError, ValueError, KeyError):
            print(json.dumps({'checked_at': time.time(), 'complete': False}))
        return
    config = json.loads(trusted('/etc/tarasec/operations-activity.json').read_text())
    boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    previous = {}  # An observer restart always resets continuity.
    while True:
        started = time.monotonic()
        try:
            demo = collect(config['demo_collector'])
            traffic = collect(config['traffic_collector'])
        except (OSError, ValueError, KeyError):
            demo, traffic = {}, {}
        previous = combine(previous, demo, traffic, time.time(), boot)
        save(path, previous)
        time.sleep(max(1, 10 - (time.monotonic() - started)))


if __name__ == '__main__':
    main()
