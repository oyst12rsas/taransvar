#!/usr/bin/env python3
"""Node-owned manager grants. Never transmit this file or its contents."""
import hashlib
import hmac
import os
import re
import stat

POLICY = '/etc/tarasec/access.conf'


def email_hash(email):
    email = email.strip().lower()
    if not email.isascii() or not re.fullmatch(r'[^\s,@]+@[^\s,@]+\.[^\s,@]+', email):
        raise ValueError('Invalid local manager email')
    return hashlib.sha256(email.encode('ascii')).hexdigest()


def manager_hashes(path=None):
    fd = os.open(path or POLICY, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'r', encoding='utf-8') as source:
        info = os.fstat(source.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
            raise RuntimeError('Local access policy must be a root-owned regular file with mode 0600 or 0400')
        text = source.read(65537)
    if len(text) > 65536:
        raise ValueError('Local access policy too large')
    values = {}
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith('#'): continue
        match = re.fullmatch(r'(MANAGER_EMAILS|MANAGER_EMAIL_SHA256)\s*=\s*(.*)', line)
        if not match or match[1] in values:
            raise ValueError('Unknown or duplicate local access setting')
        value = match[2]
        if value.startswith(('"', "'")):
            if len(value) < 2 or value[-1] != value[0]: raise ValueError('Unterminated local access setting')
            value = value[1:-1]
        values[match[1]] = value
    result = set()
    for value in values.get('MANAGER_EMAILS', '').split(','):
        if value.strip(): result.add(email_hash(value))
    for value in values.get('MANAGER_EMAIL_SHA256', '').split(','):
        value = value.strip().lower()
        if not value: continue
        if not re.fullmatch(r'[a-f0-9]{64}', value): raise ValueError('Invalid local manager hash')
        result.add(value)
    if len(result) > 128: raise ValueError('Too many local managers')
    return result


def require_manager(operator_hash):
    if not isinstance(operator_hash, str) or not re.fullmatch(r'[a-f0-9]{64}', operator_hash):
        raise RuntimeError('Approved operation has no valid operator identity hash')
    if not any(hmac.compare_digest(operator_hash, allowed) for allowed in manager_hashes()):
        raise RuntimeError('Approving operator is not authorized by this node')
