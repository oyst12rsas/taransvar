"""Submit a server maintenance report and print advisory results as they arrive."""

import argparse
import json
import os
import re
import shutil
import ssl
import stat
import subprocess
import sys
import time
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, HTTPSHandler, Request, build_opener

import server_ai_evidence

TOPICS = ("logs", "auth", "disk", "memory")
DEFAULT_TOPICS = "logs,auth"


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def resource_topics(mount):
    mount = str(Path(mount).resolve(strict=True))
    disk = shutil.disk_usage(mount)
    memory = {}
    for line in Path("/proc/meminfo").read_text().splitlines():
        name, _, value = line.partition(":")
        if name in ("MemTotal", "MemAvailable"):
            memory[name] = int(value.split()[0]) * 1024
    return {
        "disk": {
            "path": mount,
            "total_bytes": disk.total,
            "used_bytes": disk.used,
            "available_bytes": disk.free,
            "total_gib": round(disk.total / 2**30, 2),
            "available_gib": round(disk.free / 2**30, 2),
            "used_percent": round(100 * disk.used / (disk.used + disk.free), 2) if disk.used + disk.free else None,
        },
        "memory": {
            "total_bytes": memory["MemTotal"],
            "available_bytes": memory["MemAvailable"],
            "total_gib": round(memory["MemTotal"] / 2**30, 2),
            "available_gib": round(memory["MemAvailable"] / 2**30, 2),
            "available_percent": round(100 * memory["MemAvailable"] / memory["MemTotal"], 2),
        },
    }


def parse_topics(value):
    topics = [t.strip() for t in value.split(",") if t.strip()]
    if not topics or any(t not in TOPICS for t in topics):
        raise ValueError("Topics must be a comma-separated subset of " + ",".join(TOPICS))
    return topics


def local_report(mount, topics, state):
    """Build a report from this node. Returns (report, state to save after submission)."""
    found, new_state = server_ai_evidence.collect(topics, state, mount=mount)
    if "disk" in topics or "memory" in topics:
        found.update({k: v for k, v in resource_topics(mount).items() if k in topics})
    return {"topics": {t: found[t] for t in topics}}, new_state


def submit_and_poll(base_url, node, token, report, wait, emit=print, opener=None, on_submitted=None):
    url = urlsplit(base_url)
    loopback = url.scheme == "http" and url.hostname in ("127.0.0.1", "::1")
    if (not (url.scheme == "https" or loopback) or not url.hostname
            or url.username or url.password or url.query or url.fragment):
        raise ValueError("Use HTTPS or a local loopback HTTP URL.")
    if not node or not token or wait <= 0:
        raise ValueError("Node, token and positive wait time are required.")

    opener = opener or build_opener(NoRedirect())
    deadline = time.monotonic() + wait

    def call(path, body=None):
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise TimeoutError("Timed out waiting for report results")
        request = Request(
            base_url.rstrip("/") + path,
            data=None if body is None else json.dumps(body).encode(),
            headers={
                "Content-Type": "application/json",
                "X-Node-ID": node,
                "Authorization": "Bearer " + token,
            },
        )
        with opener.open(request, timeout=min(15, remaining)) as response:
            data = response.read(262145)
        if len(data) > 262144:
            raise ValueError("Dispatcher response too large")
        return json.loads(data)

    report_id = call("/reports", report)["report_id"]
    if not isinstance(report_id, str) or not re.fullmatch(r"[a-f0-9]{32}", report_id):
        raise ValueError("Invalid report ID")
    if on_submitted:
        on_submitted(report_id)
    emit({"report_id": report_id})
    topics = set(report["topics"])
    completed = set()
    failed = False
    while completed != topics:
        results = call("/reports/" + report_id)["results"]
        if set(results) != topics:
            raise ValueError("Dispatcher returned unexpected topics")
        for topic, result in results.items():
            status = result["status"]
            if topic not in completed and status in ("done", "failed"):
                emit({"report_id": report_id, "topic": topic, "result": result})
                completed.add(topic)
                failed = failed or status == "failed"
        if completed != topics:
            time.sleep(max(0, min(1, deadline - time.monotonic())))
    return 1 if failed else 0


