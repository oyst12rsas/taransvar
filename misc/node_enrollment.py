#!/usr/bin/env python3
"""Outbound enrollment; identity stays local, central secrets never reach the node."""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request

STATE = Path('/etc/tarasec')
DEFAULT_URL = 'https://tarasec.org/ops/agent/api.php'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError('Redirect refused for credential endpoint')


def endpoint(url):
    parts = urllib.parse.urlsplit(url)
    if parts.scheme != 'https' or not parts.hostname or parts.username or parts.password or parts.query or parts.fragment:
        raise ValueError('Node API must be an HTTPS URL without credentials, query or fragment')
    return url


def request(url, action, body, token=None):
    endpoint(url)
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    raw = json.dumps(body, separators=(',', ':')).encode()
    if len(raw) > 8192:
        raise ValueError('Node request too large')
    req = urllib.request.Request(url + '?action=' + action, data=raw, headers=headers, method='POST')
    with urllib.request.build_opener(NoRedirect).open(req, timeout=45 if action == 'assess' else 12) as response:
        raw = response.read(32769)
    if len(raw) > 32768:
        raise ValueError('Node response too large')
    data = json.loads(raw)
    if not isinstance(data, dict) or data.get('ok') is not True:
        raise RuntimeError('Central node API rejected request')
    return data


def atomic_write(path, data):
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix='.node-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as target:
            target.write(data)
            target.flush()
            os.fsync(target.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def openssl(*args, input=None):
    proc = subprocess.run(['openssl', *args], input=input, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, timeout=15, check=False)
    if proc.returncode:
        raise RuntimeError('Node identity cryptographic operation failed')
    return proc.stdout


def identity(state=STATE):
    state.mkdir(mode=0o700, parents=True, exist_ok=True)
    private = state / 'node-identity.pem'
    # Timer/installer take the same lock before invoking enrollment.
    if not private.exists():
        atomic_write(private, openssl('genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048').decode())
    if private.stat().st_mode & 0o077:
        raise RuntimeError('Node identity key must have mode 0600')
    public = openssl('pkey', '-in', str(private), '-pubout').decode()
    return private, public, hashlib.sha256(public.encode()).hexdigest()


def signed_request(private, public, fingerprint, action, role, hostname):
    timestamp = int(time.time())
    nonce = secrets.token_hex(16)
    message = '\n'.join(['tarasec-enroll-v1', action, fingerprint, str(timestamp), nonce, role, hostname])
    signature = openssl('dgst', '-sha256', '-sign', str(private), input=message.encode())
    return {'public_key': public, 'timestamp': timestamp, 'nonce': nonce, 'role': role,
            'hostname': hostname, 'signature': base64.b64encode(signature).decode()}


def enroll(url, role='reporting', state=STATE):
    endpoint(url)
    private, public, fingerprint = identity(state)
    metadata_path = state / 'node-credential.json'
    metadata = json.loads(metadata_path.read_text()) if metadata_path.exists() else {}
    if metadata and metadata.get('api_url') != url:
        raise RuntimeError('Node API changed; refusing to send existing credentials to another endpoint')
    token_path = state / 'agent-node.token'
    if metadata.get('until', 0) > time.time() + 3600 and token_path.exists():
        return {'state': 'approved', **metadata}
    hostname = re.sub(r'[^A-Za-z0-9._-]', '-', os.uname().nodename)[:64].strip('.-') or 'node'
    answer = request(url, 'enroll', signed_request(private, public, fingerprint, 'enroll', role, hostname))
    if answer.get('id') != fingerprint:
        raise RuntimeError('Enrollment identity mismatch')
    if answer.get('state') != 'approved':
        if answer.get('state') in ('revoked', 'rejected'):
            token_path.unlink(missing_ok=True)
            metadata_path.unlink(missing_ok=True)
        return {'state': answer.get('state', 'unknown'), 'id': fingerprint}
    ciphertext = base64.b64decode(answer['sealed_token'], validate=True)
    token = openssl('pkeyutl', '-decrypt', '-inkey', str(private), '-pkeyopt', 'rsa_padding_mode:oaep', input=ciphertext).decode()
    if not re.fullmatch(r'[a-f0-9]{64}', token) or not isinstance(answer.get('token_until'), int):
        raise RuntimeError('Invalid issued credential')
    metadata = {'id': fingerprint, 'api_url': url, 'until': answer['token_until'], 'role': answer['role']}
    atomic_write(token_path, token + '\n')
    atomic_write(metadata_path, json.dumps(metadata) + '\n')
    return {'state': 'approved', **metadata}


if __name__ == '__main__':
    import argparse
    import fcntl
    parser = argparse.ArgumentParser()
    parser.add_argument('--url', default=DEFAULT_URL)
    parser.add_argument('--role', choices=['reporting', 'operations'], default='operations')
    args = parser.parse_args()
    if os.geteuid() != 0:
        parser.error('Run as root')
    STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (STATE / 'node-enrollment.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        result = enroll(args.url, args.role)
    print('Node enrollment: ' + result['state'] + ' · fingerprint ' + result['id'])
