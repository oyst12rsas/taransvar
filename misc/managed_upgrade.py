#!/usr/bin/env python3
"""Daily desired-state maintenance, pinned releases and a central canary gate."""
import argparse
import fcntl
import fnmatch
import hashlib
import io
import ipaddress
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import tarfile
import tempfile
import time
import urllib.parse
import urllib.request

POLICY = Path('/etc/tarasec/managed-upgrade.json')
STATE = Path('/var/lib/tarasec/upgrade/state.json')
INSTALL = Path('/usr/local/lib/tarasec-upgrade')
CORE = ('tarakernel', 'database', 'runtime', 'taralink', 'gatekeeper', 'google', 'operations',
        'ssh-key', 'ssh-auth', 'ssh-window', 'honeypot')
SHA = re.compile(r'[a-f0-9]{40}')
PATHS = {'database': ('misc/install.sql',), 'runtime': ('misc/',),
         'taralink': ('taralink/',), 'gatekeeper': ('html/',),
         'google': ('misc/install_agent_approvals.sh', 'misc/agent_approval_worker.py',
                    'misc/ssh_google_window.py', 'misc/ssh_approval_poll.py',
                    'misc/ssh_security_evidence.py', 'misc/local_manager_policy.py',
                    'misc/enroll_google_manager.py', 'misc/enroll_gatekeeper_google.py'),
         'operations': ('misc/operations*.py', 'misc/install_operations*.py', 'misc/install_operations_agent.sh'),
         'honeypot': ('misc/setup_ssh_honeypot.sh', 'misc/tarasec_ssh_honeypot.py')}


def run(argv, **kwargs):
    return subprocess.run([str(a) for a in argv], check=True, text=True, **kwargs)


def atomic(path, data, mode=0o600):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as out:
            os.fchmod(out.fileno(), mode)
            json.dump(data, out, indent=2); out.write('\n'); out.flush(); os.fsync(out.fileno())
        os.replace(temp, path)
    finally:
        if os.path.exists(temp): os.unlink(temp)


def private_json(path):
    from enroll_google_manager import private_read
    return json.loads(private_read(path))


def record(result, scheduler_only=False):
    STATE.parent.mkdir(parents=True,exist_ok=True)
    with (STATE.parent/'receipt.lock').open('w') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        state = json.loads(STATE.read_text()) if STATE.exists() else {'features': {}, 'history': []}
        if scheduler_only:
            merged=state.get('managed_upgrade',{})
            merged['scheduler']=result['scheduler'];result=merged
        state['managed_upgrade'] = result
        atomic(STATE, state)
        software = STATE.parent/'software.json'
        data = json.loads(software.read_text()) if software.exists() else {}
        data['managed_upgrade'] = result
        atomic(software, data, 0o644)


def scheduler_notice(status, reason):
    record({'scheduler':{'status':status,'reason':reason,'checked_at':time.time()}},scheduler_only=True)


def git(policy, *args, **kwargs):
    source = Path(policy['source_repo']).resolve()
    owner = pwd.getpwuid(source.stat().st_uid).pw_name
    cmd = ['git', '-C', str(source), *args]
    if os.geteuid() == 0 and owner != 'root': cmd = ['runuser', '-u', owner, '--', *cmd]
    return run(cmd, env=dict(os.environ, GIT_TERMINAL_PROMPT='0', GIT_NO_REPLACE_OBJECTS='1'), **kwargs)


def export_release(policy, revision, destination):
    if not SHA.fullmatch(revision): raise ValueError('Invalid release revision')
    # A central reply cannot select arbitrary code: it must be in this repository's main history.
    git(policy, 'merge-base', '--is-ancestor', revision, 'refs/remotes/origin/main', capture_output=True)
    source = Path(policy['source_repo']).resolve()
    owner = pwd.getpwuid(source.stat().st_uid).pw_name
    cmd = ['git', '-C', str(source), 'archive', '--format=tar', revision]
    if owner != 'root': cmd = ['runuser', '-u', owner, '--', *cmd]
    archive = subprocess.run(cmd, check=True, capture_output=True, env=dict(os.environ,GIT_NO_REPLACE_OBJECTS='1')).stdout
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        for member in tar.getmembers():
            if member.issym() or member.islnk() or not (member.isfile() or member.isdir()):
                raise ValueError('Release archive contains unsupported links/devices')
        tar.extractall(destination, filter='data')


