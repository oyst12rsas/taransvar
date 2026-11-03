"""Persist one approved SSH source and reconcile the existing timed gate."""
import fcntl
import ipaddress
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile

VERSION = 1
CONF = Path('/etc/tarasecfw.conf')
STATE = Path('/var/lib/tarasec/ssh-key-sources')


def source_address(value):
    if not isinstance(value, str) or '/' in value:
        raise ValueError('One exact approved SSH source address is required')
    ip = ipaddress.ip_address(value)
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped:
        ip = ip.ipv4_mapped
    allowed = ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10') if ip.version == 4 else ('fc00::/7',)
    if not any(ip in ipaddress.ip_network(network) for network in allowed):
        raise ValueError('Approved SSH source must be a private LAN/VPN host')
    return str(ip)


def updated_config(text, source):
    ip = ipaddress.ip_address(source_address(source))
    matches = list(re.finditer(r'^([ \t]*SSH_ALLOWED_SOURCES[ \t]*=[ \t]*)([^\r\n]*)', text, re.M))
    if len(matches) != 1:
        raise ValueError('One existing SSH_ALLOWED_SOURCES assignment is required')
    match = matches[0]
    value, separator, comment = match[2].partition('#')
    value = value.strip()
    if value[:1] in ("'", '"'):
        if len(value) < 2 or value[-1] != value[0]:
            raise ValueError('Invalid SSH source policy quoting')
        value = value[1:-1]
    entries = [entry.strip() for entry in value.split(',') if entry.strip()]
    networks = [ipaddress.ip_network(entry, strict=False) for entry in entries]
    if not networks or len(networks) > 64 or any(network.prefixlen == 0 for network in networks):
        raise ValueError('Existing bounded SSH source policy is required')
    if any(ip.version == network.version and ip in network for network in networks):
        return text
    if len(networks) >= 64:
        raise ValueError('SSH source policy is full')
    exact = str(ipaddress.ip_network(str(ip), strict=False))
    replacement = match[1] + '"' + ','.join(entries + [exact]) + '"'
    if separator:
        replacement += ' #' + comment
    return text[:match.start()] + replacement + text[match.end():]


def read_config(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(fd, 'rb') as handle:
        info = os.fstat(handle.fileno())
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022
                or info.st_nlink != 1 or info.st_size > 1048576):
            raise ValueError('Unsafe SSH source configuration')
        return handle.read().decode('utf-8'), info


def reconcile_source_filter(port, sources):
    """Refresh existing source filters without accepting traffic or changing hooks."""
    for tool, version in [('iptables', 4), ('ip6tables', 6)]:
        selected = [str(source) for source in sources if source.version == version]
        if not selected:
            continue
        result = subprocess.run([tool, '-w', '5', '-S'],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=15)
        if result.returncode:
            raise RuntimeError('Cannot inspect the existing SSH source filter')
        if '-N TARASEC_SSH_SOURCE' not in result.stdout.splitlines():
            continue  # Never create a source policy or INPUT hook during enrollment.
        for source in selected:
            rule = ['-p', 'tcp', '--dport', str(port), '-s', source, '-j', 'RETURN']
            check = subprocess.run([tool, '-w', '5', '-C', 'TARASEC_SSH_SOURCE', *rule],
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=15)
            if check.returncode == 0:
                continue
            if check.returncode != 1:
                raise RuntimeError('Cannot verify the existing SSH source rule')
            added = subprocess.run([tool, '-w', '5', '-I', 'TARASEC_SSH_SOURCE', '1', *rule],
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=15)
            if added.returncode:
                raise RuntimeError('SSH source saved but source filter reconciliation failed')


def reconcile_gate():
    import ssh_google_window as window
    port, sources = window.configuration(require_enabled=False)
    reconcile_source_filter(port, sources)
    policy = window.settings(window.POLICY)
    if policy.get('SSH_GOOGLE_REOPEN_ENABLED', 'no').lower() not in ('yes', 'true', 'on', '1'):
        return  # Retain the owner's disabled gate; never enable it here.
    result = subprocess.run([sys.executable, str(Path(window.__file__)), 'reconcile'],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, timeout=30)
    if result.returncode != 0:
        raise RuntimeError('SSH source saved but timed gate reconciliation failed')


def allow_source(value, config=CONF, state=STATE):
    source = source_address(value)
    config, state = Path(config), Path(state)
    state.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = state.lstat()
    if state.is_symlink() or not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
        raise ValueError('Unsafe SSH source backup directory')
    lockfd = os.open(state/'lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_NONBLOCK, 0o600)
    with os.fdopen(lockfd, 'r+b') as lock:
        info = os.fstat(lock.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077 or info.st_nlink != 1:
            raise ValueError('Unsafe SSH source update lock')
        fcntl.flock(lock, fcntl.LOCK_EX)
        old, original = read_config(config)
        new = updated_config(old, source)
        changed = new != old
        if changed:
            fd, backup = tempfile.mkstemp(prefix='tarasecfw-', suffix='.conf', dir=state)
            with os.fdopen(fd, 'wb') as saved:
                saved.write(old.encode('utf-8'))
                saved.flush()
                os.fsync(saved.fileno())
            fd, temporary = tempfile.mkstemp(prefix='.tarasec-ssh-source-', dir=config.parent)
            try:
                with os.fdopen(fd, 'wb') as target:
                    os.fchown(target.fileno(), original.st_uid, original.st_gid)
                    target.write(new.encode('utf-8'))
                    target.flush()
                    os.fsync(target.fileno())
                # Preserve mode, ACLs and extended attributes of the owner configuration.
                shutil.copystat(config, temporary, follow_symlinks=False)
                os.utime(temporary, None)
                current, current_info = read_config(config)
                if current != old or (current_info.st_dev, current_info.st_ino) != (original.st_dev, original.st_ino):
                    raise RuntimeError('SSH configuration changed during enrollment; retry')
                os.replace(temporary, config)
                directory = os.open(config.parent, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    os.fsync(directory)
                finally:
                    os.close(directory)
            finally:
                if os.path.exists(temporary):
                    os.unlink(temporary)
        # Runs even on a retry. Reconcile preserves the current window deadline.
        reconcile_gate()
        return changed
