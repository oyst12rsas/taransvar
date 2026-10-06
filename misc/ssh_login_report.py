#!/usr/bin/env python3
"""Bounded local SSH activity for authorized installation managers."""
import datetime
import grp
import json
import os
import re
import subprocess
import tempfile
import time
from pathlib import Path

CONFIG = Path("/etc/tarasec/ssh-login-report.conf")
OUTPUT = Path("/run/tarasec-ssh-logins.json")
ACCEPTED = re.compile(r"^Accepted (publickey|password|keyboard-interactive(?:/pam)?) for (\S+) from (\S+) port (\d+)\b")
PARTIAL = re.compile(r"^Partial publickey for (\S+) from (\S+) port (\d+)\b")

def enabled():
    values = {}
    if CONFIG.exists():
        for line in CONFIG.read_text().splitlines():
            key, sep, value = line.partition("=")
            if sep and not key.lstrip().startswith("#"):
                values[key.strip()] = value.strip().strip('\\"\'').lower()
    return values.get("SSH_LOGIN_REPORT_ENABLED", "yes") in ("yes", "true", "1", "on")

def parse_records(records):
    partial = set()
    events = []
    for row in records:
        if row.get("SYSLOG_IDENTIFIER") not in ("sshd", "sshd-session"):
            continue
        msg = row.get("MESSAGE", "")
        if not isinstance(msg, str):
            continue
        identity = (row.get("_BOOT_ID"), row.get("_PID"))
        match = PARTIAL.match(msg)
        if match:
            partial.add(identity + match.groups())
            continue
        match = ACCEPTED.match(msg)
        if not match:
            continue
        method, account, source, port = match.groups()
        if method == "password" and identity + (account, source, port) in partial:
            method = "publickey+password"
        timestamp = int(row["__REALTIME_TIMESTAMP"]) / 1000000
        events.append({
            "id": row.get("__CURSOR", str(timestamp)),
            "time": datetime.datetime.fromtimestamp(timestamp, datetime.timezone.utc).isoformat(),
            "account": account, "source": source, "sourcePort": int(port),
            "authentication": method,
        })
    return events[-50:][::-1]

def collect():
    now = int(time.time())
    if not enabled():
        return {"status": "disabled", "summary": "report disabled", "checked_at": now}
    try:
        proc = subprocess.run(
            ["journalctl", "--since", "24 hours ago", "--no-pager", "-o", "json",
             "-t", "sshd", "-t", "sshd-session", "-n", "2000"],
            capture_output=True, text=True, timeout=15, check=True)
        records = [json.loads(line) for line in proc.stdout.splitlines() if line.strip()]
        return {"status": "ok", "checked_at": now, "window_hours": 24,
                "record_limit": 2000, "events": parse_records(records)}
    except (subprocess.SubprocessError, ValueError, OSError, KeyError):
        return {"status": "unavailable", "checked_at": now,
                "summary": "SSH activity collection unavailable"}

def main():
    fd, name = tempfile.mkstemp(prefix=".ssh-logins-", dir=OUTPUT.parent)
    try:
        with os.fdopen(fd, "w") as stream:
            json.dump(collect(), stream)
        os.chown(name, 0, grp.getgrnam("www-data").gr_gid)
        os.chmod(name, 0o640)
        os.replace(name, OUTPUT)
    finally:
        if os.path.exists(name):
            os.unlink(name)

if __name__ == "__main__":
    main()