def gate(policy, offered, version):
    from operations_agent import run as collector_run, trusted
    from operations_collectors import validate_feed_url
    cfg = json.loads(trusted('/etc/tarasec/operations-activity.json').read_text())
    validate_feed_url(cfg, collector_run)
    token = trusted(cfg['demo_key_file']).read_text().strip()
    url = cfg['demo_url'] + '?' + urllib.parse.urlencode(dict(rollout=1, revision=offered, version=version))
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs): return None
    request = urllib.request.Request(url, headers={'X-TaraSec-Operations-Token': token})
    with urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect()).open(request, timeout=10) as response:
        raw = response.read(16385)
    if len(raw)>16384: raise ValueError('Oversized rollout response')
    answer = json.loads(raw)
    if answer.get('complete') is not True or not 0 <= time.time()-answer.get('checked_at', 0) <= 30:
        raise ValueError('Rollout evidence missing or stale')
    rollout = answer.get('rollout')
    if not isinstance(rollout, dict): raise ValueError('Central rollout service not installed')
    return rollout


def quiet(policy):
    from operations_reboot import session_activity
    from operations_agent import run as safe_run, trusted
    data = json.loads(trusted('/run/tarasec-operations/activity.json').read_text())
    if (data.get('complete') is not True or data.get('active_demo') is not False
            or data.get('meaningful_traffic') is not False
            or not 0 <= time.time()-data.get('checked_at', 0) <= 30
            or data.get('quiet_for_seconds', 0) < policy.get('quiet_seconds', 300)):
        return False
    return not session_activity(safe_run, {'reboot_session_idle_seconds': 300})['blockers']


def feature_plan(policy, release, cohort='fleet'):
    tasks = release.get('managed_tasks', {})
    if not isinstance(tasks, dict): raise ValueError('Invalid release task catalog')
    allowed = {'ready','being_tested','requires_manual_installation','requires_local_testing'}
    if any(not isinstance(name,str) or not re.fullmatch(r'[a-z][a-z0-9-]{0,63}',name) for name in tasks):
        raise ValueError('Invalid task identifier')
    if any(value not in allowed for value in tasks.values()): raise ValueError('Unknown task deployment state')
    selected, skipped = [], []
    for name in CORE:
        if name in policy.get('excluded_features', []): continue
        if name in ('database', 'gatekeeper') and not set(policy['roles']) & {'database', 'gateway'}:
            continue
        status = tasks.get(name, 'requires_local_testing')
        if status == 'ready' or (status == 'being_tested' and cohort == 'canary'):
            selected.append(name)
        else: skipped.append({'component':name, 'state':status})
    # New tasks require an implemented adapter; unknown IDs can never become commands.
    for name,status in tasks.items():
        if name not in CORE:
            skipped.append({'component':name,'state':'requires_manual_installation'})
    return selected, skipped


def fingerprint(repo, name):
    digest = hashlib.sha256()
    paths = PATHS.get(name, ())
    for path in sorted(p for p in repo.rglob('*') if p.is_file() and not p.is_symlink()):
        relative = path.relative_to(repo).as_posix()
        if any((relative.startswith(prefix) if prefix.endswith('/') else fnmatch.fnmatchcase(relative,prefix)) for prefix in paths):
            digest.update(relative.encode()); digest.update(path.read_bytes())
    return digest.hexdigest()


def component_unchanged(policy, old, revision, name, digest):
    if old.get('managed_fingerprint')==digest: return True
    previous=old.get('installed_revision','')
    if not isinstance(previous,str) or not SHA.fullmatch(previous): return False
    paths=PATHS.get(name)
    if not paths: return False
    try:
        return not git(policy,'diff','--name-only',previous,revision,'--',*paths,capture_output=True).stdout.strip()
    except (OSError,subprocess.SubprocessError): return False


