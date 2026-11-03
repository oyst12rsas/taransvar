#!/usr/bin/env python3
"""Install only a bounded, manager-approved Ed25519 key for an opted-in account."""
import base64
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import stat
import struct
import time

POLICY = Path('/etc/tarasec/ssh-key-enrollment.json')
SOURCE_POLICY_VERSION = 1


def public_key(value):
    if not isinstance(value, str) or len(value) > 256:
        raise ValueError('Invalid public key')
    parts = value.split()
    if len(parts) != 2 or parts[0] != 'ssh-ed25519':
        raise ValueError('Only plain Ed25519 public keys are accepted')
    blob = base64.b64decode(parts[1], validate=True)
    if len(blob) != 51 or blob[:19] != struct.pack('!I', 11)+b'ssh-ed25519'+struct.pack('!I', 32):
        raise ValueError('Invalid Ed25519 key encoding')
    return 'ssh-ed25519 '+base64.b64encode(blob).decode(), 'SHA256:'+base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip('=')


def account_for(job, policy=POLICY, now=None):
    now = time.time() if now is None else now
    approved = job.get('approved_at')
    if type(approved) is not int or not 0 <= now-approved <= 600:
        raise ValueError('Fresh approval required')
    name = job.get('ssh_user')
    if not isinstance(name, str) or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', name):
        raise ValueError('Invalid SSH account')
    fd = os.open(policy, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd) as source:
        info = os.fstat(source.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077 or info.st_size > 4096:
            raise ValueError('Unsafe enrollment policy')
        allowed = json.load(source).get('allowed_users', [])
    if name not in allowed:
        raise ValueError('SSH account not opted in locally')
    account = pwd.getpwnam(name)
    if account.pw_uid < 1000 or account.pw_shell.endswith(('nologin', '/false')):
        raise ValueError('Interactive unprivileged account required')
    key, fingerprint = public_key(job.get('public_key'))
    if job.get('fingerprint') != fingerprint:
        raise ValueError('Key fingerprint mismatch')
    return account, key, fingerprint


def open_home(account):
    path = Path(account.pw_dir)
    if not path.is_absolute() or '..' in path.parts:
        raise ValueError('Invalid home')
    fd = os.open('/', os.O_RDONLY | os.O_DIRECTORY)
    try:
        for part in path.parts[1:]:
            nxt = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = nxt
            info = os.fstat(fd)
            if info.st_uid not in (0, account.pw_uid) or info.st_mode & 0o022:
                raise ValueError('Unsafe home path')
        return fd
    except BaseException:
        os.close(fd)
        raise


def install_key(account, key):
    home = open_home(account)
    ssh = None
    try:
        try:
            os.mkdir('.ssh', 0o700, dir_fd=home)
            created = True
        except FileExistsError:
            created = False
        ssh = os.open('.ssh', os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=home)
        if created:
            os.fchown(ssh, account.pw_uid, account.pw_gid)
        info = os.fstat(ssh)
        if info.st_uid != account.pw_uid or info.st_mode & 0o077:
            raise ValueError('Unsafe .ssh directory ownership or permissions')
        fd = os.open('authorized_keys', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600, dir_fd=ssh)
        with os.fdopen(fd, 'r+b') as target:
            fcntl.flock(target, fcntl.LOCK_EX)
            info = os.fstat(target.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid not in (0, account.pw_uid) or info.st_mode & 0o077 or info.st_size > 1048576:
                raise ValueError('Unsafe authorized_keys file')
            old = target.read()
            for line in old.decode('utf-8').splitlines():
                if line.split()[:2] == key.split():
                    return False
            target.seek(0, os.SEEK_END)
            target.write((b'\n' if old and not old.endswith(b'\n') else b'') + key.encode()+b' tarasec-approved-device\n')
            target.flush()
            os.fsync(target.fileno())
            os.fchown(target.fileno(), account.pw_uid, account.pw_gid)
            return True
    finally:
        if ssh is not None:
            os.close(ssh)
        os.close(home)


def execute(job):
    account, key, fingerprint = account_for(job)
    source = job.get('ssh_source')
    if source is not None:
        import ssh_enrollment_source
        source = ssh_enrollment_source.source_address(source)
    # Local manager identity is checked by the worker before this typed operation.
    install_key(account, key)
    if source is not None:
        ssh_enrollment_source.allow_source(source)
    return fingerprint

