#!/usr/bin/env python3
"""Query one DNS server directly and require the expected IPv4 answer."""

import os
import random
import socket
import struct
import sys


def skip_name(packet: bytes, offset: int) -> int:
    while True:
        length = packet[offset]
        if length & 0xC0 == 0xC0:
            return offset + 2
        offset += 1
        if length == 0:
            return offset
        offset += length


def main() -> int:
    if len(sys.argv) != 3:
        print(f"Usage: {sys.argv[0]} DNS_SERVER HOSTNAME", file=sys.stderr)
        return 2
    server, hostname = sys.argv[1:]
    expected = os.environ.get("TARASEC_EXPECTED_DNS_IP", server)
    transaction_id = random.randrange(0, 65536)
    labels = hostname.rstrip(".").split(".")
    question_name = b"".join(bytes((len(label),)) + label.encode("ascii") for label in labels) + b"\0"
    query = struct.pack("!HHHHHH", transaction_id, 0x0100, 1, 0, 0, 0) + question_name + struct.pack("!HH", 1, 1)

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.settimeout(3)
    try:
        sock.sendto(query, (server, 53))
        packet, _ = sock.recvfrom(4096)
    except OSError as exc:
        print(f"DNS query to {server}:53 failed: {exc}", file=sys.stderr)
        return 1
    finally:
        sock.close()

    if len(packet) < 12:
        print("DNS reply was truncated", file=sys.stderr)
        return 1
    reply_id, flags, questions, answers, _, _ = struct.unpack("!HHHHHH", packet[:12])
    if reply_id != transaction_id or flags & 0x000F:
        print("DNS server returned an invalid or unsuccessful reply", file=sys.stderr)
        return 1
    offset = 12
    for _ in range(questions):
        offset = skip_name(packet, offset) + 4
    found = []
    for _ in range(answers):
        offset = skip_name(packet, offset)
        record_type, record_class, _, length = struct.unpack("!HHIH", packet[offset:offset + 10])
        offset += 10
        data = packet[offset:offset + length]
        offset += length
        if record_type == 1 and record_class == 1 and length == 4:
            found.append(socket.inet_ntoa(data))
    if expected not in found:
        print(f"{hostname} resolved to {found or 'no IPv4 address'}, expected {expected}", file=sys.stderr)
        return 1
    print(f"{hostname} resolves to {expected} through {server}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
