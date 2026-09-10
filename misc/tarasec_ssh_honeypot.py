#!/usr/bin/env python3
"""Small, non-executing TaraSec SSH honeypot with structured telemetry."""

import hashlib
import hmac
import json
import os
import socket
import socketserver
import threading
import time
import urllib.error
import urllib.request
import uuid

import paramiko

HOST = os.environ.get("TARASEC_SSH_HONEYPOT_BIND", "0.0.0.0")
PORT_SPEC = os.environ.get(
    "TARASEC_SSH_HONEYPOT_PORTS",
    os.environ.get("TARASEC_SSH_HONEYPOT_PORT", "22"),
)
KEY_FILE = os.environ.get(
    "TARASEC_SSH_HONEYPOT_KEY",
    "/var/lib/tarasec-ssh-honeypot/ssh_host_ed25519_key",
)
NODE = socket.gethostname()
MAX_PORTS = 64
AUTH_MODE = os.environ.get("TARASEC_SSH_HONEYPOT_AUTH_MODE", "accept-all")
PASSWORD_HASH = os.environ.get("TARASEC_SSH_HONEYPOT_PASSWORD_HASH", "").lower()
DEMO_PORT = int(os.environ.get("TARASEC_SSH_HONEYPOT_DEMO_PORT", "0"))
DEMO_DB_URL = os.environ.get("TARASEC_SSH_HONEYPOT_DEMO_DB_URL", "").strip()
DEMO_NODE_TOKEN = os.environ.get("TARASEC_SSH_HONEYPOT_DEMO_NODE_TOKEN", "")


def parse_ports(spec):
    ports = set()
    for item in spec.replace(" ", ",").split(","):
        item = item.strip()
        if not item:
            continue
        if "-" in item:
            first_text, last_text = item.split("-", 1)
            first, last = int(first_text), int(last_text)
            if first > last:
                raise ValueError(f"descending port range: {item}")
            ports.update(range(first, last + 1))
        else:
            ports.add(int(item))
        if len(ports) > MAX_PORTS:
            raise ValueError(f"more than {MAX_PORTS} honeypot ports requested")
    if not ports or any(port < 1 or port > 65535 for port in ports):
        raise ValueError(f"invalid honeypot port specification: {spec!r}")
    return sorted(ports)


PORTS = parse_ports(PORT_SPEC)
HOST_KEY = paramiko.Ed25519Key(filename=KEY_FILE)


def clean(value, limit=200):
    return "".join(char if 32 <= ord(char) < 127 else "?" for char in str(value))[:limit]


def emit(event, context, severity, action="observe", **fields):
    record = {
        "event": event,
        "sensor": "tarasec-ssh-lite",
        "service": "ssh",
        "proto": "tcp",
        "timestamp": time.time(),
        "severity": severity,
        "confidence": "high" if severity >= 7 else "medium",
        "action": action,
        **context,
        **fields,
    }
    print(json.dumps(record, separators=(",", ":"), sort_keys=True), flush=True)


