#!/usr/bin/env python3
import json, os, random, shutil, socket, subprocess, sys, time
from urllib import request, error

VERSION = "0.1"
MAX_RESPONSE = 4096
MAX_REPORT = 16384

def read_conf(path):
    cfg = {}
    with open(path, encoding="utf-8") as f:
        for raw in f:
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            cfg[k.strip()] = v.strip()
    return cfg

def meminfo():
    out = {}
    try:
        with open("/proc/meminfo", encoding="ascii") as f:
            for line in f:
                key, value = line.split(":", 1)
                parts = value.strip().split()
                if parts and parts[0].isdigit(): out[key] = int(parts[0])
    except OSError: pass
    return out

def uptime():
    try:
        return int(float(open("/proc/uptime", encoding="ascii").read().split()[0]))
    except Exception:
        return None

def update_count():
    # Bounded, read-only check. Failure simply omits the field.
    if not shutil.which("apt-get"): return None
    try:
        p = subprocess.run(["apt-get", "-s", "upgrade"], capture_output=True, text=True, timeout=15)
        return sum(1 for line in p.stdout.splitlines() if line.startswith("Inst "))
    except Exception:
        return None

def build_report(role):
    m = meminfo()
    try: l1, l5, l15 = os.getloadavg()
    except OSError: l1 = l5 = l15 = None
    try:
        disk = shutil.disk_usage("/")
        disk_pct = round((disk.used * 100.0) / disk.total, 1) if disk.total else None
    except OSError: disk_pct = None
    report = {
        "agent": "tarasec-server-agent", "agentVersion": VERSION, "role": role,
        "hostname": socket.gethostname()[:128], "uptimeSeconds": uptime(),
        "load1": l1, "load5": l5, "load15": l15,
        "memoryAvailableKb": m.get("MemAvailable"), "memoryTotalKb": m.get("MemTotal"),
        "diskRootUsedPercent": disk_pct,
        "bootReq": os.path.exists("/var/run/reboot-required")
    }
    updates = update_count()
    if updates is not None: report["updates"] = updates
    return {k: v for k, v in report.items() if v is not None}

def send(url, token, report):
    body = json.dumps(report, separators=(",", ":")).encode()
    if len(body) > MAX_REPORT: raise RuntimeError("report exceeds size limit")
    headers = {"Content-Type": "application/json", "User-Agent": "TaraSec-Server-Agent/" + VERSION}
    if token: headers["Authorization"] = "Bearer " + token
    req = request.Request(url, data=body, headers=headers, method="POST")
    with request.urlopen(req, timeout=10) as response:
        reply = response.read(MAX_RESPONSE + 1)
        if len(reply) > MAX_RESPONSE: raise RuntimeError("receiver response too large")
        if response.status < 200 or response.status >= 300: raise RuntimeError("receiver HTTP %d" % response.status)

def main():
    path = sys.argv[1] if len(sys.argv) > 1 else "/etc/tarasec-server-status.conf"
    cfg = read_conf(path)
    url = cfg.get("DB_STATUS_URL", "")
    if not url.startswith(("https://", "http://")): raise SystemExit("DB_STATUS_URL must be http(s)")
    role = cfg.get("ROLE", "vm")[:64]
    token = cfg.get("TOKEN", "")
    # Jitter avoids synchronized fleets. One-shot design lets systemd enforce cadence.
    jitter = min(max(int(cfg.get("JITTER_SECONDS", "20")), 0), 45)
    if jitter: time.sleep(random.randint(0, jitter))
    try:
        send(url, token, build_report(role))
    except (error.URLError, OSError, RuntimeError) as exc:
        print("TaraSec status report failed: %s" % exc, file=sys.stderr)
        return 1
    return 0

if __name__ == "__main__": raise SystemExit(main())
