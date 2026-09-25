#!/usr/bin/env python3
"""Read-only status and an operator-approved, fixed-action TaraSec executor."""

import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request

import ssh_security_evidence

CONF = "/etc/tarasecfw.conf"
TOKEN_PATH = "/etc/tarasec/agent-node.token"
URL = "https://tarasec.org/ops/agent/api.php"


def setting(name):
    try:
        with open(CONF, encoding="utf-8") as source:
            for line in source:
                match = re.match(r"^\s*" + re.escape(name) + r"\s*=\s*(.*?)\s*(?:#.*)?$", line)
                if match:
                    return match.group(1).strip().strip("'\"")
    except OSError:
        pass
    return ""


def api(action, body, token):
    data = json.dumps(body, separators=(",", ":")).encode("utf-8")
    request = urllib.request.Request(
        URL + "?action=" + action, data=data,
        headers={"Content-Type": "application/json", "Authorization": "Bearer " + token},
        method="POST")
    with urllib.request.urlopen(request, timeout=12) as response:
        answer = json.load(response)
    if not isinstance(answer, dict) or answer.get("ok") is not True:
        raise RuntimeError("Approval API rejected " + action)
    return answer


def run(*argv):
    return subprocess.run(argv, text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=15, check=False)


def health(evidence):
    fields = evidence["tarasecfw_selected_fields"]
    port = fields.get("SSH_PORT", "22")
    trap_port = fields.get("SSH_HONEYPOT_PORT", "22")
    listeners = evidence["ssh_listeners"].get("stdout", "")
    auth = evidence["sshd_effective_selected_fields"].get("stdout", "")
    rules = evidence["filter_rules"].get("stdout", "").splitlines()
    concerns = []
    if evidence["sshd_syntax"].get("exit_code") != 0:
        concerns.append("SSH configuration invalid")
    if not any(":" + port + " " in line and "sshd" in line for line in listeners.splitlines()):
        concerns.append("SSH listener missing")
    if fields.get("SSH_HONEYPOT", "").lower() in ("on", "yes", "1"):
        if not any(":" + trap_port + " " in line and "python" in line for line in listeners.splitlines()):
            concerns.append("Honeypot listener missing")
    if "authenticationmethods any" in auth and "passwordauthentication yes" in auth:
        concerns.append("Password alone may authenticate")
    interface = fields.get("WAN_INTERFACE", "wt0")
    all_vpn = "-A INPUT -i " + interface + " -j ACCEPT"
    if all_vpn in rules:
        concerns.append("Broad VPN INPUT acceptance")
    if evidence["services"]["tarasec-gateway.service"]["stdout"].find("ActiveState=failed") >= 0:
        concerns.append("Failed local service")
    if evidence["filter_rules"].get("exit_code") != 0 or not rules:
        concerns.append("IPv4 firewall evidence unavailable")
    # An SSH listener on IPv6 needs separately inspected IPv6 firewall policy.
    ipv6_listener = any("[::]:" + port in line and "sshd" in line
                        for line in listeners.splitlines())
    if ipv6_listener:
        ipv6 = evidence.get("ipv6_filter_rules", {})
        ipv6_rules = ipv6.get("stdout", "").splitlines()
        if ipv6.get("exit_code") != 0 or not ipv6_rules:
            concerns.append("IPv6 firewall evidence unavailable")
        elif "-P INPUT ACCEPT" in ipv6_rules:
            concerns.append("IPv6 INPUT policy accepts traffic by default")
        if "-A INPUT -i " + interface + " -j ACCEPT" in ipv6_rules:
            concerns.append("Broad VPN IPv6 INPUT acceptance")
    return concerns


def obsolete_unit_candidate(evidence):
    fields = evidence["tarasecfw_selected_fields"]
    gateway = evidence["services"]["tarasec-gateway.service"]
    persistent = evidence["services"]["netfilter-persistent.service"]
    return (
        fields.get("IS_GATEWAY") == "0"
        and "ActiveState=failed" in gateway.get("stdout", "")
        and "ExecMainStatus=203" in gateway.get("stdout", "")
        and gateway.get("enabled", {}).get("stdout", "").strip() == "enabled"
        and gateway.get("executable", {}).get("executable") is False
        and persistent.get("enabled", {}).get("stdout", "").strip() == "enabled"
        and "ActiveState=active" in persistent.get("stdout", "")
    )


def disable_obsolete_gateway_unit():
    # Revalidate immediately before modifying anything. Running a service
    # from a user-writable checkout as root is never part of this operation.
    evidence = ssh_security_evidence.collect()
    gateway = evidence["services"]["tarasec-gateway.service"]
    if gateway.get("enabled", {}).get("stdout", "").strip() == "disabled":
        return True
    if not obsolete_unit_candidate(evidence):
        raise RuntimeError("Local safety checks no longer match the approved proposal")
    result = run("systemctl", "disable", "tarasec-gateway.service")
    if result.returncode != 0:
        raise RuntimeError("Could not disable obsolete unit: " + result.stderr[:300])
    run("systemctl", "reset-failed", "tarasec-gateway.service")
    return run("systemctl", "is-enabled", "tarasec-gateway.service").returncode != 0


def main():
    if os.geteuid() != 0:
        raise RuntimeError("Run as root")
    token = open(TOKEN_PATH, encoding="ascii").read().strip()
    if not re.fullmatch(r"[a-f0-9]{64}", token):
        raise RuntimeError("Invalid node token")
    nickname = setting("AGENT_PUBLIC_NICKNAME")
    if not re.fullmatch(r"[A-Za-z][A-Za-z0-9 _-]{2,31}", nickname):
        raise RuntimeError("Set AGENT_PUBLIC_NICKNAME in /etc/tarasecfw.conf")
    evidence = ssh_security_evidence.collect()
    concerns = health(evidence)
    api("heartbeat", {"nickname": nickname,
                      "level": "attention" if concerns else "ok",
                      "findings": concerns}, token)
    print("Agent status: " + ("needs review (" + str(len(concerns)) + " checks)" if concerns else "operating"))
    if obsolete_unit_candidate(evidence):
        proposal = api("propose", {"operation": "disable_obsolete_gateway_unit"}, token)
        print("Proposal " + proposal["id"] + ": " + proposal["state"])
    job = api("poll", {}, token).get("job")
    if not job:
        return
    success = False
    try:
        if job.get("operation") != "disable_obsolete_gateway_unit":
            raise RuntimeError("Unknown operation; refusing to execute")
        success = disable_obsolete_gateway_unit()
    except Exception as exc:
        print("Approved operation failed: " + str(exc), file=sys.stderr)
    api("result", {"id": job["id"], "nonce": job["nonce"], "success": success}, token)
    print("Approved operation " + ("completed" if success else "failed"))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, RuntimeError, urllib.error.URLError) as exc:
        print("Agent unavailable: " + str(exc), file=sys.stderr)
        sys.exit(1)
