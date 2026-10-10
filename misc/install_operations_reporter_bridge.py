#!/usr/bin/env python3
"""Guarded demo bridge into the discovered legacy minute reporter."""
import hashlib
import os
from pathlib import Path
import re
import subprocess
import tempfile

MARKER = '# TARASEC_OPERATIONS_REPORT_BRIDGE_V1'
HELPER = r'''
# TARASEC_OPERATIONS_REPORT_BRIDGE_V1
sub tarasecOperationsSnapshotForReport {
    my ($path) = @_;
    return {status => "unavailable", reason => "no_recent_assessment"}
        unless -f $path && !-l $path && -r $path && (-s $path // 0) <= 16384;
    open(my $snapshot, "<", $path) or return {status => "unavailable"};
    local $/;
    my $raw = <$snapshot>;
    close($snapshot);
    my $value = eval { JSON::decode_json($raw // "") };
    return {status => "unavailable", reason => "invalid_assessment"}
        unless ref($value) eq "HASH" && defined($value->{checked_at}) &&
        $value->{checked_at} =~ /^\d+$/;
    my $age = time() - $value->{checked_at};
    return {status => "stale", age_seconds => $age}
        if $age > 180 || $age < -60;
    $value->{age_seconds} = $age < 0 ? 0 : $age;
    return $value;
}
'''

def patched_source(source):
    if MARKER in source:
        return source
    matches = list(re.finditer(r'sub reportStatus\s*\{(?:(?!\nsub ).){0,1000}?my %json;', source, re.S))
    if len(matches) != 1:
        raise ValueError('Unsupported reporter layout; preserve source for review')
    insertion = matches[0].end()
    fields = '\n\t$json{"operationsAgent"} = tarasecOperationsSnapshotForReport("/var/lib/tarasec-operations/report.json");\n'
    if not re.search(r'["\x27]aiAgent["\x27]', source):
        fields += '\t$json{"aiAgent"} = tarasecOperationsSnapshotForReport("/var/lib/tarasec/agent-status.json");\n'
    start = matches[0].start()
    return source[:start] + HELPER + source[start:insertion] + fields + source[insertion:]

def install_bridge(path, expected_sha):
    path = Path(path)
    if path.name != 'crontasks.pl' or not path.is_absolute():
        raise ValueError('Only the discovered absolute minute reporter path is supported')
    for part in (path, *path.parents):
        info = part.lstat()
        if part.is_symlink() or info.st_uid != 0 or info.st_mode & 0o022:
            raise ValueError('Reporter and parents must be root owned and not group/world writable')
    info = path.stat()
    if not path.is_file() or info.st_size > 1024 * 1024:
        raise ValueError('Reporter must be a bounded regular file')
    raw = path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != expected_sha:
        raise ValueError('Reporter changed after inspection; re-diagnose before deployment')
    updated = patched_source(raw.decode('utf-8')).encode()
    if updated == raw:
        print('Reporter bridge already installed; central receipt remains unverified.')
        return
    backup = path.with_name(path.name + '.before-operations-' + expected_sha[:12])
    fd = os.open(backup, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(raw)
        stream.flush()
        os.fsync(stream.fileno())
    fd, temporary = tempfile.mkstemp(prefix='.operations-reporter-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(updated)
            stream.flush()
            os.fchown(stream.fileno(), info.st_uid, info.st_gid)
            os.fchmod(stream.fileno(), info.st_mode & 0o777)
            os.fsync(stream.fileno())
        syntax = subprocess.run(['/usr/bin/perl', '-c', temporary], cwd=path.parent,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20, check=False)
        if syntax.returncode != 0:
            raise ValueError('Patched reporter syntax/dependencies did not validate; original preserved')
        # Recheck identity and bytes immediately before replacement.
        if path.stat().st_ino != info.st_ino or path.read_bytes() != raw:
            raise ValueError('Reporter changed during deployment')
        os.replace(temporary, path)
    finally:
        Path(temporary).unlink(missing_ok=True)
    print('Minute reporter bridge installed; wait for cron and verify central DB receipt. Security enrollment is still independent.')
    print('Recovery backup: ' + str(backup))

if __name__ == '__main__':
    import sys
    if os.geteuid() != 0 or len(sys.argv) != 3:
        raise SystemExit('Run as root with discovered reporter path and SHA256')
    install_bridge(sys.argv[1], sys.argv[2])