def active(*units):
    return any(subprocess.run(['systemctl', 'is-active', '--quiet', unit],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0 for unit in units)


def healthy(name, u, policy):
    if name == 'tarakernel': return Path('/sys/module/tarakernel').is_dir()
    if name == 'runtime': return Path('/root/taransvar/perl/crontasks.pl').is_file()
    if name == 'database':
        try:
            value = run(['mariadb', '--batch', '--skip-column-names', 'taransvar', '-e',
                         'SELECT dbVersion FROM setup LIMIT 1'], capture_output=True).stdout.strip()
            versions = re.findall(r'^#version\s+(\d+)\b', (u.REPO/'misc/install.sql').read_text(), re.M)
            return value.isdigit() and int(value) == max(map(int, versions))
        except (OSError, ValueError, subprocess.SubprocessError): return False
    if name == 'taralink': return active('taralink.service')
    if name == 'gatekeeper':
        if not Path('/var/www/html/gatekeeper/index.php').is_file() or not active('apache2.service','nginx.service'): return False
        try:
            class NoRedirect(urllib.request.HTTPRedirectHandler):
                def redirect_request(self,*args,**kwargs): return None
            with urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect()).open('http://127.0.0.1/gatekeeper/index.php',timeout=5) as response:
                body=response.read(65536)
                return response.status==200 and b'tarasec-product' in body and not body.lstrip().startswith(b'<?')
        except (OSError,ValueError): return False
    if name == 'google':
        if not u.existing_google_setup() or not u.approval_worker_current(): return False
        if not active('tarasec-agent-approvals.timer'): return False
        try:
            snapshot=json.loads(Path('/var/lib/tarasec/agent-status.json').read_text())
            return 0<=time.time()-snapshot.get('checked_at',0)<=180 and snapshot.get('approval_service') in ('connected','checking')
        except (OSError,ValueError): return False
    if name == 'operations': return u.operations_installed() and active('tarasec-operations-agent.timer')
    if name == 'ssh-window': return u.ssh_window_current()
    if name == 'ssh-auth': return u.ssh_auth_policy(policy['admin_user']).get('authenticationmethods') == 'publickey,password'
    if name == 'ssh-key':
        account = pwd.getpwnam(policy['admin_user'])
        path = Path(account.pw_dir)/'.ssh/authorized_keys'
        return path.is_file() and any(re.match(r'^(ssh-|ecdsa-)', line) for line in path.read_text().splitlines())
    if name == 'honeypot':
        if not active('tarasec-ssh-honeypot.service'): return False
        port=u.settings(Path('/etc/tarasecfw.conf')).get('SSH_HONEYPOT_PORT','22')
        return port.isdigit() and bool(run(['ss','-H','-ltn','sport = :'+port],capture_output=True).stdout.strip())
    return False


class Prerequisite(Exception): pass


