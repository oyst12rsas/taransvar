#!/usr/bin/env python3
"""Run the owner's existing reporter, preserving its cwd and flock."""
import json
import os
import stat
from pathlib import Path

CONFIG = Path("/etc/tarasec/node-coordinator.json")

def trusted(path):
    for item in [path, *path.parents]:
        metadata = item.lstat()
        if stat.S_ISLNK(metadata.st_mode) or metadata.st_uid != 0 or metadata.st_mode & 0o022:
            raise ValueError("Reporter/configuration path must be root owned and not writable by others")

def main():
    trusted(CONFIG)
    reporter = Path(json.loads(CONFIG.read_text())["reporter"])
    if not reporter.is_absolute() or reporter.name != "crontasks.pl" or ".." in reporter.parts:
        raise ValueError("Invalid reporter path")
    trusted(reporter)
    os.chdir(reporter.parent)
    os.execv("/usr/bin/perl", ["/usr/bin/perl", str(reporter), "cron"])

if __name__ == "__main__":
    main()
