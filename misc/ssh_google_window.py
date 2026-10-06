#!/usr/bin/env python3
"""Default-closed, source-restricted SSH windows authorized by the operator API."""
import fcntl
import ipaddress
import json
import os
import re
import subprocess
import sys
import tempfile
import time

CONF = "/etc/tarasecfw.conf"
POLICY = "/etc/tarasec-server-manager.conf"
STATE = "/run/tarasec/ssh-google-window.json"
LOCK = "/run/tarasec/ssh-google-window.lock"
CHAIN = "TARASEC_SSH_GOOGLE"
COMMENT = "tarasec-google-ssh-rebuild"

def settings(path):
    result = {}
    with open(path, encoding="utf-8") as source:
        for line in source:
            match = re.match(r"^\s*([A-Z_]+)\s*=\s*(.*?)\s*(?:#.*)?$", line)
            if match:
                result[match[1]] = match[2].strip("'\"")
    return result

def configuration(require_enabled=True):
    policy = settings(POLICY)
    if require_enabled and policy.get("SSH_GOOGLE_REOPEN_ENABLED", "no").lower() not in ("yes", "true", "on", "1"):
        raise RuntimeError("Google-controlled SSH is not enabled by the owner")
    cfg = settings(CONF)
    port = int(cfg.get("SSH_PORT", "22"))
    if not 1 <= port <= 65535:
        raise ValueError("Invalid admin SSH port")
    sources = [ipaddress.ip_network(s.strip(), strict=False)
               for s in cfg.get("SSH_ALLOWED_SOURCES", "").split(",") if s.strip()]
    if not sources or len(sources) > 64 or any(s.prefixlen == 0 for s in sources):
        raise ValueError("Explicit SSH_ALLOWED_SOURCES required; unrestricted sources refused")
    return port, sources

def command(*args, check=True):
    result = subprocess.run(args, capture_output=True, text=True, timeout=15)
    if check and result.returncode:
        raise RuntimeError("SSH firewall operation failed: " + args[0])
    return result.returncode == 0

def read_state():
    try:
        with open(STATE, encoding="utf-8") as source:
            state = json.load(source)
        if not isinstance(state, dict):
            raise ValueError("Invalid window state")
        return state
    except FileNotFoundError:
        return {}

def write_state(state):
    fd, temporary = tempfile.mkstemp(dir=os.path.dirname(STATE))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as target:
            os.fchmod(target.fileno(), 0o600)
            json.dump(state, target)
        os.replace(temporary, STATE)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

def apply_rules(port, sources, until):
    # Install guards in BOTH families before rebuilding. A failure leaves guards
    # in place. No INPUT flush, policy change, NAT or FORWARD mutation.
    families = [("iptables", 4), ("ip6tables", 6)]
    guard = ["-p", "tcp", "--dport", str(port), "-m", "conntrack",
             "--ctstate", "NEW", "-m", "comment", "--comment", COMMENT,
             "-j", "REJECT", "--reject-with", "tcp-reset"]
    for tool, _ in families:
        if not command(tool, "-w", "5", "-C", "INPUT", *guard, check=False):
            command(tool, "-w", "5", "-I", "INPUT", "1", *guard)
    for tool, version in families:
        command(tool, "-w", "5", "-N", CHAIN, check=False)
        command(tool, "-w", "5", "-F", CHAIN)
        if until > time.time():
            for source in sources:
                if source.version == version:
                    command(tool, "-w", "5", "-A", CHAIN, "-s", str(source), "-j", "RETURN")
        command(tool, "-w", "5", "-A", CHAIN, "-j", "REJECT", "--reject-with", "tcp-reset")
        jump = ["-p", "tcp", "--dport", str(port), "-m", "conntrack",
                "--ctstate", "NEW", "-j", CHAIN]
        # Remove the old hook and reinsert ahead of broad VPN ACCEPT rules.
        while command(tool, "-w", "5", "-C", "INPUT", *jump, check=False):
            command(tool, "-w", "5", "-D", "INPUT", *jump)
        command(tool, "-w", "5", "-I", "INPUT", "1", *jump)
    for tool, _ in families:
        while command(tool, "-w", "5", "-C", "INPUT", *guard, check=False):
            command(tool, "-w", "5", "-D", "INPUT", *guard)

def reconcile():
    port, sources = configuration()
    state = read_state()
    apply_rules(port, sources, int(state.get("until", 0)))

def open_window(job_id, until):
    port, sources = configuration()
    now = int(time.time())
    if not re.fullmatch(r"[a-f0-9]{32}", job_id) or not now < until <= now + 900:
        raise ValueError("Expired or invalid approved window")
    state = read_state()
    if state.get("id") == job_id:
        # A delivery retry never extends the approved deadline.
        if int(state.get("until", 0)) != until:
            raise ValueError("Window retry changed deadline")
        reconcile()
        return
    if int(state.get("until", 0)) > now:
        raise RuntimeError("Another SSH window is active")
    apply_rules(port, sources, 0)
    # Schedule closure BEFORE opening. The unique unit prevents retry collisions.
    command("systemd-run", "--quiet", "--collect",
            "--unit=tarasec-ssh-window-" + job_id,
            "--on-active=%ds" % max(1, until-int(time.time())),
            "/usr/bin/python3", "/usr/local/lib/tarasec/ssh_google_window.py", "close", job_id)
    write_state({"id": job_id, "until": until, "port": port})
    try:
        apply_rules(port, sources, until)
    except Exception:
        write_state({"id": job_id, "until": 0, "port": port})
        apply_rules(port, sources, 0)
        raise

def close_window(job_id):
    state = read_state()
    # An old timer must never close a later, independently approved window.
    if state.get("id") != job_id:
        return
    port = int(state["port"])
    if not 1 <= port <= 65535:
        raise ValueError("Invalid saved SSH port")
    write_state({"id": job_id, "until": 0, "port": port})
    apply_rules(port, [], 0)

def main():
    if os.geteuid() != 0:
        raise RuntimeError("Run as root")
    os.makedirs("/run/tarasec", mode=0o755, exist_ok=True)
    with open(LOCK, "a", encoding="ascii") as lock:
        os.chmod(LOCK, 0o600)
        fcntl.flock(lock, fcntl.LOCK_EX)
        if sys.argv[1:] == ["reconcile"]:
            reconcile()
        elif len(sys.argv) == 4 and sys.argv[1] == "open":
            open_window(sys.argv[2], int(sys.argv[3]))
        elif len(sys.argv) == 3 and sys.argv[1] == "close":
            close_window(sys.argv[2])
        else:
            raise ValueError("Expected reconcile, open ID DEADLINE, or close ID")
if __name__ == "__main__":
    main()
