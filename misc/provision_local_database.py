#!/usr/bin/env python3
"""Provision local-only application accounts without secrets in argv or output."""
import fcntl
import grp
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import tempfile

DIRECTORY = Path('/etc/tarasec')
ACCOUNTS = {'app': 'scriptUsrAces3f3', 'perl': 'perl'}


def secure_file(path, value, mode=0o600, gid=0):
    if path.is_symlink():
        raise RuntimeError('Refusing symlink credential path')
    fd, temporary = tempfile.mkstemp(prefix='.db-', dir=path.parent)
    try:
        os.fchmod(fd, mode)
        os.fchown(fd, 0, gid)
        with os.fdopen(fd, 'w') as output:
            output.write(value)
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def password(directory, kind, web_gid):
    path = directory / ('db-' + kind + '.password')
    if path.exists() or path.is_symlink():
        if path.is_symlink() or not stat.S_ISREG(path.stat().st_mode) or path.stat().st_uid != 0:
            raise RuntimeError('Unsafe database credential file')
        value = path.read_text().strip()
        if not re.fullmatch(r'[a-f0-9]{64}', value):
            raise RuntimeError('Invalid stored database credential; refusing regeneration')
    else:
        value = secrets.token_hex(32)
    secure_file(path, value + '\n', 0o640 if kind == 'app' else 0o600, web_gid if kind == 'app' else 0)
    return value


def mysql(sql, *, defaults=None):
    argv = ['mysql']
    if defaults:
        argv.append('--defaults-file=' + str(defaults))
    else:
        argv += ['--protocol=socket', '--user=root']
    result = subprocess.run(argv + ['--batch', '--skip-column-names'], input=sql,
                            text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30, check=False)
    if result.returncode:
        # SQL errors may echo the statement containing an issued password.
        raise RuntimeError('Local database provisioning failed; inspect database health and root socket access')
    return result.stdout


def provision(directory=DIRECTORY, execute=mysql, web_gid=None):
    if os.geteuid() != 0:
        raise RuntimeError('Run as root')
    web_gid = grp.getgrnam(os.environ.get('TARASEC_DB_WEB_GROUP', 'www-data')).gr_gid if web_gid is None else web_gid
    if directory.is_symlink():
        raise RuntimeError('Refusing symlink configuration directory')
    directory.mkdir(mode=0o750, parents=True, exist_ok=True)
    if directory.stat().st_uid != 0:
        raise RuntimeError('Configuration directory must be root-owned')
    os.chown(directory, 0, web_gid)
    os.chmod(directory, 0o750)
    lock_path = directory / 'database-install.lock'
    fd = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        for kind, user in ACCOUNTS.items():
            value = password(directory, kind, web_gid)
            cnf = '[client]\nuser=' + user + '\npassword=' + value + '\nhost=localhost\nprotocol=socket\n'
            config_path = directory / ('db-' + kind + '.cnf')
            secure_file(config_path, cnf)
            if kind == 'app':
                secure_file(directory / 'access-mysql.cnf', cnf)
            # Fixed local account names and generated hex values only; never accept SQL identifiers from a caller.
            execute("CREATE USER IF NOT EXISTS '" + user + "'@'localhost' IDENTIFIED BY '" + value + "';\n"
                    "ALTER USER '" + user + "'@'localhost' IDENTIFIED BY '" + value + "';\n"
                    "GRANT SELECT, INSERT, UPDATE, DELETE ON taransvar.* TO '" + user + "'@'localhost';\n")
            execute('SELECT 1;\n', defaults=config_path)
    return True


if __name__ == '__main__':
    try:
        provision()
        print('Local TaraSec database credentials installed and verified; existing generated passwords preserved.')
    except (OSError, KeyError, RuntimeError, subprocess.SubprocessError):
        print('Local database provisioning failed. No credentials were printed. Check root socket access and private configuration permissions.', file=__import__('sys').stderr)
        raise SystemExit(1)
