"""Run installer flows with an isolated filesystem and simulated OS commands."""
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

REPO = Path(__file__).resolve().parents[1]


class HoneypotTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'; self.bin.mkdir()
        self.log = self.root / 'commands'
        for directory in ('etc/systemd/system', 'etc/ssh/sshd_config.d', 'var/lib/tarasec-ssh-honeypot'):
            (self.root / directory).mkdir(parents=True, exist_ok=True)
        (self.root / 'etc/tarasecfw.conf').write_text('SSH_PORT=5822\nSSH_HONEYPOT_PORT=22\nSSH_FAILSAFE=on\nSSH_ALLOWED_SOURCES=100.64.0.0/10\n')
        (self.root / 'var/lib/tarasec-ssh-honeypot/ssh_host_ed25519_key').write_text('test-only')
        source = (REPO / 'misc/setup_ssh_honeypot.sh').read_text()
        for prefix in ('/etc/', '/var/lib/', '/usr/local/', '/run/'):
            source = source.replace(prefix, str(self.root) + prefix)
        self.script = self.root / 'setup.sh'; self.script.write_text(source)
        self.env = dict(os.environ, PATH=str(self.bin)+':'+os.environ['PATH'],
                        TEST_ROOT=str(self.root), TEST_LOG=str(self.log),
                        TARASEC_REPO_DIR=str(REPO), TEST_PORTS='5822', TEST_LISTENER='yes', TEST_GATE='no', TEST_ARMED='no')
        self.command('id', 'echo 0')
        self.command('sshd', 'if [[ $1 == -T ]]; then for port in $TEST_PORTS; do echo "port $port"; done; fi')
        self.command('ss', '''if [[ $TEST_LISTENER == delayed ]]; then
n=0; [[ ! -e $TEST_ROOT/polls ]] || n=$(cat "$TEST_ROOT/polls"); n=$((n+1)); echo "$n" > "$TEST_ROOT/polls"
[[ $n -gt 2 ]] || exit 0
elif [[ $TEST_LISTENER == no ]]; then exit 0; fi
if [[ $TEST_LISTENER == many ]]; then
for ((i=0;i<10000;i++)); do echo 'LISTEN 0 128 0.0.0.0:5822 0.0.0.0:*'; done
else echo 'LISTEN 0 128 0.0.0.0:5822 0.0.0.0:*'; fi''')
        self.command('systemctl', '''echo "systemctl $*" >> "$TEST_LOG"
if [[ $1 == is-active ]]; then
if [[ $* == *tarasec-ssh-rollback.timer* ]]; then [[ $TEST_ARMED == yes ]]; else exit 0; fi
elif [[ $1 == is-enabled ]]; then exit 1
fi''')
        for tool in ('iptables', 'ip6tables'):
            self.command(tool, 'echo "'+tool+' $*" >> "$TEST_LOG"\nif [[ $1 == -S ]]; then [[ $TEST_GATE == yes ]]; fi')
        self.command('iptables-save', 'echo baseline')
        self.command('ip6tables-save', 'echo baseline')
        self.command('apt-get', 'exit 88')
        self.command('python3', 'exit 0')
        self.command('ip', 'exit 0')
        self.command('sleep', 'exit 0')
        install = '''import os,sys,pathlib,shutil
args=sys.argv[1:]; directory=False; mode=0o755; paths=[]; i=0
while i<len(args):
 a=args[i]
 if a in ('-o','-g'): i+=2; continue
 if a=='-m': mode=int(args[i+1],8); i+=2; continue
 if a=='-d': directory=True; i+=1; continue
 paths.append(pathlib.Path(a)); i+=1
if directory:
 for p in paths:
  assert str(p).startswith(os.environ['TEST_ROOT']); p.mkdir(parents=True,exist_ok=True); p.chmod(mode)
else:
 dst=paths[-1]; assert str(dst).startswith(os.environ['TEST_ROOT'])
 for src in paths[:-1]:
  target=dst/src.name if dst.is_dir() else dst
  shutil.copyfile(src,target); target.chmod(mode)
'''
        path = self.bin / 'install'; path.write_text('#!'+sys.executable+'\n'+install); path.chmod(0o755)

    def tearDown(self): self.temp.cleanup()

    def command(self, name, body):
        p = self.bin/name; p.write_text('#!/bin/bash\n'+body+'\n'); p.chmod(0o755)

    def execute(self, *args):
        return subprocess.run(['bash',str(self.script),*args],env=self.env,text=True,capture_output=True,timeout=20)

    def commands(self): return self.log.read_text() if self.log.exists() else ''

    def test_existing_setup_refreshes_without_touching_admin_access(self):
        self.env['TEST_GATE']='yes'
        r=self.execute('--refresh-only')
        self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        calls=self.commands()
        self.assertIn('restart tarasec-ssh-honeypot.service',calls)
        self.assertNotIn('iptables -I',calls)
        self.assertNotIn('stop tarasec-ssh-honeypot.service',calls)
        self.assertNotIn('reload ssh',calls)
        self.assertFalse((self.root/'var/lib/tarasec/ssh-rollback').exists())
        self.assertFalse((self.root/'etc/ssh/sshd_config.d/90-tarasec.conf').exists())

    def test_refresh_only_never_falls_back_to_migration(self):
        self.env['TEST_LISTENER']='no'
        r=self.execute('--refresh-only')
        self.assertNotEqual(r.returncode,0)
        self.assertIn('No SSH/firewall changes made',r.stderr)
        self.assertNotIn('iptables -I',self.commands())

    def test_armed_snapshot_is_preserved(self):
        snapshot=self.root/'var/lib/tarasec/ssh-rollback/iptables.previous'
        snapshot.parent.mkdir(parents=True); snapshot.write_text('known-good')
        self.env['TEST_ARMED']='yes'
        r=self.execute()
        self.assertNotEqual(r.returncode,0)
        self.assertIn('snapshot is preserved',r.stderr)
        self.assertEqual(snapshot.read_text(),'known-good')
        self.assertNotIn('iptables -I',self.commands())

    def test_new_migration_refuses_an_existing_google_gate(self):
        self.env.update(TEST_PORTS='22',TEST_LISTENER='no',TEST_GATE='yes')
        r=self.execute()
        self.assertNotEqual(r.returncode,0)
        self.assertIn('bypass',r.stderr)
        self.assertNotIn('iptables -I',self.commands())

    def test_large_listener_output_does_not_false_fail_under_pipefail(self):
        self.env['TEST_LISTENER']='many'
        r=self.execute('--refresh-only')
        self.assertEqual(r.returncode,0,r.stdout+r.stderr)

    def test_startup_delay_is_polled(self):
        # Keep the initial admin listener present, then simulate delayed HP binding.
        self.command('ss', '''if [[ $* == *5822* ]]; then echo LISTEN; exit 0; fi
n=0; [[ ! -e $TEST_ROOT/polls ]] || n=$(cat "$TEST_ROOT/polls"); n=$((n+1)); echo "$n" > "$TEST_ROOT/polls"
[[ $n -gt 2 ]] && echo LISTEN
exit 0''')
        r=self.execute('--refresh-only')
        self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        self.assertGreaterEqual(int((self.root/'polls').read_text()),3)

    def test_real_migration_waits_for_listener_and_arms_rollback_first(self):
        self.command('sshd', '\n'.join([
            'if [[ $1 == -T ]]; then',
            'if [[ -f $TEST_ROOT/etc/ssh/sshd_config.d/90-tarasec.conf ]]; then',
            'awk \'$1 == "Port" { print "port " $2 }\' "$TEST_ROOT/etc/ssh/sshd_config.d/90-tarasec.conf"',
            'else echo "port 22"; fi', 'fi']))
        self.command('ss', '''if [[ $* == *5822* ]]; then
[[ -f $TEST_ROOT/etc/ssh/sshd_config.d/90-tarasec.conf ]] || exit 0
n=0; [[ ! -e $TEST_ROOT/polls ]] || n=$(cat "$TEST_ROOT/polls"); n=$((n+1)); echo "$n" > "$TEST_ROOT/polls"
[[ $n -gt 2 ]] && echo LISTEN
else echo LISTEN; fi
exit 0''')
        r=self.execute()
        self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        calls=self.commands()
        self.assertLess(calls.index('start tarasec-ssh-rollback.timer'),calls.index('iptables -I'))
        self.assertLess(calls.index('start tarasec-ssh-rollback.timer'),calls.index('stop tarasec-ssh-honeypot.service'))
        self.assertEqual((self.root/'etc/ssh/sshd_config.d/90-tarasec.conf').read_text().count('Port '),1)
        self.assertGreaterEqual(int((self.root/'polls').read_text()),3)


if __name__=='__main__': unittest.main()
