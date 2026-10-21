"""Owner-authorized required-reboot maintenance, independent of model budgets."""
import os
import re
from pathlib import Path
import time
import math
import stat
from operations_policy import maintenance_enabled

DEFAULT_PROBE = "/usr/local/lib/tarasec-operations/reboot-verification-probe"


TTY_NAME = r'(?:pts/[0-9]+|tty(?:S|AMA|USB|ACM|hvc)?[0-9]+|hvc[0-9]+|xvc[0-9]+)'


def session_terminals(run, session, properties, root):
    """Resolve missing logind TTY through kernel session-scope membership."""
    scope = properties.get('Scope', '')
    if scope != 'session-' + session + '.scope':
        raise ValueError('Session scope unavailable')
    answer = run(['systemctl', 'show', scope, '-p', 'ControlGroup', '--value'], 5)
    group = answer['output'].strip()
    if (answer['exit_code'] != 0 or not group.startswith('/') or
            '..' in group.split('/') or not group.endswith('/' + scope)):
        raise ValueError('Invalid session control group')
    directory = root / 'sys/fs/cgroup' / group.lstrip('/')
    members = set()
    for path in [directory / 'cgroup.procs', *directory.glob('**/cgroup.procs')]:
        members.update(path.read_text().split())
    if not members or len(members) > 512:
        raise ValueError('Session process inventory unavailable')
    terminals = set()
    for pid in members:
        if not pid.isdigit():
            raise ValueError('Invalid session PID')
        proc = root / 'proc' / pid
        try:
            # Recheck membership to reject PID reuse or a process moved elsewhere.
            memberships = [line.split(':', 2)[2] for line in
                           (proc / 'cgroup').read_text().splitlines()]
            if not any(g == group or g.startswith(group + '/') for g in memberships):
                raise ValueError('Session membership changed')
            fields = (proc / 'stat').read_text().rsplit(')', 1)[1].split()
            device = int(fields[4]) & 0xffffffff  # tty_nr, after pid and comm.
            if device == 0:
                continue
            major = (device >> 8) & 0xfff
            minor = (device & 0xff) | ((device >> 12) & 0xfff00)
            found = False
            for fd in ('0', '1', '2'):
                link = proc / 'fd' / fd
                try:
                    name = os.readlink(link)
                except FileNotFoundError:
                    continue
                if not name.startswith('/dev/') or not re.fullmatch(TTY_NAME, name[5:]):
                    continue
                metadata = (root / name.lstrip('/')).stat()
                if (stat.S_ISCHR(metadata.st_mode) and os.major(metadata.st_rdev) == major
                        and os.minor(metadata.st_rdev) == minor):
                    terminals.add(name[5:])
                    found = True
            if not found:
                raise ValueError('Controlling terminal unresolved')
        except FileNotFoundError:
            # A session process may exit during inspection; never infer idle from it.
            raise ValueError('Session process disappeared')
    if not terminals:
        raise ValueError('No verified session terminal')
    return sorted(terminals)


def session_activity(run, policy, root=Path('/')):
    """TTY input time measures activity, rather than merely an open login."""
    idle_seconds = policy.get('reboot_session_idle_seconds', 900)
    if not isinstance(idle_seconds, int) or isinstance(idle_seconds, bool) or idle_seconds < 60:
        return {'blockers': ['invalid_reboot_session_idle_seconds'], 'sessions': []}
    result = run(['loginctl', 'list-sessions', '--no-legend', '--no-pager'], 20)
    evidence = {'idle_seconds': idle_seconds, 'sessions': [], 'blockers': []}
    if result['exit_code'] != 0:
        evidence['blockers'].append('interactive_session_activity_unverified')
        return evidence
    now = time.time()
    for line in result['output'].splitlines():
        if not line.strip():
            continue
        session = line.split()[0]
        item = {'session': session, 'status': 'unknown'}
        evidence['sessions'].append(item)
        if not re.fullmatch(r'[A-Za-z0-9_-]+', session):
            evidence['blockers'].append('interactive_session_activity_unverified')
            continue
        details = run(['loginctl', 'show-session', session, '--no-pager',
                       '-p', 'TTY', '-p', 'TimestampMonotonic', '-p', 'IdleHint',
                       '-p', 'IdleSinceHintMonotonic', '-p', 'Scope'], 20)
        try:
            if details['exit_code'] != 0:
                raise ValueError('Session inspection failed')
            properties = dict(line.split('=', 1) for line in details['output'].splitlines())
            uptime = float((root / 'proc/uptime').read_text().split()[0])
            age = uptime - int(properties['TimestampMonotonic']) / 1000000
            tty = properties.get('TTY', '')
            if tty:
                if not re.fullmatch(TTY_NAME, tty):
                    raise ValueError('Unsupported TTY')
                idle = min(age, now - (root / 'dev' / tty).stat().st_atime)
                item['terminals'] = [tty]
            elif properties.get('IdleHint') == 'yes':
                since = int(properties['IdleSinceHintMonotonic']) / 1000000
                if since <= 0:
                    raise ValueError('Missing idle timestamp')
                idle = min(age, uptime - since)
            else:
                terminals = session_terminals(run, session, properties, root)
                idle = min([age] + [now - (root / 'dev' / t).stat().st_atime for t in terminals])
                item['terminals'] = terminals
            if not math.isfinite(idle) or idle < 0:
                raise ValueError('Invalid activity timestamp')
            item.update(status='idle' if idle >= idle_seconds else 'recently_active',
                        idle_seconds=int(idle))
            if idle < idle_seconds:
                evidence['blockers'].append('interactive_session_recently_active')
        except (OSError, ValueError, KeyError, IndexError) as error:
            item['reason'] = str(error)[:160]
            evidence['blockers'].append('interactive_session_activity_unverified')
    evidence['blockers'] = sorted(set(evidence['blockers']))
    return evidence


