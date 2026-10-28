#!/usr/bin/env python3
"""Root-owned node enrollment; upload manager hashes, never raw manager emails.

This registers identity and permissions only. It never changes firewall rules or
closes SSH; enabling that policy still requires a working recovery console.
"""
import argparse
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import stat
import tempfile
import urllib.error
import urllib.parse
import urllib.request

import local_manager_policy

DEFAULT_URL = 'https://tarasec.org/ops/agent/api.php'


def enrollment_read(path, shared_config=False):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, encoding='utf-8') as f:
        info = os.fstat(f.fileno())
        forbidden = 0o022 if shared_config else 0o077
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & forbidden:
            if shared_config:
                raise ValueError('Firewall configuration must be root-owned and not group/other writable')
            raise ValueError('Enrollment files must be root-owned regular files, mode 0600 or 0400')
        text = f.read(65537)
    if len(text) > 65536:
        raise ValueError('Enrollment file too large')
    return text


def private_read(path):
    return enrollment_read(path)


def atomic_write(path, text, *, shared_config=False):
    path = Path(path)
    if path.is_symlink():
        raise ValueError('Symlink enrollment files are prohibited')
    shared_info = None
    if path.exists():
        old = enrollment_read(path, shared_config=shared_config)
        if shared_config: shared_info = path.stat()
        # Preserve the previous owner file before every explicit enrollment edit.
        backup_fd, backup = tempfile.mkstemp(prefix=path.name + '.before-enrollment-', dir=path.parent)
        with os.fdopen(backup_fd, 'w', encoding='utf-8') as f:
            os.fchmod(f.fileno(), 0o600)
            f.write(old)
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            os.fchmod(f.fileno(), 0o600)
            f.write(text)
            f.flush()
            os.fsync(f.fileno())
        if shared_config:
            if shared_info is not None:
                os.chown(temporary, 0, shared_info.st_gid)
                shutil.copystat(path, temporary, follow_symlinks=False)
                os.utime(temporary, None)
            else:
                os.chmod(temporary, 0o644)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)


def node_token(path):
    path = Path(path)
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    if not path.exists():
        # O_EXCL prevents replacing an identity created by a concurrent installer.
        try:
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        except FileExistsError:
            pass
        else:
            with os.fdopen(fd, 'w') as f: f.write(secrets.token_hex(32) + '\n')
    token = private_read(path).strip()
    if not re.fullmatch('[a-f0-9]{64}', token): raise ValueError('Malformed node credential; left unchanged')
    return token


def setting(text, key):
    entries = re.findall(r'^\s*' + re.escape(key) + r'\s*=\s*(.*?)\s*$', text, re.M)
    if len(entries) > 1: raise ValueError('Duplicate enrollment setting: ' + key)
    return entries[0].strip().strip('\'"') if entries else ''


def set_setting(text, key, value):
    if re.search(r'[\r\n"\']', value): raise ValueError('Invalid enrollment setting')
    pattern = r'^\s*' + re.escape(key) + r'\s*=.*$'
    if len(re.findall(pattern, text, re.M)) > 1: raise ValueError('Duplicate enrollment setting: ' + key)
    line = key + '="' + value + '"'
    return re.sub(pattern, lambda m: line, text, flags=re.M) if re.search(pattern, text, re.M) else text.rstrip() + '\n' + line + '\n'


def enrollment_url(api_url):
    parsed = urllib.parse.urlsplit(api_url)
    if (parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password
            or parsed.query or parsed.fragment or not parsed.path.endswith('/api.php')):
        raise ValueError('Approval URL must be an HTTPS api.php URL without credentials, query or fragment')
    return urllib.parse.urlunsplit(parsed._replace(path=parsed.path[:-7] + 'enroll.php'))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError('Enrollment redirects are prohibited')


def register(api_url, token, nickname, hashes, opener=None, gatekeeper=None):
    data = {'nickname': nickname, 'manager_hashes': sorted(hashes)}
    if gatekeeper is not None: data['gatekeeper'] = gatekeeper
    payload = json.dumps(data).encode()
    req = urllib.request.Request(enrollment_url(api_url), data=payload,
        headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token}, method='POST')
    opener = opener or urllib.request.build_opener(NoRedirect())
    with opener.open(req, timeout=15) as response:
        raw = response.read(8193)
    if len(raw) > 8192: raise ValueError('Enrollment response too large')
    answer = json.loads(raw)
    if (not isinstance(answer, dict) or answer.get('ok') is not True
            or not isinstance(answer.get('node_id'), str)
            or not re.fullmatch('[A-Za-z0-9_. -]{1,128}', answer['node_id'])
            or answer.get('manager_count') != len(hashes)):
        raise ValueError('Enrollment was not confirmed by the approval service')
    return answer