def validate_demo(context, username, password):
    """Ask the authoritative DB to validate one source/port-bound challenge."""
    body = json.dumps(
        {
            "action": "validate",
            "source_ip": context["src_ip"],
            "source_port": context["src_port"],
            "destination_port": context["dst_port"],
            "username": username,
            "password": password,
        }
    ).encode("utf-8")
    request = urllib.request.Request(
        DEMO_DB_URL,
        data=body,
        method="POST",
        headers={
            "Authorization": f"Bearer {DEMO_NODE_TOKEN}",
            "X-TaraSec-Token": DEMO_NODE_TOKEN,
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=4) as response:
            reply = json.loads(response.read(4096))
            return bool(reply.get("ok") and reply.get("accepted")), clean(reply.get("state", ""), 40)
    except (OSError, ValueError, urllib.error.HTTPError) as error:
        emit("ssh_demo_validation_error", context, 5, reason=type(error).__name__)
        return False, "validation_error"


class SSHServer(paramiko.ServerInterface):
    def __init__(self, context):
        self.context = context
        self.username = "unknown"
        self.shell_requested = threading.Event()
        self.exec_requested = threading.Event()
        self.exec_command = ""

    def get_allowed_auths(self, username):
        return "password"

    def check_auth_password(self, username, password):
        self.username = clean(username, 64)
        supplied_hash = hashlib.sha256(password.encode("utf-8", "surrogatepass")).hexdigest()
        demo_attempt = DEMO_PORT > 0 and self.context["dst_port"] == DEMO_PORT
        if demo_attempt:
            accepted, demo_state = validate_demo(self.context, self.username, password)
        else:
            accepted = AUTH_MODE == "accept-all" or (
                AUTH_MODE == "password"
            and bool(PASSWORD_HASH)
            and hmac.compare_digest(supplied_hash, PASSWORD_HASH)
            )
            demo_state = ""
        emit(
            "ssh_login_success" if accepted else "ssh_login_failed",
            self.context,
            7 if accepted else 4,
            "tag_host" if accepted else "observe",
            username=self.username,
            password_length=len(password),
            auth_mode="demo-challenge" if demo_attempt else AUTH_MODE,
            demo_state=demo_state,
        )
        return paramiko.AUTH_SUCCESSFUL if accepted else paramiko.AUTH_FAILED

    def check_channel_request(self, kind, chanid):
        if kind == "session":
            return paramiko.OPEN_SUCCEEDED
        return paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED

    def check_channel_pty_request(
        self, channel, term, width, height, pixelwidth, pixelheight, modes
    ):
        return True

    def check_channel_shell_request(self, channel):
        self.shell_requested.set()
        return True

    def check_channel_exec_request(self, channel, command):
        if isinstance(command, bytes):
            command = command.decode("utf-8", "replace")
        self.exec_command = clean(command)
        self.exec_requested.set()
        return True


def fake_response(command, username):
    command = command.strip()
    first = command.split()[0] if command else ""
    responses = {
        "": "",
        "help": "GNU bash, version 5.1.16(1)-release\n",
        "whoami": f"{username}\n",
        "pwd": f"/home/{username}\n",
        "hostname": f"{NODE}\n",
        "uname": "Linux node11 5.15.0-119-generic #129-Ubuntu SMP x86_64 GNU/Linux\n",
        "ls": "backup  documents  logs  scripts\n",
        "ps": "    PID TTY          TIME CMD\n   1832 pts/0    00:00:00 bash\n",
        "clear": "\x1b[H\x1b[2J",
    }
    if first in {"exit", "logout"}:
        return None
    if first == "id":
        return f"uid=1000({username}) gid=1000({username}) groups=1000({username}),27(sudo)\n"
    if command == "cat /etc/os-release":
        return 'PRETTY_NAME="Ubuntu 22.04.5 LTS"\nVERSION_ID="22.04"\n'
    if first == "sudo":
        return f"[sudo] password for {username}: Sorry, try again.\n"
    return responses.get(first, f"-bash: {clean(first, 60)}: command not found\n")


def record_command(server, command):
    emit(
        "ssh_command_input",
        server.context,
        8,
        "tag_host",
        username=server.username,
        command=clean(command),
    )


def run_shell(channel, server):
    username = server.username or "ubuntu"
    channel.send(
        "Welcome to Ubuntu 22.04.5 LTS (GNU/Linux 5.15.0-119-generic x86_64)\r\n\r\n"
        f"Last login: {time.strftime('%a %b %d %H:%M:%S %Y')} "
        f"from {server.context['src_ip']}\r\n"
    )
    prompt = f"{username}@{NODE}:~$ "
    channel.send(prompt)
    buffer = ""
    while True:
        data = channel.recv(1024)
        if not data:
            return
        for char in data.decode("utf-8", "replace"):
            if char in "\r\n":
                channel.send("\r\n")
                command, buffer = clean(buffer), ""
                if command:
                    record_command(server, command)
                response = fake_response(command, username)
                if response is None:
                    channel.send("logout\r\n")
                    return
                channel.send(response.replace("\n", "\r\n"))
                channel.send(prompt)
            elif char in "\x08\x7f":
                if buffer:
                    buffer = buffer[:-1]
                    channel.send("\b \b")
            elif 32 <= ord(char) < 127 and len(buffer) < 200:
                buffer += char
                channel.send(char)


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        src_ip, src_port = self.client_address[:2]
        local_ip, dst_port = self.request.getsockname()[:2]
        context = {
            "session": uuid.uuid4().hex[:16],
            "src_ip": src_ip,
            "src_port": src_port,
            "dst_ip": local_ip,
            "dst_port": dst_port,
            "node": NODE,
        }
        emit("ssh_session_connect", context, 1)
        transport = channel = None
        started = time.monotonic()
        try:
            self.request.settimeout(20)
            transport = paramiko.Transport(self.request)
            transport.local_version = "SSH-2.0-OpenSSH_8.9p1 Ubuntu-3ubuntu0.10"
            transport.add_server_key(HOST_KEY)
            server = SSHServer(context)
            transport.start_server(server=server)
            emit(
                "ssh_client_version",
                context,
                1,
                client=clean(transport.remote_version or "unknown", 120),
            )
            channel = transport.accept(15)
            if channel is None:
                return
            channel.settimeout(120)
            if server.exec_requested.wait(0.5):
                record_command(server, server.exec_command)
                response = fake_response(server.exec_command, server.username)
                if response:
                    channel.send(response)
                channel.send_exit_status(0)
                return
            if server.shell_requested.wait(10):
                run_shell(channel, server)
        except (EOFError, OSError, paramiko.SSHException, socket.timeout) as error:
            emit("ssh_session_error", context, 2, reason=type(error).__name__)
        finally:
            if channel is not None:
                channel.close()
            if transport is not None:
                transport.close()
            emit(
                "ssh_session_closed",
                context,
                2,
                "archive",
                duration_sec=round(time.monotonic() - started, 3),
            )


class ThreadedServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True
    request_queue_size = 50


if __name__ == "__main__":
    if AUTH_MODE not in {"accept-all", "reject-all", "password"}:
        raise SystemExit(f"invalid TARASEC_SSH_HONEYPOT_AUTH_MODE: {AUTH_MODE}")
    if AUTH_MODE == "password" and (
        len(PASSWORD_HASH) != 64 or any(c not in "0123456789abcdef" for c in PASSWORD_HASH)
    ):
        raise SystemExit("password mode requires a 64-character SHA-256 password hash")
    if DEMO_PORT:
        if DEMO_PORT not in PORTS:
            raise SystemExit("demo port must also be included in TARASEC_SSH_HONEYPOT_PORTS")
        if not DEMO_DB_URL.startswith("https://"):
            raise SystemExit("demo challenge validation requires an HTTPS DB URL")
        if len(DEMO_NODE_TOKEN) < 32:
            raise SystemExit("demo challenge validation requires a node token of at least 32 characters")
    servers = [ThreadedServer((HOST, port), Handler) for port in PORTS]
    print(
        json.dumps(
            {
                "event": "ssh_honeypot_start",
                "sensor": "tarasec-ssh-lite",
                "service": "ssh",
                "node": NODE,
                "bind": HOST,
                "ports": PORTS,
                "timestamp": time.time(),
            },
            separators=(",", ":"),
            sort_keys=True,
        ),
        flush=True,
    )
    threads = []
    for server in servers:
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        threads.append(thread)
    try:
        threads[0].join()
    except KeyboardInterrupt:
        pass
    finally:
        for server in servers:
            server.shutdown()
            server.server_close()
