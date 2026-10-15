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
        success = worker.execute_approved_job(job)
    finally:
        worker.api("result", {"id": job["id"], "nonce": job["nonce"],
                             "success": success}, token)
    print("Approved operation completed" if success else "Approved operation failed")

if __name__ == "__main__":
    poll_once()

