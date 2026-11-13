#!/usr/bin/env python3
"""Default-closed, source-restricted SSH windows authorized by the operator API."""
import fcntl
import ipaddress
import json
import os
import re
import stat
import subprocess
import sys
import tempfile
import time
import local_manager_policy

CONF = "/etc/tarasecfw.conf"
POLICY = "/etc/tarasec-server-manager.conf"
STATE = "/run/tarasec/ssh-google-window.json"
LOCK = "/run/tarasec/ssh-google-window.lock"
CHAIN = "TARASEC_SSH_GOOGLE"
COMMENT = "tarasec-google-ssh-rebuild"
MIGRATION_PENDING = "/var/lib/tarasec/ssh-port-migration/pending.json"


def settings(path):
    result = {}
    with open(path, encoding="utf-8") as source:
        for line in source:
            match = re.match(r"^\s*([A-Z_]+)\s*=\s*(.*?)\s*(?:#.*)?$", line)
            if match:
                result[match[1]] = match[2].strip("'\"")
    return result


def configuration(require_enabled=True, *, allow_empty_sources=False):
    policy = settings(POLICY)
    if require_enabled and policy.get("SSH_GOOGLE_REOPEN_ENABLED", "no").lower() not in ("yes", "true", "on", "1"):
        raise RuntimeError("Google-controlled SSH is not enabled by the owner")
    cfg = settings(CONF)
    port = int(cfg.get("SSH_PORT", "22"))
    if not 1 <= port <= 65535:
        raise ValueError("Invalid admin SSH port")
    sources = [ipaddress.ip_network(s.strip(), strict=False)
               for s in cfg.get("SSH_ALLOWED_SOURCES", "").split(",") if s.strip()]
    if not sources and not allow_empty_sources:
        raise ValueError("Set SSH_ALLOWED_SOURCES in /etc/tarasecfw.conf before opening SSH")
    if len(sources) > 64 or any(s.prefixlen == 0 for s in sources):
        raise ValueError("Explicit SSH_ALLOWED_SOURCES required; unrestricted sources refused")
    return port, sources


def command(*args, check=True):
    result = subprocess.run(args, capture_output=True, text=True, timeout=15)
    if check and result.returncode:
        raise RuntimeError("SSH firewall operation failed: " + args[0] + ": " + result.stderr.strip()[:500])
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


def decoy_enabled():
    return os.path.exists(CONF) and settings(CONF).get("SSH_CLOSED_HONEYPOT", "no").lower() in ("yes", "true", "on", "1")


def apply_rules(port, sources, until):
    # Guard both families before rebuilding. Failures leave new connections
    # rejected. Never flush INPUT, change policy or touch NAT/FORWARD.
    families = [("iptables", 4), ("ip6tables", 6)]
    guard = ["-p", "tcp", "--dport", str(port), "-m", "conntrack",
             "--ctstate", "NEW", "-m", "comment", "--comment", COMMENT,
             "-j", "REJECT", "--reject-with", "tcp-reset"]
    for tool, _ in families:
        if not command(tool, "-w", "5", "-C", "INPUT", *guard, check=False):
            command(tool, "-w", "5", "-I", "INPUT", "1", *guard)
    decoys = decoy_enabled()
    if decoys:
        import ssh_honeypot_route
        ssh_honeypot_route.prepare()
    for tool, version in families:
        command(tool, "-w", "5", "-N", CHAIN, check=False)
        command(tool, "-w", "5", "-F", CHAIN)
        if until > time.time():
            for source in sources:
                if source.version == version:
                    extra = ["-p", "tcp", "-m", "set", "--match-set", ssh_honeypot_route.SETS[version], "src,dst"] if decoys else []
                    command(tool, "-w", "5", "-A", CHAIN, "-s", str(source), *extra, "-j", "RETURN")
        command(tool, "-w", "5", "-A", CHAIN, "-p", "tcp", "-j", "REJECT", "--reject-with", "tcp-reset")
        jump = ["-p", "tcp", "--dport", str(port), "-m", "conntrack",
                "--ctstate", "NEW", "-j", CHAIN]
        while command(tool, "-w", "5", "-C", "INPUT", *jump, check=False):
            command(tool, "-w", "5", "-D", "INPUT", *jump)
        command(tool, "-w", "5", "-I", "INPUT", "1", *jump)
    if decoys:
        ssh_honeypot_route.lease(port, sources, until)
    for tool, _ in families:
        while command(tool, "-w", "5", "-C", "INPUT", *guard, check=False):
            command(tool, "-w", "5", "-D", "INPUT", *guard)