def apply_component(name, u, policy, backup):
    # All adapters are noninteractive. A missing real prerequisite is an issue, not an input prompt.
    if name == 'tarakernel': raise Prerequisite('Experimental kernel adapter requires a deployment-tested procedure')
    if name == 'google' and (not Path('/etc/tarasec/agent-enrollment.json').is_file() or not u.existing_google_setup()):
        from enroll_google_manager import enroll
        from local_manager_policy import manager_hashes
        if not Path('/etc/tarasec/access.conf').is_file() or not manager_hashes(): raise Prerequisite('Google manager enrollment required on the approval website')
        enroll([], sync=True)
        run(['bash', u.REPO/'misc/install_agent_approvals.sh', '--no-enrollment'])
        answer=u.refresh_google(backup)
        if not answer.get('pending_configuration'):
            run(['systemctl','restart','tarasec-agent-approvals.timer'])
            run(['systemctl','start','tarasec-agent-approvals.service'])
        return answer
    if name == 'honeypot':
        cfg = u.settings(Path('/etc/tarasecfw.conf'))
        port = cfg.get('SSH_PORT', '')
        if not port.isdigit() or int(port) == 22:
            raise Prerequisite('Administrator SSH port must be assigned and verified before installing the honeypot')
        honeypot_port=cfg.get('SSH_HONEYPOT_PORT','22')
        if not honeypot_port.isdigit(): raise Prerequisite('Honeypot port is not assigned')
        if not active('tarasec-ssh-honeypot.service') and run(['ss','-H','-ltn','sport = :'+honeypot_port],capture_output=True).stdout.strip():
            raise Prerequisite('An existing workload owns the honeypot port; role-specific integration is required')
        run(['bash', u.REPO/'misc/setup_ssh_honeypot.sh', '--refresh-only'])
        return {'administrator_ssh': 'unchanged', 'honeypot': 'installed_or_refreshed'}
    if name == 'taralink' and not Path('/usr/local/sbin/taralink').exists():
        # The legacy combined installer also installs a kernel module. Never let
        # a ready taralink task silently deploy a requires_local_testing kernel.
        if not Path('/sys/module/tarakernel').is_dir():
            raise Prerequisite('A locally tested tarakernel must be installed before starting taralink')
        helper=shutil.which('mariadb_config') or shutil.which('mysql_config')
        if not helper: raise Prerequisite('MariaDB build headers are missing')
        import shlex
        flags=shlex.split(run([helper,'--cflags','--libs'],capture_output=True).stdout)
        backup.mkdir(parents=True,exist_ok=True)
        binary=backup/'taralink.new'
        run(['gcc','-g',u.REPO/'taralink/taralink.c','-o',binary,*flags,'-lcurl','-lcjson'])
        run(['install','-o','root','-g','root','-m','0755',binary,'/usr/local/sbin/taralink'])
        run(['install','-o','root','-g','root','-m','0644',u.REPO/'misc/systemd/taralink.service','/etc/systemd/system/taralink.service'])
        Path('/etc/tarasec/taralink-managed-by-systemd').touch()
        run(['systemctl','daemon-reload']);run(['systemctl','enable','--now','taralink.service'])
        return {'service':'installed','kernel':'existing tested module preserved'}
    if name == 'ssh-key': raise Prerequisite('Administrator public key must be enrolled; no private key is generated on the server')
    if name == 'ssh-auth':
        raise Prerequisite('First key-plus-password login and recovery verification required; existing verified policy is maintained without prompts')
    if name == 'ssh-window':
        cfg = u.settings(Path('/etc/tarasec-server-manager.conf'))
        if cfg.get('SSH_GOOGLE_REOPEN_ENABLED', '').lower() not in ('yes', 'true', '1'):
            raise Prerequisite('Google/TOTP reopening and recovery console must be verified once before closing SSH')
        run(['python3', '/usr/local/lib/tarasec/ssh_google_window.py', 'reconcile'])
        return {'existing_window_policy': 'reconciled'}
    if name == 'database' and not Path('/var/lib/mysql/taransvar').exists():
        raise Prerequisite('Initial local TaraSec database and credentials must be provisioned')
    if name == 'gatekeeper' and not Path('/var/www/html/dbfunc.php').exists():
        raise Prerequisite('Initial Gatekeeper database connection must be provisioned')
    answer=u.apply_feature(name,backup)
    if name=='taralink': run(['systemctl','enable','--now','taralink.service'])
    if name=='google' and not (isinstance(answer,dict) and answer.get('pending_configuration')):
        run(['systemctl','restart','tarasec-agent-approvals.timer'])
        run(['systemctl','start','tarasec-agent-approvals.service'])
    return answer


