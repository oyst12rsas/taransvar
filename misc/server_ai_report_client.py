"""Submit a server maintenance report and print advisory results as they arrive."""

import argparse
import json
import os
import re
import shutil
import sys
import time
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def local_report(mount):
    mount = str(Path(mount).resolve(strict=True))
    disk = shutil.disk_usage(mount)
    memory = {}
    for line in Path("/proc/meminfo").read_text().splitlines():
        name, _, value = line.partition(":")
        if name in ("MemTotal", "MemAvailable"):
            memory[name] = int(value.split()[0]) * 1024
    return {
        "topics": {
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
        },
    }


def submit_and_poll(base_url, node, token, report, wait, emit=print, opener=None):
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


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="Dispatcher base URL")
    parser.add_argument("--node", required=True, help="Configured TaraSec node ID")
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--report", type=Path, help="Tagged JSON report to submit")
    source.add_argument("--local", action="store_true", help="Measure Linux disk and memory")
    parser.add_argument("--mount", default="/", help="Path to measure with --local")
    parser.add_argument("--wait", type=int, default=300, help="Time limit in seconds")
    args = parser.parse_args()
    try:
        report = local_report(args.mount) if args.local else json.loads(args.report.read_text())
        return submit_and_poll(
            args.url, args.node, os.environ.get("SERVER_AI_NODE_TOKEN"), report, args.wait,
            emit=lambda value: print(json.dumps(value), flush=True),
        )
    except HTTPError as exc:
        print(f"Dispatcher HTTP error: {exc.code}", file=sys.stderr)
    except (URLError, OSError, ValueError, TypeError, KeyError) as exc:
        print(f"Report failed: {exc}", file=sys.stderr)
    return 1


if __name__ == "__main__":
    sys.exit(main())