def safe_runner(runner):
    def checked(argv, timeout):
        try:
            return runner(argv, timeout)
        except OSError:
            return {'exit_code': 127, 'output': ''}
    return checked


def inspect(run, root=Path('/')):
    run = safe_runner(run)
    marker = root / 'var/run/reboot-required'
    evidence = {'required': marker.exists(), 'running_kernel': os.uname().release,
                'checked_at': time.time(), 'blockers': []}
    if not evidence['required']:
        return evidence
    packages = root / 'var/run/reboot-required.pkgs'
    evidence['packages'] = packages.read_text()[:2000].splitlines()[:40] if packages.exists() else []
    kernels = [p.name[8:] for p in (root / 'boot').glob('vmlinuz-*') if p.is_file()]
    # Debian version ordering (lexical ordering puts 6.8.0-9 after 6.8.0-100).
    target = None
    for candidate in kernels:
        if target is None or run(['dpkg', '--compare-versions', candidate, 'gt', target], 10)['exit_code'] == 0:
            target = candidate
    evidence['target_kernel'] = target
    if not target:
        evidence['blockers'].append('boot_kernel_missing')
        return evidence
    if not (root / 'boot' / ('initrd.img-' + target)).is_file():
        evidence['blockers'].append('target_initramfs_missing')
    if not (root / 'lib/modules' / target / 'build').is_dir():
        evidence['blockers'].append('target_headers_missing')
    module = run(['modinfo', '-k', target, '-F', 'vermagic', 'tarakernel'], 20)
    if module['exit_code'] != 0 or module['output'].split()[:1] != [target]:
        evidence['blockers'].append('target_tarakernel_missing_or_mismatched')
    dkms = run(['dkms', 'status', '-m', 'tarakernel', '-k', target], 20)
    if dkms['exit_code'] != 0 or not any(target in line and line.rstrip().endswith(': installed') for line in dkms['output'].splitlines()):
        evidence['blockers'].append('target_dkms_installation_unverified')
    dependency = run(['modprobe', '--dry-run', '--set-version', target, 'tarakernel'], 20)
    if dependency['exit_code'] != 0:
        evidence['blockers'].append('target_module_dependencies_failed')
    # Do not infer boot readiness from an installed file when a custom boot
    # selection or Secure Boot can load another kernel or reject the module.
    grub = root / 'boot/grub/grub.cfg'
    text = grub.read_text(errors='replace') if grub.is_file() else ''
    entries = re.findall(r'^\s*linux(?:efi)?\s+\S*vmlinuz-([^\s/]+)', text, re.M)
    if not entries or entries[0] != target:
        evidence['blockers'].append('target_boot_entry_unverified')
    environment = root / 'boot/grub/grubenv'
    if environment.exists() and re.search(rb'(?:^|\n)next_entry=[^\n\x00]+', environment.read_bytes()):
        evidence['blockers'].append('one_shot_boot_selection_unverified')
    defaults = root / 'etc/default/grub'
    lines = defaults.read_text().splitlines() if defaults.exists() else []
    if not any(line.strip() in ('GRUB_DEFAULT=0', 'GRUB_DEFAULT="0"', "GRUB_DEFAULT='0'") for line in lines):
        evidence['blockers'].append('default_boot_selection_unverified')
    if any(line.strip().startswith('GRUB_SAVEDEFAULT=') and 'true' in line for line in lines):
        evidence['blockers'].append('saved_boot_selection_unverified')
    if (root / 'sys/firmware/efi').exists():
        secure = run(['mokutil', '--sb-state'], 20)
        if secure['exit_code'] != 0 or 'SecureBoot disabled' not in secure['output']:
            evidence['blockers'].append('secure_boot_module_acceptance_unverified')
    return evidence


