#!/usr/bin/env python3
"""TaraSec node manager: assessment, reporting and tightly bounded actions."""

import json
import os
import re
import signal
import subprocess
import sys
import time
import urllib.error
import urllib.request

import ssh_security_evidence

CONF = "/etc/tarasecfw.conf"
MANAGER_CONF = "/etc/tarasec-server-manager.conf"
TOKEN_PATH = "/etc/tarasec/agent-node.token"
URL = "https://tarasec.org/ops/agent/api.php"
AUDIT_PATH = "/var/log/tarasec/server-manager-actions.jsonl"
CONTAINMENT_STATE = "/run/tarasec/ssh-containment.json"
CONTAINMENT_COMMENT = "tarasec-ai-temporary-ssh-containment"


def setting(name, path=CONF):
    try:
        with open(path, encoding="utf-8") as source:
            for line in source:
                match = re.match(r"^\s*" + re.escape(name) + r"\s*=\s*(.*?)\s*(?:#.*)?$", line)
                if match:
                    return match.group(1).strip().strip("'\"")
    except OSError:
        pass
    return ""


def manager_setting(name, default=""):
    return setting(name, MANAGER_CONF) or default


def enabled(name, default="no"):
    return manager_setting(name, default).lower() in ("1", "yes", "true", "on")


def bounded_int(name, default, minimum, maximum):
    try:
        value = int(manager_setting(name, str(default)))
    except ValueError:
        value = default
    return min(max(value, minimum), maximum)


def audit(event):
    record = {"at": int(time.time()), **event}
    os.makedirs(os.path.dirname(AUDIT_PATH), mode=0o750, exist_ok=True)
    with open(AUDIT_PATH, "a", encoding="utf-8") as target:
        target.write(json.dumps(record, separators=(",", ":"), sort_keys=True) + "\n")


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


def terminal_state():
    declared = enabled("TERMINAL_AVAILABLE")
    heartbeat = manager_setting("TERMINAL_HEARTBEAT_FILE", "/run/tarasec/operator-terminal.heartbeat")
    max_age = bounded_int("TERMINAL_HEARTBEAT_MAX_AGE_SECONDS", 120, 30, 3600)
    age = None
    try:
        age = max(0, int(time.time() - os.stat(heartbeat).st_mtime))
    except OSError:
        pass
    return {"declared": declared, "verified": declared and age is not None and age <= max_age,
            "heartbeat_age_seconds": age, "maximum_age_seconds": max_age}


def ssh_attack_evidence():
    """Return bounded aggregate evidence; never transmit usernames or source addresses."""
    window = bounded_int("SSH_ATTACK_WINDOW_SECONDS", 120, 60, 900)
    result = run("journalctl", "-u", "ssh.service", "-u", "sshd.service",
                 "--since", "%d seconds ago" % window, "-n", "10000",
                 "--no-pager", "-o", "cat")
    patterns = re.compile(r"failed password|invalid user|authentication failure|maximum authentication attempts",
                          re.IGNORECASE)
    failures = sum(1 for line in result.stdout.splitlines() if patterns.search(line))
    threshold = bounded_int("SSH_ATTACK_FAILURE_THRESHOLD", 20, 5, 10000)
    return {"window_seconds": window, "authentication_failures": failures,
            "threshold": threshold, "ongoing": result.returncode == 0 and failures >= threshold}


def forwarding_health(evidence):
    is_gateway = evidence["tarasecfw_selected_fields"].get("IS_GATEWAY") == "1"
    return {"is_gateway": is_gateway,
            "ip_forward": evidence.get("ip_forward") == "1" if is_gateway else None,
            "gateway_service_active": "ActiveState=active" in
                evidence["services"]["tarasec-gateway.service"].get("stdout", "") if is_gateway else None}


def assessment(evidence, concerns, terminal, attack, actions):
    forwarding = forwarding_health(evidence)
    priority = "contain_intrusion" if attack["ongoing"] else (
        "preserve_forwarding" if forwarding["is_gateway"] else "monitor")
    return {
        "schema_version": 1,
        "prompt_version": "server-manager-v1",
        "knowledge_revision": "server-manager-core-v1",
        "agent_mode": manager_setting("AI_AGENT_MODE", "conservative"),
        "priority": priority,
        "summary": ("Ongoing SSH authentication attack detected" if attack["ongoing"] else
                    ("Configuration or service findings need review" if concerns else "No issue found by bounded checks")),
        "confidence": "high" if attack["ongoing"] or not concerns else "medium",
        "ssh_attack": attack,
        "terminal": terminal,
        "forwarding": forwarding,
        "findings": concerns,
        "actions_taken": actions,
    }


def containment_rule(port, delete=False):
    action = "-D" if delete else "-I"
    argv = ["iptables", action, "INPUT"]
    if not delete:
        argv.append("1")
    argv += ["-p", "tcp", "--dport", str(port), "-m", "conntrack", "--ctstate", "NEW",
             "-m", "comment", "--comment", CONTAINMENT_COMMENT,
             "-j", "REJECT", "--reject-with", "tcp-reset"]
    return run(*argv)


def rollback_containment():
    try:
        with open(CONTAINMENT_STATE, encoding="utf-8") as source:
            state = json.load(source)
    except (OSError, ValueError):
        return False
    result = containment_rule(int(state["port"]), delete=True)
    if result.returncode == 0:
        try:
            os.unlink(CONTAINMENT_STATE)
        except OSError:
            pass
        audit({"action": "ssh_containment_rollback", "success": True})
        return True
    return False


