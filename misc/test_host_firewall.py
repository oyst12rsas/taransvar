import os
from pathlib import Path
import subprocess
import tempfile
import unittest

class HostFirewallTests(unittest.TestCase):
    def test_host_preserves_other_chains_and_tables(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            script = root / 'firewall.sh'
            source = Path(__file__).with_name('firewall.sh').read_text()
            script.write_text(source.replace('/etc/rsyslog.d/33-tarasec-host.conf', str(root / 'forward.conf')))
            calls = root / 'calls'
            mock = '''#!/bin/bash
printf '%s %s\\n' "${0##*/}" "$*" >> "$CALLS"
case " $* " in *' -C '*|*' -D '*) exit 1;; esac
exit 0
'''
            for family in ('iptables', 'ip6tables'):
                path = root / family
                path.write_text(mock)
                path.chmod(0o755)
            config = root / 'conf'
            config.write_text('FIREWALL_MODE=host\nIS_GATEWAY=0\nNODE_NAME=vm-server\nSSH_PORT=4100\nSSH_HONEYPOT=on\nSSH_HONEYPOT_PORTS="22,4200-4202"\nHOST_RSYSLOG_FORWARD=off\n')
            env = dict(os.environ, PATH=str(root)+':'+os.environ['PATH'], CALLS=str(calls))
            subprocess.run(['bash',str(script),str(config)], env=env,check=True,capture_output=True)
            lines = calls.read_text().splitlines()
            self.assertEqual(sum(' -I INPUT 1 -j TARASEC_HOST_LOG' in line for line in lines), 2)
            self.assertEqual(sum('--dport 4200:4202' in line for line in lines), 2)
            for line in lines:
                self.assertNotIn(' -t ', line)
                self.assertNotIn('FORWARD', line)
                self.assertNotIn('LIBVIRT', line)
                self.assertNotIn(' -P ', line)
                if ' -F ' in line or ' -N ' in line:
                    self.assertTrue(line.endswith('TARASEC_HOST_LOG'))
            calls.unlink()
            config.write_text(config.read_text().replace('4200-4202','4099-4101'))
            result = subprocess.run(['bash',str(script),str(config)],env=env,capture_output=True)
            self.assertNotEqual(result.returncode,0)
            self.assertFalse(calls.exists(), 'Admin/honeypot collision must fail before mutations')

if __name__ == '__main__':
    unittest.main()
