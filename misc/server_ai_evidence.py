"""Collect bounded, redacted log and access evidence for operator AI reports.

Each run covers the journal window since the previous successful submission.
Nothing leaves the node in raw form: secrets, e-mail addresses and long tokens
are masked, public IP addresses become per-node pseudonyms, and repeated
messages are grouped into templates with counts.
"""

import base64
import hashlib
import hmac
import ipaddress
import json
import os
import pwd
import re
import secrets
import shutil
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path

STATE_PATH = Path("/var/lib/tarasec-server-ai-report/state.json")
FIRST_WINDOW = 3600
MAX_WINDOW = 7 * 86400
MAX_ROWS = 20000
TOPIC_BYTES = 16000
MAX_SEEN = 5000
MESSAGE_CHARS = 300

SHARED_NET = ipaddress.ip_network("100.64.0.0/10")
SECRET_KV = re.compile(
    r"(?i)\b(pass(?:word|wd|phrase)?|token|secret|api[_-]?key|authorization|bearer|cookie)"
    r"(\s*[=:]\s*|\s+)(\S+)")
EMAIL = re.compile(r"[\w.+-]+@[\w-]+(?:\.[\w-]+)+")
IPV4 = re.compile(r"(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])")
IPV6 = re.compile(r"(?<![\w:])(?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4}(?![\w:])")
LONG_TOKEN = re.compile(r"(?<![\w/+-])(?=[A-Za-z0-9+_-]*\d)(?=[A-Za-z0-9+_-]*[A-Za-z])"
                        r"[A-Za-z0-9+_-]{24,}={0,2}(?![\w/+-])")
FIREWALL = re.compile(r"\bIN=\S* OUT=\S*")

AUTH_TAGS = ("sshd", "sshd-session", "sudo", "su", "useradd", "usermod", "userdel",
             "groupadd", "groupmod", "groupdel", "gpasswd", "passwd", "chpasswd",
             "chage", "tarasec-ssh-honeypot")
ACCOUNT_TAGS = {"useradd", "usermod", "userdel", "groupadd", "groupmod", "groupdel",
                "gpasswd", "passwd", "chpasswd", "chage"}
ACCEPTED = re.compile(r"^Accepted (\S+) for (\S+) from (\S+) port \d+")
FAILED = re.compile(r"^Failed (\S+) for (invalid user )?(\S*) from (\S+) port \d+")
INVALID = re.compile(r"^Invalid user (\S*) from (\S+) port \d+")
NOT_ALLOWED = re.compile(r"^User (\S+) from (\S+) not allowed because (.+?)\s*$")
SU_OPENED = re.compile(r"^pam_unix\(su(?:-l)?:session\): session opened for user (\S+?)\(uid=\d+\) by (\S*?)\(uid=")
SU_FAILED = re.compile(r"^pam_unix\(su(?:-l)?:auth\): authentication failure.*?\bruser=(\S*)")
KEY_TYPES = ("ssh-", "ecdsa-", "sk-")


class Redactor:
    def __init__(self, key):
        self.key = bytes.fromhex(key)

    def ip(self, value):
        try:
            addr = ipaddress.ip_address(value.split("%")[0])
        except ValueError:
            return value
        if (addr.is_private or addr.is_loopback or addr.is_link_local
                or (addr.version == 4 and addr in SHARED_NET)):
            return str(addr)
        return "pub-" + hmac.new(self.key, addr.packed, hashlib.sha256).hexdigest()[:10]

    def _ip_sub(self, match):
        return self.ip(match.group(0))

    def text(self, message):
        message = message.replace("\n", " ")[:2000]
        message = SECRET_KV.sub(lambda m: m.group(1) + m.group(2) + "<redacted>", message)
        message = EMAIL.sub("<email>", message)
        message = IPV4.sub(self._ip_sub, message)
        message = IPV6.sub(self._ip_sub, message)
        message = LONG_TOKEN.sub("<token>", message)
        return message[:MESSAGE_CHARS]