def permission(policy, state, now):
    # Demo mode always grants reboot permission, even if the obsolete
    # allow_reboot setting is false or absent. Permission is not unconditional
    # dispatch: interrupted work and the daily limit still block here, while
    # maintain() separately checks boot readiness, activity and quiet time.
    if not maintenance_enabled(policy):
        return 'local_mode_prohibits_reboot'
    if state.get('command_pending'):
        return 'interrupted_command_requires_reconciliation'
    recent = [stamp for stamp in state.get('reboots', []) if 0 <= now - stamp < 86400]
    maximum = policy.get('max_reboots_per_day', 1)
    if not isinstance(maximum, int) or isinstance(maximum, bool) or maximum < 0:
        return 'invalid_reboot_limit'
    if len(recent) >= maximum:
        return 'daily_reboot_limit_reached'
    return None


def verify(checkpoint, policy, run, trusted, root=Path('/')):
    run = safe_runner(run)
    blockers = []
    if os.uname().release != checkpoint['target_kernel']:
        blockers.append('booted_kernel_differs_from_target')
    if (root / 'var/run/reboot-required').exists():
        blockers.append('reboot_requirement_not_cleared')
    loaded = run(['lsmod'], 20)
    if loaded['exit_code'] != 0 or not any(line.split()[:1] == ['tarakernel'] for line in loaded['output'].splitlines()):
        blockers.append('tarakernel_not_loaded')
    for service in checkpoint['services']:
        if run(['systemctl', 'is-active', '--quiet', service], 20)['exit_code'] != 0:
            blockers.append('service_not_active:' + service)
    for version, before in checkpoint.get('forwarding', {}).items():
        file = root / version
        if before == '1' and (not file.exists() or file.read_text().strip() != '1'):
            blockers.append('forwarding_not_restored:' + version)
    # Operator-owned probe checks real TaraLink/DB exchange and a fresh central
    # heartbeat. Exit 0 is its explicit acceptance contract, not model text.
    probe = policy.get('reboot_verification_probe') or DEFAULT_PROBE
    if not probe:
        blockers.append('functional_verification_probe_missing')
    else:
        try:
            accepted = run([str(trusted(probe))], 30)['exit_code'] == 0
        except (OSError, ValueError):
            accepted = False
        if not accepted:
            blockers.append('functional_verification_pending_or_failed')
    return blockers


