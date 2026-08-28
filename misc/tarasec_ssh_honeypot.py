#!/usr/bin/env python3
"""Lightweight TaraSec SSH decoy.

This is deliberately not an SSH implementation. It occupies the public decoy
port, records the source of every TCP connection, sends an SSH-looking banner,
reads a small amount of client data and closes the connection. Real SSH must
listen on a different port.
"""

import os
import socket
import socketserver
import sys

HOST = os.environ.get("TARASEC_SSH_HONEYPOT_BIND", "0.0.0.0")
PORT = int(os.environ.get("TARASEC_SSH_HONEYPOT_PORT", "22"))
NODE = socket.gethostname()
BANNER = b"SSH-2.0-OpenSSH_8.9p1 Ubuntu-3ubuntu0.10\r\n"


class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        ip, port = self.client_address[:2]
        print(
            f"TARASEC_SSH_HONEYPOT node={NODE} src={ip} src_port={port} dst_port={PORT}",
            flush=True,
        )
        try:
            self.request.settimeout(3)
            self.request.sendall(BANNER)
            data = self.request.recv(512)
            if data:
                safe = data[:160].replace(b"\r", b" ").replace(b"\n", b" ")
                print(
                    f"TARASEC_SSH_HONEYPOT_DATA node={NODE} src={ip} bytes={len(data)} data={safe!r}",
                    flush=True,
                )
        except (OSError, socket.timeout):
            pass


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


if __name__ == "__main__":
    try:
        with Server((HOST, PORT), Handler) as server:
            print(
                f"TARASEC_SSH_HONEYPOT_START node={NODE} bind={HOST} port={PORT}",
                flush=True,
            )
            server.serve_forever()
    except PermissionError:
        print("Binding the honeypot port requires root privileges.", file=sys.stderr)
        raise