def converge(policy, revision, generation, repo, cohort='fleet'):
    import standard_upgrade as u
    u.REPO = repo
    state = u.load_state()
    previous_result = state.get('managed_upgrade', {})
    if state.get('roles') != policy['roles']: raise ValueError('Assigned roles changed; explicit reassignment required')
    release = json.loads((repo/'release.json').read_text())
    selected, excluded = feature_plan(policy, release, cohort)
    result = dict(status='running', revision=revision, generation=generation, checked_at=time.time(),
                  withheld_tasks=[], pending=[], failed=[], cohort=cohort)
    backup = STATE.parent/'backups'/('daily-'+str(int(time.time())))
    # Existing upgrade and daily workers share the same mutation lock.
    STATE.parent.mkdir(parents=True, exist_ok=True)
    with (STATE.parent/'upgrade.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = u.load_state()
        state['managed_upgrade'] = result
        record(result)
        for task in excluded:
            name=task['component']
            try:
                current=healthy(name,u,policy)
                old=state.get('features',{}).get(name,{})
                effective_only=name in ('database','ssh-key','ssh-auth','ssh-window')
                if current and (effective_only or component_unchanged(policy,old,revision,name,fingerprint(repo,name))):
                    continue
            except (OSError,ValueError,KeyError,subprocess.SubprocessError): pass
            result['withheld_tasks'].append(task)
        for name in selected:
            old = state.setdefault('features', {}).get(name, {})
            content = fingerprint(repo, name)
            try:
                working = healthy(name, u, policy)
                effective_only = name in ('database', 'ssh-key', 'ssh-auth', 'ssh-window')
                if working and (effective_only or component_unchanged(policy,old,revision,name,content)):
                    state['features'][name]=dict(old,status='completed',managed_fingerprint=content,installed_version=release['version'],installed_revision=revision)
                    u.save(state)
                    continue
                answer = apply_component(name, u, policy, backup/name)
                if isinstance(answer, dict) and (answer.get('pending_configuration') or answer.get('activation') in ('pending_model_configuration', 'pending_model_connection')):
                    raise Prerequisite('Component configuration or web activation pending')
                if not healthy(name, u, policy): raise ValueError('Post-upgrade health check failed')
                state['features'][name] = dict(old, status='completed', managed_fingerprint=content,
                    installed_version=release['version'], installed_revision=revision, details=answer or {})
                u.save(state)
            except Prerequisite as error:
                result['pending'].append({'component':name, 'reason':str(error)})
                result['withheld_tasks'].append({'component':name,'state':'requires_local_testing' if name in ('tarakernel','ssh-auth','ssh-window') else 'requires_manual_installation'})
                # Stop dependents after a missing core dependency; never run against a guessed schema.
                if name in ('database', 'runtime', 'google'): break
            except (OSError, ValueError, subprocess.SubprocessError) as error:
                result['failed'].append({'component':name, 'reason':type(error).__name__})
                break
        result.update(status='failed' if result['failed'] else 'pending' if result['pending'] else 'verified', checked_at=time.time())
        if result['status'] == 'verified':
            # Recheck the whole eligible set after all installers have finished.
            for name in selected:
                if not healthy(name,u,policy): result['failed'].append({'component':name,'reason':'Final health check failed'})
            if result['failed']: result['status']='failed'
        if result['status'] == 'verified':
            result['health']={'ok':True,'checked_at':time.time()}
            prior = previous_result
            result['verified_at'] = prior.get('verified_at', time.time()) if prior.get('revision') == revision else time.time()
        record(result)
        if result['status']=='verified' and 'runtime' in selected and INSTALL.exists():
            for source in (repo/'misc').glob('*.py'):
                shutil.copyfile(source,INSTALL/source.name);(INSTALL/source.name).chmod(0o644)
            health_source=INSTALL/'health-source'/'misc'
            health_source.mkdir(parents=True,exist_ok=True)
            for source in (repo/'misc').glob('*.py'): shutil.copyfile(source,health_source/source.name)
            shutil.copyfile(repo/'misc/install.sql',health_source/'install.sql')
            atomic(INSTALL/'health-target.json',dict(revision=revision,generation=generation,features=selected))
    return result


def check_health(policy):
    import standard_upgrade as u
    STATE.parent.mkdir(parents=True,exist_ok=True)
    with (STATE.parent/'upgrade.lock').open('w') as lock:
        try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: return 0
        state=json.loads(STATE.read_text()) if STATE.exists() else {}
        result=state.get('managed_upgrade',{})
        if result.get('status')!='verified': return 0
        try:
            target=private_json(INSTALL/'health-target.json')
            if (target.get('revision'),target.get('generation'))!=(result.get('revision'),result.get('generation')):
                raise ValueError('Health target mismatch')
            u.REPO=INSTALL/'health-source'
            failed=[name for name in target['features'] if not healthy(name,u,policy)]
        except (OSError,ValueError,KeyError,subprocess.SubprocessError): failed=['health_evidence']
        result['health']={'ok':not failed,'checked_at':time.time()}
        if failed:
            result['status']='failed'
            result['failed']=[{'component':name,'reason':'Post-deployment health failed'} for name in failed]
        record(result)
        return 1 if failed else 0


def daily(policy):
    if Path('/etc/tarasec-operations-feed/tokens.json').is_file():
        refresh_rollout_local_addresses()
    before = json.loads(STATE.read_text()).get('managed_upgrade', {}) if STATE.exists() else {}
    origin=git(policy,'remote','get-url','origin',capture_output=True).stdout.strip()
    if origin not in ('https://github.com/oyst12rsas/taransvar.git','https://github.com/oyst12rsas/taransvar','git@github.com:oyst12rsas/taransvar.git'):
        raise ValueError('Assigned source repository changed')
    git(policy, 'fetch', '--no-tags', 'https://github.com/oyst12rsas/taransvar.git', 'main:refs/remotes/origin/main', capture_output=True)
    offered = git(policy, 'rev-parse', 'refs/remotes/origin/main', capture_output=True).stdout.strip()
    if not SHA.fullmatch(offered): raise ValueError('Invalid source revision')
    manifest = json.loads(git(policy, 'show', offered+':release.json', capture_output=True).stdout)
    decision = gate(policy, offered, manifest['version'])
    target = decision.get('target_revision')
    generation = decision.get('generation', 0)
    if decision.get('allowed') is not True or not isinstance(target, str) or not SHA.fullmatch(target):
        reason=str(decision.get('reason','rollout not ready'))
        scheduler_notice('waiting',reason)
        print('Daily upgrade waiting: '+reason)
        return 0
    if before.get('revision') == target and before.get('generation') == generation and before.get('status') == 'failed':
        print('Daily upgrade held after failure; use central rollout reset before retrying.')
        return 0
    if not quiet(policy):
        scheduler_notice('waiting','Waiting for quiet activity and inactive sessions')
        print('Daily upgrade waiting for quiet activity and inactive sessions.')
        return 0
    with tempfile.TemporaryDirectory(prefix='tarasec-release-') as tmp:
        repo = Path(tmp)
        export_release(policy, target, repo)
        # Recheck pause/generation and activity after download/export, immediately before dispatch.
        latest=gate(policy,offered,manifest['version'])
        if (latest.get('allowed') is not True or latest.get('target_revision')!=target or latest.get('generation')!=generation or latest.get('cohort')!=decision.get('cohort') or not quiet(policy)):
            scheduler_notice('waiting','Rollout or activity changed before dispatch')
            return 0
        # Execute only a controller from a revision which has passed this unit's central gate.
        try:
            result = run(['python3', repo/'misc/managed_upgrade.py', 'apply', '--revision', target,
                      '--generation', str(generation), '--cohort', decision.get('cohort','fleet')], stdin=subprocess.DEVNULL,
                         timeout=1800, capture_output=True)
        except (OSError, subprocess.SubprocessError) as error:
            def diagnostic(value):
                if isinstance(value,bytes):value=value.decode('utf-8','replace')
                return str(value or '')[-32768:]
            atomic(STATE.parent/'last-daily-error.json',dict(error=type(error).__name__,
                stdout=diagnostic(getattr(error,'stdout','')),stderr=diagnostic(getattr(error,'stderr',''))))
            current=json.loads(STATE.read_text()).get('managed_upgrade',{}) if STATE.exists() else {}
            if current.get('revision')!=target or current.get('generation')!=generation or current.get('status')!='failed':
                record(dict(status='failed', revision=target, generation=generation, checked_at=time.time(), failed=[{'component':'release','reason':'Execution failed or timed out'}]))
            raise
        print(result.stdout)
        if result.stderr: print('See component service journals for additional diagnostics.')
    return 0



def rollout_local_addresses():
    """Observe this host's assigned IPv4 addresses; no DB address or VPN assumption."""
    rows=json.loads(run(['ip','-j','-4','address','show'],capture_output=True).stdout)
    addresses=set()
    for row in rows:
        for entry in row.get('addr_info',[]):
            if entry.get('family')!='inet': continue
            address=ipaddress.IPv4Address(entry.get('local',''))
            if not address.is_loopback and not address.is_unspecified and not address.is_multicast:
                addresses.add(str(address))
    if not addresses: raise ValueError('No assigned local IPv4 address for central rollout identity')
    return sorted(addresses)


def refresh_rollout_local_addresses():
    import grp
    directory=Path('/etc/tarasec-operations-feed')
    if directory.is_symlink(): raise ValueError('Symlinked rollout identity directory')
    addresses=rollout_local_addresses()
    directory.mkdir(parents=True,exist_ok=True,mode=0o750)
    os.chown(directory,0,grp.getgrnam('www-data').gr_gid)
    os.chmod(directory,0o750)
    atomic(directory/'rollout-local-addresses.json',dict(addresses=addresses),0o644)


def prepare_rollout_web(source):
    import standard_upgrade as u
    if u.operations_central_database() is not True: return
    refresh_rollout_local_addresses()
    import grp
    directory=Path('/var/lib/tarasec-rollout')
    if directory.is_symlink(): raise ValueError('Symlinked rollout storage is not permitted')
    directory.mkdir(parents=True,exist_ok=True,mode=0o770)
    os.chown(directory,0,grp.getgrnam('www-data').gr_gid);os.chmod(directory,0o770)
    if not (directory/'policy.json').exists():
        atomic(directory/'policy.json',dict(paused=True,canaries=[],generation=1,soak_seconds=86400),0o660)
        os.chown(directory/'policy.json',0,grp.getgrnam('www-data').gr_gid)
    web=Path('/var/www/html')
    if not (web/'dbfunc.php').is_file(): raise ValueError('Central Gatekeeper web root is not configured')
    backup=STATE.parent/'backups'/('daily-bootstrap-'+str(int(time.time())))
    for relative in ('script/operationsActivity.php','script/operationsActivityAuth.php','script/upgradeRolloutLib.php','script/upgradeNotices.php','gatekeeper/func/upgradeRollout.php'):
        src=source/'html'/relative;dst=web/relative
        run(['php','-l',src],capture_output=True)
        u.backup(dst,backup)
        shutil.copyfile(src,dst);dst.chmod(0o644)
    index=web/'gatekeeper/index.php'
    text=index.read_text()
    needle='<a href="index.php?f=setup">Setup</a>'
    if 'f=upgradeRollout' not in text and needle in text:
        u.backup(index,backup)
        u.atomic(index,text.replace(needle,needle+' · <a href="index.php?f=upgradeRollout">Upgrade rollout</a>'),0o644)
    print('Central rollout control ready: /gatekeeper/index.php?f=upgradeRollout (administrator login required).')


def enroll(source, roles, admin_user, reassign=False):
    STATE.parent.mkdir(parents=True,exist_ok=True)
    with (STATE.parent/'upgrade.lock').open('w') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        return enroll_locked(source,roles,admin_user,reassign)


def enroll_locked(source, roles, admin_user, reassign=False):
    if not roles: raise ValueError('Assign roles once before enabling daily upgrades')
    old = private_json(POLICY) if POLICY.exists() else None
    if old and old['roles'] != roles and not reassign: raise ValueError('Role assignment is fixed; use explicit reassignment')
    import standard_upgrade as u
    state=u.load_state()
    if state.get('roles') and state['roles']!=roles and not reassign:
        raise ValueError('Role reassignment must be explicit')
    pwd.getpwnam(admin_user)
    source = source.resolve()
    policy = dict(enabled=True, roles=roles, admin_user=admin_user, source_repo=str(source),
                  excluded_features=(old.get('excluded_features', []) if old else
                      sorted(name for name, record in state.get('features', {}).items()
                             if record.get('choice') == 'never')), quiet_seconds=300)
    url = git(policy, 'remote', 'get-url', 'origin', capture_output=True).stdout.strip()
    if url not in ('https://github.com/oyst12rsas/taransvar.git', 'https://github.com/oyst12rsas/taransvar', 'git@github.com:oyst12rsas/taransvar.git'):
        raise ValueError('Daily source must be the assigned TaraSec repository')
    prepare_rollout_web(source)
    atomic(POLICY, policy)
    state['roles']=roles
    state['managed_policy']={'enabled':True,'schedule':'03:00–03:10 Europe/Oslo','roles':roles}
    u.save(state)
    INSTALL.mkdir(parents=True, exist_ok=True)
    for path in (source/'misc').glob('*.py'):
        shutil.copyfile(path, INSTALL/path.name); (INSTALL/path.name).chmod(0o644)
    # Stop the older notice-driven path from bypassing the staged daily controller.
    adapter = Path('/usr/local/lib/tarasec-operations/operations_upgrade.py')
    if adapter.parent.is_dir(): shutil.copyfile(source/'misc/operations_upgrade.py', adapter)
    service='''[Unit]\nDescription=TaraSec staged daily desired-state upgrade\nAfter=network-online.target\nWants=network-online.target\n[Service]\nType=oneshot\nUMask=0077\nExecStart=/usr/bin/python3 /usr/local/lib/tarasec-upgrade/managed_upgrade.py daily\nTimeoutStartSec=35min\n'''
    timer='''[Unit]\nDescription=Daily TaraSec release rollout at 03:00 Europe/Oslo\n[Timer]\nOnCalendar=*-*-* 03:00:00 Europe/Oslo\nRandomizedDelaySec=10min\nPersistent=true\n[Install]\nWantedBy=timers.target\n'''
    Path('/etc/systemd/system/tarasec-managed-upgrade.service').write_text(service)
    Path('/etc/systemd/system/tarasec-managed-upgrade.timer').write_text(timer)
    Path('/etc/systemd/system/tarasec-managed-health.service').write_text('[Unit]\nDescription=Verify the deployed TaraSec release\n[Service]\nType=oneshot\nUMask=0077\nExecStart=/usr/bin/python3 /usr/local/lib/tarasec-upgrade/managed_upgrade.py check\nTimeoutStartSec=60\n')
    Path('/etc/systemd/system/tarasec-managed-health.timer').write_text('[Unit]\nDescription=Observe staged TaraSec release health every minute\n[Timer]\nOnBootSec=2min\nOnUnitInactiveSec=60s\n[Install]\nWantedBy=timers.target\n')
    run(['systemctl', 'daemon-reload']); run(['systemctl','enable','--now','tarasec-managed-upgrade.timer','tarasec-managed-health.timer'])
    print('Daily maintenance enabled: 03:00–03:10 Europe/Oslo. Roles retained. Central rollout policy selects test units and holds fleet promotion.')


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=('daily','apply','check'))
    parser.add_argument('--revision'); parser.add_argument('--generation', type=int, default=0)
    parser.add_argument('--cohort',choices=('canary','fleet'),default='fleet')
    args=parser.parse_args(argv)
    if os.geteuid()!=0: raise ValueError('Run as root')
    policy=private_json(POLICY)
    if policy.get('enabled') is not True: return 0
    if args.command=='check': return check_health(policy)
    if args.command=='apply':
        if not args.revision or not SHA.fullmatch(args.revision): raise ValueError('Invalid pinned revision')
        answer=converge(policy,args.revision,args.generation,Path(__file__).resolve().parent.parent,args.cohort)
        print(json.dumps(answer)); return 1 if answer['status']=='failed' else 0
    STATE.parent.mkdir(parents=True, exist_ok=True)
    with (STATE.parent/'daily.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        try: return daily(policy)
        except (OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
            # No credential-bearing command output is published.
            scheduler_notice('blocked',type(error).__name__+'; local setup or central evidence unavailable')
            print('Daily maintenance blocked: '+type(error).__name__)
            return 1

if __name__=='__main__': raise SystemExit(main())