def contain_ssh(evidence, terminal, attack):
    if not enabled("AI_MAY_CLOSE_SSH_DURING_ACTIVE_ATTACK") or not terminal["verified"] or not attack["ongoing"]:
        return None
    if os.path.exists(CONTAINMENT_STATE):
        return {"action": "ssh_new_connections_blocked", "result": "already_active"}
    port = int(evidence["tarasecfw_selected_fields"].get("SSH_PORT", "22"))
    duration = bounded_int("SSH_CONTAINMENT_SECONDS", 600, 60, 3600)
    before = forwarding_health(evidence)
    result = containment_rule(port)
    if result.returncode != 0:
        audit({"action": "ssh_new_connections_blocked", "success": False,
               "error": result.stderr[:300]})
        return {"action": "ssh_new_connections_blocked", "result": "failed"}
    os.makedirs(os.path.dirname(CONTAINMENT_STATE), mode=0o755, exist_ok=True)
    with open(CONTAINMENT_STATE, "w", encoding="utf-8") as target:
        json.dump({"port": port, "expires": int(time.time()) + duration}, target)
    timer = run("systemd-run", "--quiet", "--collect", "--unit=tarasec-ssh-containment-rollback",
                "--on-active=%ds" % duration, "/usr/bin/python3",
                "/usr/local/lib/tarasec/agent_approval_worker.py", "--rollback-ssh-containment")
    if timer.returncode != 0:
        containment_rule(port, delete=True)
        try:
            os.unlink(CONTAINMENT_STATE)
        except OSError:
            pass
        audit({"action": "ssh_new_connections_blocked", "success": False,
               "error": "automatic rollback could not be scheduled"})
        return {"action": "ssh_new_connections_blocked", "result": "failed_safe"}
    after = forwarding_health(ssh_security_evidence.collect())
    action = {"action": "ssh_new_connections_blocked", "result": "applied",
              "reason": "ongoing_attack", "existing_sessions_interrupted": False,
              "duration_seconds": duration, "forwarding_before": before,
              "forwarding_after": after}
    audit({**action, "success": True})
    return action


def established_ssh_session_pids(port):
    """Find sshd processes owning established sockets on the admin port."""
    result = run("ss", "-Htnp", "state", "established")
    if result.returncode != 0:
        raise RuntimeError("Could not inspect established SSH sessions")
    pids = set()
    for line in result.stdout.splitlines():
        columns = line.split()
        if len(columns) < 5 or columns[2].rsplit(":", 1)[-1] != str(port):
            continue
        for match in re.finditer(r'\("sshd",pid=([0-9]+),', line):
            pid = int(match.group(1))
            if pid > 1:
                pids.add(pid)
    return sorted(pids)


def terminate_existing_ssh_sessions(evidence, terminal, attack):
    if (not enabled("AI_MAY_TERMINATE_EXISTING_SSH_SESSIONS")
            or not terminal["verified"] or not attack["ongoing"]):
        return None
    port = int(evidence["tarasecfw_selected_fields"].get("SSH_PORT", "22"))
    try:
        pids = established_ssh_session_pids(port)
    except RuntimeError as exc:
        audit({"action": "terminate_existing_ssh_sessions", "success": False,
               "error": str(exc)})
        return {"action": "terminate_existing_ssh_sessions", "result": "inspection_failed",
                "sessions_terminated": 0}
    terminated = 0
    for pid in pids:
        try:
            os.kill(pid, signal.SIGTERM)
            terminated += 1
        except ProcessLookupError:
            continue
        except OSError as exc:
            audit({"action": "terminate_existing_ssh_sessions", "success": False,
                   "sessions_terminated": terminated, "error": str(exc)})
            return {"action": "terminate_existing_ssh_sessions", "result": "partially_applied",
                    "sessions_terminated": terminated}
    action = {"action": "terminate_existing_ssh_sessions", "result": "applied",
              "reason": "ongoing_attack_with_verified_console",
              "sessions_terminated": terminated,
              "forwarding_after": forwarding_health(ssh_security_evidence.collect())}
    audit({**action, "success": True})
    return action


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
    if len(sys.argv) == 2 and sys.argv[1] == "--rollback-ssh-containment":
        return 0 if rollback_containment() else 1
    token = open(TOKEN_PATH, encoding="ascii").read().strip()
    if not re.fullmatch(r"[a-f0-9]{64}", token):
        raise RuntimeError("Invalid node token")
    nickname = setting("AGENT_PUBLIC_NICKNAME")
    if not re.fullmatch(r"[A-Za-z][A-Za-z0-9 _-]{2,31}", nickname):
        raise RuntimeError("Set AGENT_PUBLIC_NICKNAME in /etc/tarasecfw.conf")
    evidence = ssh_security_evidence.collect()
    concerns = health(evidence)
    terminal = terminal_state()
    attack = ssh_attack_evidence()
    actions = []
    contained = contain_ssh(evidence, terminal, attack)
    if contained:
        actions.append(contained)
    terminated = terminate_existing_ssh_sessions(evidence, terminal, attack)
    if terminated:
        actions.append(terminated)
    current_assessment = assessment(evidence, concerns, terminal, attack, actions)
    needs_attention = bool(concerns or attack["ongoing"] or actions)
    api("heartbeat", {"nickname": nickname,
                      "level": "attention" if needs_attention else "ok",
                      "findings": concerns,
                      "assessment": current_assessment}, token)
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
        result = main()
        if isinstance(result, int):
            sys.exit(result)
    except (OSError, ValueError, RuntimeError, urllib.error.URLError) as exc:
        print("Agent unavailable: " + str(exc), file=sys.stderr)
        sys.exit(1)