def template(redacted):
    text = re.sub(r"\bpub-[0-9a-f]{10}\b", "<ip>", redacted)
    text = IPV4.sub("<ip>", text)
    text = re.sub(r"\b0x[0-9a-fA-F]+\b", "<hex>", text)
    text = re.sub(r"\b[0-9a-f]{8,}\b", "<hex>", text)
    return re.sub(r"\d+", "N", text)


def iso(usec):
    try:
        stamp = int(usec) / 1e6
    except (TypeError, ValueError):
        return None
    return datetime.fromtimestamp(stamp, timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def journal(since, until, args=(), run=subprocess.run):
    command = ["journalctl", "--no-pager", "-q", "-o", "json",
               f"--since=@{int(since)}", f"--until=@{int(until)}", *args]
    output = run(command, capture_output=True, text=True, timeout=90, check=True).stdout
    rows, truncated = [], False
    for line in output.splitlines():
        if len(rows) >= MAX_ROWS:
            truncated = True
            break
        try:
            row = json.loads(line)
        except ValueError:
            continue
        message = row.get("MESSAGE")
        if isinstance(message, list):
            message = bytes(b & 0xFF for b in message if isinstance(b, int)).decode("utf-8", "replace")
        if isinstance(message, str):
            row["MESSAGE"] = message
            rows.append(row)
    return rows, truncated


def fit(topic, limit=TOPIC_BYTES):
    """Drop entries from the longest lists until the topic fits the byte budget."""
    def size():
        return len(json.dumps(topic, separators=(",", ":")))
    while size() > limit:
        lists = [(len(v), k) for k, v in topic.items() if isinstance(v, list) and v]
        lists += [(len(v), (k, k2)) for k, sub in topic.items() if isinstance(sub, dict)
                  for k2, v in sub.items() if isinstance(v, list) and v]
        if not lists:
            break
        _, where = max(lists, key=lambda item: item[0])
        target = topic[where[0]][where[1]] if isinstance(where, tuple) else topic[where]
        target.pop()
        topic["trimmed"] = True
    return topic


def logs_topic(rows, redactor, seen, now, baseline, truncated=False):
    groups = {}
    firewall = 0
    for row in rows:
        source = row.get("SYSLOG_IDENTIFIER") or row.get("_COMM") or "unknown"
        message = redactor.text(row["MESSAGE"])
        if source == "kernel" and FIREWALL.search(message):
            firewall += 1
            continue
        shape = template(message)
        stamp = iso(row.get("__REALTIME_TIMESTAMP"))
        try:
            priority = int(row.get("PRIORITY", 6))
        except ValueError:
            priority = 6
        group = groups.get((source, shape))
        if group is None:
            group = groups[(source, shape)] = {
                "source": source, "unit": row.get("UNIT") or row.get("_SYSTEMD_UNIT"),
                "priority": priority, "template": shape, "example": message,
                "count": 0, "first": stamp, "last": stamp}
        group["count"] += 1
        group["priority"] = min(group["priority"], priority)
        group["last"] = stamp or group["last"]
    for group in groups.values():
        digest = hashlib.sha256((group["source"] + "\0" + group["template"]).encode()).hexdigest()[:16]
        group["new"] = digest not in seen
        seen[digest] = int(now)
    ordered = sorted(groups.values(), key=lambda g: (not g["new"], g["priority"], -g["count"]))
    return fit({
        "baseline_run": baseline,
        "entries": sum(g["count"] for g in groups.values()),
        "distinct_messages": len(groups),
        "new_messages": sum(g["new"] for g in groups.values()),
        "firewall_log_lines": firewall,
        "read_limit_reached": truncated,
        "groups": ordered,
    })


def _sudo(message):
    head, sep, rest = message.partition(" : ")
    if not sep:
        return None
    index = rest.find("COMMAND=")
    fields = rest if index < 0 else rest[:index]
    pieces = [p.strip() for p in fields.split(";") if p.strip()]
    target = next((p[5:] for p in pieces if p.startswith("USER=")), None)
    problem = [p for p in pieces if not re.match(r"(TTY|PWD|USER|ENV|CHROOT|CWD|GROUP)=", p)]
    return {"user": head.strip(), "target": target,
            "command": rest[index + 8:].strip() if index >= 0 else None,
            "problem": "; ".join(problem) or None}


def _bump(table, key, stamp, **fields):
    entry = table.get(key)
    if entry is None:
        entry = table[key] = dict(fields, count=0, first=stamp, last=stamp)
    entry["count"] += 1
    entry["last"] = stamp or entry["last"]


def _top(table, n):
    return sorted(table.values(), key=lambda e: -e["count"])[:n]


def auth_topic(rows, redactor, local_users, key_report, truncated=False):
    accepted, failed_users, sources, not_allowed, sudo, su = {}, {}, {}, {}, {}, {}
    invalid_names, invalid_attempts, failures = set(), 0, 0
    honeypot_events, honeypot_sources = 0, set()
    accounts = []

    def who(name):
        return name if name in local_users else "<not a local account>"

    for row in rows:
        tag = row.get("SYSLOG_IDENTIFIER", "")
        message = row["MESSAGE"].strip()
        stamp = iso(row.get("__REALTIME_TIMESTAMP"))
        if tag == "tarasec-ssh-honeypot":
            honeypot_events += 1
            honeypot_sources.update(redactor.ip(ip) for ip in IPV4.findall(message))
            continue
        if tag in ACCOUNT_TAGS:
            accounts.append({"at": stamp, "tool": tag, "message": redactor.text(message)})
            continue
        if tag == "sudo":
            entry = _sudo(message)
            if entry:
                command = redactor.text(entry["command"] or "")[:160] or None
                _bump(sudo, (entry["user"], entry["target"], command, entry["problem"]), stamp,
                      user=who(entry["user"]), target=entry["target"], command=command,
                      problem=entry["problem"])
            continue
        if tag == "su":
            match = SU_OPENED.match(message)
            if match:
                _bump(su, (match[2], match[1], "ok"), stamp, user=who(match[2]), target=match[1], result="ok")
            match = SU_FAILED.match(message)
            if match:
                _bump(su, (match[1], None, "failed"), stamp, user=who(match[1]), target=None, result="failed")
            continue
        match = ACCEPTED.match(message)
        if match:
            method, user, source = match.groups()
            source = redactor.ip(source)
            _bump(accepted, (user, method, source), stamp, user=who(user), method=method, source=source)
            continue
        match = FAILED.match(message)
        if match:
            failures += 1
            _, invalid, user, source = match.groups()
            source = redactor.ip(source)
            _bump(sources, source, stamp, source=source)
            if invalid or user not in local_users:
                invalid_names.add(user)
                invalid_attempts += 1
            else:
                _bump(failed_users, user, stamp, user=user)
            continue
        match = INVALID.match(message)
        if match:
            invalid_names.add(match[1])
            continue
        match = NOT_ALLOWED.match(message)
        if match:
            user, source, reason = match.groups()
            source = redactor.ip(source)
            _bump(not_allowed, (who(user), source, reason), stamp,
                  user=who(user), source=source, reason=reason)

    return fit({
        "read_limit_reached": truncated,
        "ssh_logins": _top(accepted, 30),
        "ssh_failures": {
            "total": failures,
            "local_accounts": _top(failed_users, 20),
            "attempts_on_unknown_names": invalid_attempts,
            "distinct_unknown_names": len(invalid_names),
            "top_sources": _top(sources, 15),
            "distinct_sources": len(sources),
        },
        "ssh_not_allowed": _top(not_allowed, 15),
        "sudo": _top(sudo, 40),
        "su": _top(su, 15),
        "account_changes": accounts[:30],
        "authorized_keys": key_report,
        "honeypot": {"events": honeypot_events, "distinct_sources": len(honeypot_sources)},
    })


def _read_private(path, limit=262144):
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    except OSError:
        return None
    with os.fdopen(fd, "rb") as handle:
        return handle.read(limit).decode("utf-8", "replace")


def fingerprints(text):
    keys = []
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        for i, part in enumerate(parts[:-1]):
            if part.startswith(KEY_TYPES):
                try:
                    blob = base64.b64decode(parts[i + 1], validate=True)
                except ValueError:
                    continue
                digest = base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip("=")
                keys.append({"type": part, "fingerprint": "SHA256:" + digest, "restricted": i > 0})
                break
    return keys


def authorized_keys(users, previous):
    current, report = {}, {"baseline_run": previous is None, "accounts": []}
    for entry in users:
        found = []
        for name in ("authorized_keys", "authorized_keys2"):
            text = _read_private(os.path.join(entry.pw_dir, ".ssh", name))
            if text:
                found += fingerprints(text)
        if not found and not (previous or {}).get(entry.pw_name):
            continue
        current[entry.pw_name] = sorted({k["fingerprint"] for k in found})
        before = set((previous or {}).get(entry.pw_name, []))
        added = [k for k in found if k["fingerprint"] not in before]
        removed = sorted(before - set(current[entry.pw_name]))
        report["accounts"].append({"user": entry.pw_name, "keys": len(found),
                                   "added": added if previous is not None else [],
                                   "removed": removed,
                                   "current": found if previous is None else []})
    return report, current


def context(since, now, mount="/"):
    info = {"window": {"from": iso(since * 1e6), "to": iso(now * 1e6), "seconds": int(now - since)}}
    try:
        disk = shutil.disk_usage(mount)
        info["disk_used_percent"] = round(100 * disk.used / (disk.used + disk.free), 1)
        meminfo = dict(line.split(":", 1) for line in Path("/proc/meminfo").read_text().splitlines())
        total = int(meminfo["MemTotal"].split()[0])
        info["memory_available_percent"] = round(100 * int(meminfo["MemAvailable"].split()[0]) / total, 1)
        info["load_1m"] = float(Path("/proc/loadavg").read_text().split()[0])
        info["uptime_hours"] = round(float(Path("/proc/uptime").read_text().split()[0]) / 3600, 1)
    except (OSError, ValueError, KeyError, ZeroDivisionError):
        pass
    return info


def load_state(path=STATE_PATH):
    try:
        state = json.loads(Path(path).read_text())
        if isinstance(state, dict) and re.fullmatch(r"[0-9a-f]{64}", str(state.get("key", ""))):
            return state
    except (OSError, ValueError):
        pass
    return {"key": secrets.token_hex(32)}


def save_state(state, path=STATE_PATH):
    path = Path(path)
    seen = state.get("seen", {})
    if len(seen) > MAX_SEEN:
        state["seen"] = dict(sorted(seen.items(), key=lambda kv: -kv[1])[:MAX_SEEN])
    tmp = path.with_suffix(".tmp")
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as handle:
        json.dump(state, handle)
    os.replace(tmp, path)


def collect(topics, state, mount="/", now=None, read_journal=journal, users=None):
    """Return (report topics, updated state). Save the state only after submission."""
    now = int(now or time.time())
    since = max(int(state.get("last_until") or now - FIRST_WINDOW), now - MAX_WINDOW)
    redactor = Redactor(state["key"])
    users = users if users is not None else pwd.getpwall()
    local_users = {u.pw_name for u in users}
    new_state = dict(state, last_until=now, seen=dict(state.get("seen", {})))
    shared = context(since, now, mount)
    result = {}
    if "logs" in topics:
        rows, truncated = read_journal(since, now, ("-p", "warning"))
        result["logs"] = dict(logs_topic(rows, redactor, new_state["seen"], now,
                                         baseline=not state.get("seen"), truncated=truncated),
                              context=shared)
    if "auth" in topics:
        args = tuple(arg for tag in AUTH_TAGS for arg in ("-t", tag))
        rows, truncated = read_journal(since, now, args)
        key_report, new_state["keys"] = authorized_keys(users, state.get("keys"))
        result["auth"] = dict(auth_topic(rows, redactor, local_users, key_report, truncated),
                              context=shared)
    return result, new_state
