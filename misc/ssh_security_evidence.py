#!/usr/bin/env python3
"""Collect read-only SSH and firewall evidence for an independent AI review.

Run as root on the node being reviewed. This tool does not fix or reload anything.
Only named, non-secret TaraSec configuration fields are included in the report.
"""

import datetime
import json
import os
import re
import socket
import subprocess


CONFIG = "/etc/tarasecfw.conf"
CONFIG_FIELDS = {
    "IS_GATEWAY", "SSH_PORT", "SSH_HONEYPOT", "SSH_HONEYPOT_PORT",
    "SSH_HONEYPOT_PORTS", "SSH_ALLOWED_SOURCES", "SSH_RECOVERY_PROTECT",
    "SSH_RECOVERY_SOURCES", "ALLOW_SSH", "WAN_INTERFACE", "LAN_INTERFACE",
}
SSH_FIELDS = {
    "port", "listenaddress", "authenticationmethods", "passwordauthentication",
    "pubkeyauthentication", "kbdinteractiveauthentication", "permitrootlogin",
    "authorizedkeysfile", "allowusers", "allowgroups", "usepam",
}
SERVICE_NAMES = ("ssh.service", "ssh.socket", "tarasec-ssh-honeypot.service",
                 "tarasec-gateway.service", "netfilter-persistent.service")


def command(*argv):
    try:
        result = subprocess.run(argv, text=True, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, timeout=12, check=False)
        return {"exit_code": result.returncode,
                "stdout": result.stdout[:40000], "stderr": result.stderr[:2000],
                "stdout_truncated": len(result.stdout) > 40000,
                "stderr_truncated": len(result.stderr) > 2000}
    except (OSError, subprocess.TimeoutExpired) as exc:
        return {"error": str(exc)}


def config_fields():
    fields = {}
    try:
        with open(CONFIG, encoding="utf-8") as source:
            for line in source:
                match = re.match(r"^\s*([A-Z_]+)\s*=\s*(.*?)\s*(?:#.*)?$", line)
                if match and match.group(1) in CONFIG_FIELDS:
                    fields[match.group(1)] = match.group(2).strip().strip("\"'")
    except OSError as exc:
        return {"error": str(exc)}
    return fields


def executable_metadata(service_info):
    match = re.search(r"(?:^|[ ;])path=([^ ;]+)", service_info)
    if not match:
        return None
    path = match.group(1)
    result = {"path": path}
    try:
        stat = os.stat(path)
        result.update(exists=True, mode=oct(stat.st_mode & 0o777),
                      owner_uid=stat.st_uid, group_gid=stat.st_gid,
                      executable=os.access(path, os.X_OK))
    except OSError as exc:
        result.update(exists=False, error=str(exc))
    return result


def collect():
    report = {
        "report": "TaraSec SSH security evidence",
        "generated_utc": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "hostname": socket.gethostname(),
        "ai_analysis_request": (
            "Review the observed state independently. Examine authentication requirements, "
            "listener ownership, firewall rule order on every interface, honeypot routing, "
            "service failures and boot persistence. Distinguish proven issues from unknowns "
            "and role-specific exceptions. Cite the exact evidence field for each finding. "
            "Suggest verification before changes; do not assume a failed unit is required."
        ),
        "tarasecfw_selected_fields": config_fields(),
        "sshd_syntax": command("/usr/sbin/sshd", "-t"),
        "ssh_listeners": command("ss", "-lntp"),
        "filter_rules": command("iptables-save", "-t", "filter"),
        "nat_rules": command("iptables-save", "-t", "nat"),
        "ip_forward": None,
        "services": {},
    }
    try:
        with open("/proc/sys/net/ipv4/ip_forward", encoding="ascii") as source:
            report["ip_forward"] = source.read().strip()
    except OSError as exc:
        report["ip_forward"] = {"error": str(exc)}
    effective = command("/usr/sbin/sshd", "-T")
    if "stdout" in effective:
        effective["stdout"] = "\n".join(
            line for line in effective["stdout"].splitlines()
            if line.split(" ", 1)[0] in SSH_FIELDS
        )
    report["sshd_effective_selected_fields"] = effective
    for name in SERVICE_NAMES:
        state = command("systemctl", "show", name, "--no-pager",
                        "-p", "LoadState", "-p", "ActiveState", "-p", "SubState",
                        "-p", "Result", "-p", "ExecMainStatus", "-p", "ExecStart")
        info = state.get("stdout", "")
        state["executable"] = executable_metadata(info)
        state["enabled"] = command("systemctl", "is-enabled", name)
        report["services"][name] = state
    return report


if __name__ == "__main__":
    if os.geteuid() != 0:
        raise SystemExit("Run as root: sudo python3 misc/ssh_security_evidence.py")
    print(json.dumps(collect(), indent=2, sort_keys=True))