def migration_blocked(port):
    try:
        info = os.lstat(MIGRATION_PENDING)
    except FileNotFoundError:
        return False
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022 or info.st_size > 8192:
        raise RuntimeError("Unsafe SSH port migration state; local recovery required")
    with open(MIGRATION_PENDING, encoding="utf-8") as source:
        pending = json.load(source)
    if not isinstance(pending, dict):
        raise RuntimeError("Invalid SSH port migration state; local recovery required")
    if pending.get("phase") in ("staging", "rollback", "console_recovery_required"):
        return True
    # Older pending receipts have no phase. Identify their fail-closed guards.
    guard = ["-p", "tcp", "--dport", str(port), "-m", "conntrack", "--ctstate", "NEW",
             "-m", "comment", "--comment", "tarasec-port-rollback-guard",
             "-j", "REJECT", "--reject-with", "tcp-reset"]
    return any(command(tool, "-w", "5", "-C", "INPUT", *guard, check=False)
               for tool in ("iptables", "ip6tables"))


def reconcile():
    port, sources = configuration(allow_empty_sources=True)
    state = read_state()
    until = int(state.get("until", 0))
    if not sources or migration_blocked(port):
        if until:
            state.update({"until": 0, "closed_at": int(time.time())})
            write_state(state)
        until = 0
    if until > time.time():
        try:
            local_manager_policy.require_manager(state.get("approved_operator_sha256"))
        except (OSError, ValueError, RuntimeError):
            until = 0
    apply_rules(port, sources, until)


def open_window(job_id, until, operator_hash=None, approved_at=None):
    local_manager_policy.require_manager(operator_hash)
    port, sources = configuration()
    if migration_blocked(port):
        raise RuntimeError("SSH port recovery is incomplete; use ssh_port_setup.py --recover-current from the console or finish the pending rollback before requesting a new window")
    now = int(time.time())
    if (isinstance(until, bool) or not isinstance(until, int)
            or not re.fullmatch(r"[a-f0-9]{32}", job_id) or not now < until <= now + 900):
        raise ValueError("Expired or invalid approved window")
    state = read_state()
    closed_at = int(state.get("closed_at", 0))
    if closed_at and (isinstance(approved_at, bool) or not isinstance(approved_at, int) or approved_at <= closed_at):
        raise ValueError("Opening predates the last explicit SSH closure")
    if state.get("id") == job_id:
        if int(state.get("until", 0)) != until or state.get("approved_operator_sha256") != operator_hash:
            raise ValueError("Window retry changed deadline")
        reconcile()
        return
    if int(state.get("until", 0)) > now:
        raise RuntimeError("Another SSH window is active")
    if decoy_enabled():
        import ssh_honeypot_route
        ssh_honeypot_route.require_quiet(port)
    apply_rules(port, sources, 0)
    # Schedule independent closure BEFORE opening. Retries retain the deadline.
    command("systemd-run", "--quiet", "--collect",
            "--unit=tarasec-ssh-window-" + job_id,
            "--on-calendar=@%d" % until, "--timer-property=AccuracySec=1s",
            "--timer-property=RandomizedDelaySec=0",
            "/usr/bin/python3", "/usr/local/lib/tarasec/ssh_google_window.py", "close", job_id)
    write_state({"id": job_id, "until": until, "port": port, "approved_operator_sha256": operator_hash, "closed_at": closed_at})
    try:
        apply_rules(port, sources, until)
    except Exception:
        write_state({"id": job_id, "until": 0, "port": port, "closed_at": int(state.get("closed_at", 0))})
        apply_rules(port, sources, 0)
        raise


def close_window(job_id):
    state = read_state()
    if state.get("id") != job_id:
        return
    port = int(state["port"])
    if not 1 <= port <= 65535:
        raise ValueError("Invalid saved SSH port")
    write_state({"id": job_id, "until": 0, "port": port, "closed_at": int(state.get("closed_at", 0))})
    apply_rules(port, [], 0)


def lock_now(job_id, operator_hash):
    local_manager_policy.require_manager(operator_hash)
    if not re.fullmatch(r"[a-f0-9]{32}", job_id):
        raise ValueError("Invalid closure job")
    port, _ = configuration(allow_empty_sources=True)
    state = read_state()
    # Persist revocation before changing either firewall family; reconciliation
    # then retains the closed policy if a command fails partway through.
    closed_at = int(state.get("closed_at", 0)) if state.get("id") == job_id else int(time.time())
    write_state({"id": job_id, "until": 0, "port": port, "closed_at": closed_at})
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
        elif len(sys.argv) in (5, 6) and sys.argv[1] == "open":
            open_window(sys.argv[2], int(sys.argv[3]), sys.argv[4], int(sys.argv[5]) if len(sys.argv) == 6 else None)
        elif len(sys.argv) == 4 and sys.argv[1] == "lock":
            lock_now(sys.argv[2], sys.argv[3])
        elif len(sys.argv) == 3 and sys.argv[1] == "close":
            close_window(sys.argv[2])
        else:
            raise ValueError("Expected reconcile, open ID DEADLINE OPERATOR_SHA256 [APPROVED_AT], lock ID OPERATOR_SHA256, or close ID")


if __name__ == "__main__":
    main()