def public_nickname(current, requested=None):
    """Replace only the generated hex fallback; retain owner-selected names."""
    if requested:
        return requested
    if current and not re.fullmatch(r'Node [a-fA-F0-9]{8}', current):
        return current
    qualities = (
        'Amber', 'Azure', 'Birch', 'Blue', 'Bright', 'Calm', 'Clear', 'Copper',
        'Coral', 'Crimson', 'Crystal', 'Dawn', 'Deep', 'Emerald', 'Evening', 'Fair',
        'Golden', 'Green', 'Indigo', 'Ivory', 'Jade', 'Juniper', 'Mellow', 'Misty',
        'Moonlit', 'Mossy', 'Northern', 'Ocean', 'Olive', 'Pearl', 'Quiet', 'Ruby',
        'Saffron', 'Sage', 'Scarlet', 'Silver', 'Soft', 'Spring', 'Still', 'Sunny',
        'Teal', 'Velvet', 'Violet', 'Warm', 'White', 'Wild', 'Winter', 'Woodland',
    )
    places = (
        'Acorn', 'Alder', 'Anchor', 'Aspen', 'Aurora', 'Bay', 'Beacon', 'Birch',
        'Brook', 'Canyon', 'Cedar', 'Cliff', 'Cloud', 'Cove', 'Creek', 'Dune',
        'Elm', 'Falcon', 'Fern', 'Field', 'Finch', 'Fjord', 'Forest', 'Garden',
        'Glen', 'Grove', 'Harbor', 'Haven', 'Heron', 'Hill', 'Horizon', 'Island',
        'Juniper', 'Lake', 'Lantern', 'Lark', 'Laurel', 'Leaf', 'Linden', 'Maple',
        'Meadow', 'Moon', 'Oak', 'Orchard', 'Otter', 'Owl', 'Peak', 'Pine',
        'Reed', 'Ridge', 'River', 'Robin', 'Rowan', 'Shore', 'Sparrow', 'Spruce',
        'Star', 'Stone', 'Summit', 'Thistle', 'Valley', 'Willow', 'Wind', 'Wren',
    )
    return secrets.choice(qualities) + ' ' + secrets.choice(places)


def enroll(emails, sync=False, approval_url=None, nickname=None, root=Path('/'), opener=None):
    cfg = root / 'etc/tarasecfw.conf'
    access = root / 'etc/tarasec/access.conf'
    manager = root / 'etc/tarasec-server-manager.conf'
    new_hashes = {local_manager_policy.email_hash(email) for email in emails}
    existing = local_manager_policy.manager_hashes(str(access)) if access.exists() else set()
    hashes = existing | new_hashes
    if not hashes and not sync: raise ValueError('Supply a manager Google email, or --sync to publish an empty revocation list')
    cfg_text = enrollment_read(cfg, shared_config=True) if cfg.exists() else ''
    nickname = public_nickname(setting(cfg_text, 'AGENT_PUBLIC_NICKNAME'), nickname)
    if not re.fullmatch('[A-Za-z][A-Za-z0-9 _-]{2,31}', nickname): raise ValueError('Invalid public nickname')
    manager_text = private_read(manager) if manager.exists() else ''
    api_url = approval_url or setting(manager_text, 'AGENT_APPROVAL_URL') or DEFAULT_URL
    enrollment_url(api_url)  # Validate before creating credentials or changing files.
    token = node_token(root / 'etc/tarasec/agent-node.token')
    if new_hashes - existing or not access.exists():
        # Merge with existing grants; an update never silently removes another
        # manager. For revocation edit access.conf, then run --sync.
        text = private_read(access) if access.exists() else 'MANAGER_EMAILS=""\nMANAGER_EMAIL_SHA256=""\n'
        text = set_setting(text, 'MANAGER_EMAIL_SHA256', ','.join(sorted(hashes)))
        atomic_write(access, text)
    # Persist configuration before the request: a timeout may follow a successful
    # server commit, so retries must retain exactly the same identity and URL.
    if not cfg.exists() or nickname != setting(cfg_text, 'AGENT_PUBLIC_NICKNAME'):
        # Existing firewall configuration is not rewritten; append/replace only
        # this descriptive field, after validating its ownership and permissions.
        atomic_write(cfg, set_setting(cfg_text, 'AGENT_PUBLIC_NICKNAME', nickname), shared_config=True)
    if approval_url:
        atomic_write(manager, set_setting(manager_text, 'AGENT_APPROVAL_URL', api_url))
    answer = register(api_url, token, nickname, hashes, opener)
    atomic_write(root / 'etc/tarasec/agent-enrollment.json', json.dumps({
        'node_id': answer['node_id'], 'approval_url': api_url, 'nickname': nickname,
        'manager_count': len(hashes)}, indent=2) + '\n')
    publish_ssh_approval_target({'node_id': answer['node_id'], 'approval_url': api_url}, root)
    return answer