def maintain(state, policy, evidence, boot, now, run, trusted, quiet, persist, record, root=Path('/')):
    """Return True when reboot maintenance owns this tick (including blockers)."""
    run = safe_runner(run)
    checkpoint = state.get('reboot_checkpoint')
    if checkpoint:
        if checkpoint['boot_id'] == boot:
            state['reboot'] = dict(evidence, status='awaiting_boot', blockers=['reboot_did_not_change_boot'])
            state['status'] = 'reboot_waiting_for_boot'
        else:
            blockers = verify(checkpoint, policy, run, trusted, root)
            state['reboot'] = dict(evidence, status='verification_pending' if blockers else 'verified', blockers=blockers)
            state['status'] = 'reboot_verification_pending' if blockers else 'reboot_verified'
            if not blockers:
                state['last_verified_reboot'] = dict(checkpoint, verified_at=now)
                state.pop('reboot_checkpoint', None)
                state.pop('reboot_pending', None)
                state['resume_required'] = False
                record({'reboot_verified': state['last_verified_reboot']})
        persist()
        return True
    state['reboot'] = dict(evidence, status='required' if evidence['required'] else 'not_required')
    if not evidence['required']:
        return False
    blocked = permission(policy, state, now)
    blockers = ([blocked] if blocked else []) + evidence['blockers']
    probe = policy.get('reboot_verification_probe') or DEFAULT_PROBE
    if not probe:
        blockers.append('functional_verification_probe_missing')
    elif not blockers:
        try:
            executable = trusted(probe)
            if not executable.is_file() or not os.access(executable, os.X_OK):
                blockers.append('functional_verification_probe_not_executable')
        except (OSError, ValueError):
            blockers.append('functional_verification_probe_unavailable_or_untrusted')
    services = policy.get('reboot_required_services', ['taralink.service', 'netbird.service', 'rsyslog.service'])
    if not isinstance(services, list) or not services or len(services) > 20 or any(
            not isinstance(s, str) or not s.endswith('.service') or not all(c.isalnum() or c in '@_.-' for c in s) for s in services):
        blockers.append('invalid_reboot_required_services')
    if blockers:
        state['reboot']['blockers'] = blockers
        state['reboot']['status'] = 'blocked'
        # Keep unrelated model diagnostics available even when reboot is disabled.
        return False
    sessions = session_activity(run, policy, root)
    state['reboot']['session_activity'] = sessions
    if sessions['blockers']:
        state['reboot'].update(status='waiting_for_quiet', blockers=sessions['blockers'])
        return False
    observed = run([str(trusted(policy['quiet_probe']))], 20)
    import json
    sample = json.loads(observed['output']) if observed['exit_code'] == 0 else {}
    state['activity'] = sample
    if not quiet(state, sample, time.time(), max(60, int(policy.get('quiet_seconds', 300)))):
        state['reboot'].update(status='waiting_for_quiet', blockers=['activity_or_quiet_time_unverified'])
        return False
    # Repeat readiness and activity immediately before dispatch. No model output
    # or cached diagnosis can substitute for either observation.
    fresh = inspect(run, root)
    if not fresh['required'] or fresh['blockers'] or fresh.get('target_kernel') != evidence['target_kernel']:
        state['reboot'] = dict(fresh, status='readiness_changed')
        return False
    observed = run([str(trusted(policy['quiet_probe']))], 20)
    sample = json.loads(observed['output']) if observed['exit_code'] == 0 else {}
    if not quiet(state, sample, time.time(), max(60, int(policy.get('quiet_seconds', 300)))):
        state['reboot'].update(status='waiting_for_quiet', blockers=['activity_changed_before_dispatch'])
        return False
    sessions = session_activity(run, policy, root)
    state['reboot']['session_activity'] = sessions
    if sessions['blockers']:
        state['reboot'].update(status='waiting_for_quiet', blockers=sessions['blockers'])
        return False
    checkpoint = {'boot_id': boot, 'requested_at': now, 'target_kernel': evidence['target_kernel'],
                  'reason': 'OS reboot-required marker', 'packages': evidence.get('packages', []),
                  'services': services, 'task_state': state.get('task_state', {}), 'forwarding': {}}
    for file in ('proc/sys/net/ipv4/ip_forward', 'proc/sys/net/ipv6/conf/all/forwarding'):
        path = root / file
        if path.exists():
            checkpoint['forwarding'][file] = path.read_text().strip()
    state['reboot_checkpoint'] = checkpoint
    state['reboot_pending'] = True
    state['reboots'] = [t for t in state.get('reboots', []) if 0 <= now - t < 86400] + [now]
    state['status'] = 'reboot_requested'
    state['reboot']['status'] = 'requested'
    persist()  # Durable checkpoint and budget BEFORE calling systemctl.
    record({'reboot_requested': checkpoint})
    result = run(['systemctl', 'reboot'], 30)
    if result['exit_code'] != 0:
        state.pop('reboot_checkpoint', None)
        state.pop('reboot_pending', None)
        state['status'] = 'reboot_failed'
        state['reboot'].update(status='failed', blockers=['reboot_command_failed'])
        record({'reboot_failed': result['exit_code']})
    persist()
    return True


def functional_receipt(sample, boot, boot_started, now):
    """A fresh report from this boot must prove kernel/link health at the DB."""
    stamp = sample.get('central_received_at')
    return (isinstance(stamp, (int, float)) and not isinstance(stamp, bool)
            and boot_started <= stamp <= now and now - stamp <= 180
            and sample.get('operations_boot_id') == boot
            and sample.get('kernel_running') is True and sample.get('link_running') is True)


def probe_main():
    # Reuse the source-bound feed token and its HTTPS/explicit NetBird transport.
    from operations_collectors import demo
    boot = Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    started = time.time() - float(Path('/proc/uptime').read_text().split()[0])
    try:
        accepted = functional_receipt(demo(), boot, started, time.time())
    except Exception:
        accepted = False
    raise SystemExit(0 if accepted else 1)


if __name__ == '__main__':
    probe_main()

