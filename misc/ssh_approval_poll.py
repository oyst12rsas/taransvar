#!/usr/bin/env python3
"""Poll approved jobs without collecting evidence or calling an AI model."""
import os
import re
import agent_approval_worker as worker
import ssh_google_window as window

def poll_once():
    if os.geteuid() != 0:
        raise RuntimeError("Run as root")
    if not worker.enabled("SSH_GOOGLE_REOPEN_ENABLED"):
        return
    with open(worker.TOKEN_PATH, encoding="ascii") as source:
        token = source.read().strip()
    if not re.fullmatch(r"[a-f0-9]{64}", token):
        raise RuntimeError("Invalid node token")
    job = worker.api("poll", {}, token).get("job")
    if not job:
        return
    success = False
    try:
        if job.get("operation") == "open_ssh_temporarily":
            until = job.get("open_until")
            if isinstance(until, bool) or not isinstance(until, int):
                raise ValueError("Invalid SSH window deadline")
            # Helper independently validates policy, allowlist, deadline and lock.
            os.makedirs("/run/tarasec", mode=0o755, exist_ok=True)
            import fcntl
            with open(window.LOCK, "a", encoding="ascii") as lock:
                os.chmod(window.LOCK, 0o600)
                fcntl.flock(lock, fcntl.LOCK_EX)
                window.open_window(job["id"], until)
            success = True
        elif job.get("operation") == "disable_obsolete_gateway_unit":
            success = worker.disable_obsolete_gateway_unit()
        else:
            raise RuntimeError("Unknown approved operation")
    finally:
        worker.api("result", {"id": job["id"], "nonce": job["nonce"],
                             "success": success}, token)
    print("Approved operation completed" if success else "Approved operation failed")

if __name__ == "__main__":
    poll_once()