def publish_ssh_approval_target(receipt, root=Path('/')):
    """Publish an opaque node reference and HTTPS service URL, never credentials."""
    import hashlib
    node = receipt.get('node_id', '')
    if not isinstance(node, str) or not re.fullmatch(r'[A-Za-z0-9_. -]{1,128}', node):
        raise ValueError('Invalid enrollment identity')
    api = receipt.get('approval_url', '')
    enrollment_url(api)
    ref = hashlib.sha256(('agent-dashboard:' + node).encode()).hexdigest()
    folder = root / 'var/lib/tarasec-ssh-approval'
    folder.mkdir(mode=0o755, parents=True, exist_ok=True)
    info = folder.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
        raise ValueError('Unsafe public approval directory')
    path = folder / 'target.json'
    if path.is_symlink():
        raise ValueError('Symlink approval metadata prohibited')
    payload = json.dumps({'version': 1, 'server_ref': ref,
        'approval_url': api.rsplit('/', 1)[0] + '/'}, sort_keys=True) + '\n'
    fd, temporary = tempfile.mkstemp(dir=folder, prefix='.target-')
    try:
        with os.fdopen(fd, 'w') as out:
            out.write(payload)
            out.flush()
            os.fsync(out.fileno())
            os.fchmod(out.fileno(), 0o644)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    return json.loads(payload)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--email', action='append', default=[], help='Google manager email; repeat to add managers')
    parser.add_argument('--sync', action='store_true', help='Publish current local hashes, including an empty revocation list')
    parser.add_argument('--approval-url', help='Private/self-hosted HTTPS approval api.php URL')
    parser.add_argument('--nickname')
    args = parser.parse_args()
    if os.geteuid() != 0: parser.error('Run with sudo on the intended server')
    if not args.email and not args.sync:
        args.email = [input('Google email authorized to manage this server: ').strip()]
    try:
        result = enroll(args.email, args.sync, args.approval_url, args.nickname)
    except urllib.error.HTTPError as e:
        raise SystemExit('Enrollment rejected (HTTP %s); local identity retained. Deploy the central enrollment endpoint, then retry.' % e.code)
    except urllib.error.URLError:
        raise SystemExit('Enrollment HTTPS connection failed; local identity retained. Check DNS, TLS certificates and connectivity to the approval website.')
    except ValueError as error:
        # Only known static diagnostics may be printed; never raw responses or input.
        reasons = {
            'Enrollment was not confirmed by the approval service': 'The approval website returned an unexpected enrollment confirmation.',
            'Enrollment files must be root-owned regular files, mode 0600 or 0400': 'Local enrollment files have incorrect ownership or permissions.',
            'Firewall configuration must be root-owned and not group/other writable': 'The firewall configuration must be root-owned and not writable by its group or other users.',
            'Enrollment redirects are prohibited': 'The approval website redirected the enrollment request; use its direct HTTPS endpoint.',
            'Malformed node credential; left unchanged': 'The existing local node credential is malformed; it was retained.',
        }
        reason = reasons.get(str(error), 'Local enrollment configuration or the website response could not be validated.')
        raise SystemExit(reason + ' Enrollment not confirmed; local identity retained.')
    except (OSError, RuntimeError):
        raise SystemExit('Enrollment could not read/write its local configuration or contact the approval website; local identity retained.')
    print('Enrollment confirmed for %s (%d local managers).' % (result['nickname'], result['manager_count']))
    receipt = json.loads(private_read(Path('/etc/tarasec/agent-enrollment.json')))
    print('Google sign-in: ' + receipt['approval_url'].rsplit('/', 1)[0] + '/')
    print('SSH policy unchanged. Verify Google/TOTP and console recovery before enabling SSH closure; see docs/google-ssh.md.')


if __name__ == '__main__': main()