def read_enrollment_file(path):
    with os.fdopen(os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)) as file:
        info = os.fstat(file.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
            raise ValueError("Enrollment files must be private, root-owned regular files")
        return file.read(8193)


def enrolled_dispatcher():
    directory = Path("/etc/tarasec")
    receipt = json.loads(read_enrollment_file(directory / "agent-enrollment.json"))
    url = urlsplit(receipt["approval_url"])
    if (url.scheme != "https" or not url.hostname or url.username or url.password
            or url.query or url.fragment or not url.path.endswith("/api.php")):
        raise ValueError("Invalid TaraSec enrollment URL")
    credential = read_enrollment_file(directory / "agent-node.token").strip()
    if not re.fullmatch(r"[a-f0-9]{64}", credential):
        raise ValueError("Invalid TaraSec enrollment token")
    request = Request(url.geturl() + "?action=report_credential", data=b"{}",
                      headers={"Content-Type": "application/json",
                               "Authorization": "Bearer " + credential})
    try:
        with build_opener(NoRedirect()).open(request, timeout=15) as response:
            data = response.read(32769)
    except HTTPError as exc:
        raise ValueError(f"TaraSec enrollment API HTTP error: {exc.code}") from exc
    except URLError as exc:
        raise ValueError(f"TaraSec enrollment API connection failed: {exc.reason}") from exc
    if len(data) > 32768:
        raise ValueError("Enrollment response too large")
    answer = json.loads(data)
    if (answer.get("ok") is not True or not answer["url"].startswith("https://")
            or not re.fullmatch(r"enrolled-report:[a-f0-9]{64}", answer["node_id"])
            or not re.fullmatch(r"v1\.[0-9]{1,12}\.[a-f0-9]{64}", answer["token"])):
        raise ValueError("Invalid reporting credential")
    ca_pem = answer.get("ca_pem")
    opener = None
    if ca_pem:
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.load_verify_locations(cadata=ca_pem)
        opener = build_opener(NoRedirect(), HTTPSHandler(context=context))
    return answer["url"], answer["node_id"], answer["token"], opener


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--enroll", action="store_true", help="Use existing TaraSec enrollment")
    parser.add_argument("--url", help="Dispatcher base URL")
    parser.add_argument("--node", help="Configured TaraSec node ID")
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--report", type=Path, help="Tagged JSON report to submit")
    source.add_argument("--local", action="store_true", help="Collect evidence from this node")
    parser.add_argument("--topics", default=os.environ.get("SERVER_AI_TOPICS") or DEFAULT_TOPICS,
                        help="Topics for --local: comma-separated subset of " + ",".join(TOPICS))
    parser.add_argument("--state", type=Path, default=server_ai_evidence.STATE_PATH,
                        help="Where --local remembers the last window and seen messages")
    parser.add_argument("--dry-run", action="store_true",
                        help="Print the --local report instead of submitting it")
    parser.add_argument("--mount", default="/", help="Filesystem for disk figures with --local")
    parser.add_argument("--wait", type=int, default=300, help="Time limit in seconds")
    args = parser.parse_args()
    if args.dry_run:
        if not args.local:
            parser.error("--dry-run needs --local")
        report, _ = local_report(args.mount, parse_topics(args.topics),
                                 server_ai_evidence.load_state(args.state))
        print(json.dumps(report, indent=2))
        return 0
    if args.enroll and (args.url or args.node):
        parser.error("--enroll supplies the URL and node ID")
    if not args.enroll and not (args.url and args.node):
        parser.error("provide --enroll or both --url and --node")
    try:
        url, node, token, opener = (
            enrolled_dispatcher() if args.enroll else
            (args.url, args.node, os.environ.get("SERVER_AI_NODE_TOKEN"), None)
        )
        new_state = None
        if args.local:
            report, new_state = local_report(args.mount, parse_topics(args.topics),
                                             server_ai_evidence.load_state(args.state))
        else:
            report = json.loads(args.report.read_text())
        return submit_and_poll(
            url, node, token, report, args.wait, opener=opener,
            emit=lambda value: print(json.dumps(value), flush=True),
            on_submitted=new_state and (lambda _: server_ai_evidence.save_state(new_state, args.state)),
        )
    except HTTPError as exc:
        print(f"Dispatcher HTTP error: {exc.code}", file=sys.stderr)
    except (URLError, OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError) as exc:
        print(f"Report failed: {exc}", file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
