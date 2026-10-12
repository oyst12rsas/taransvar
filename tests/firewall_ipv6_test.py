"""Generate the real IPv6 policy with mocked firewall/WireGuard commands.

These tests never change host firewall rules. The packet checks interpret the
generated allow/drop list; live Netfilter enforcement remains a deployment check.
"""
import ipaddress
import json
import os
from pathlib import Path
import shlex
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class IPv6FirewallTests(unittest.TestCase):
    def generate(self, **settings):
        with tempfile.TemporaryDirectory() as tmp:
            folder = Path(tmp)
            command = folder / 'mock'
            command.write_text('''#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
name=Path(sys.argv[0]).name
if name=='wg':
    print(os.environ.get('WG_PORTS','wt0\\t56300\\nwg0\\t51820'))
elif name=='ip6tables':
    print(os.environ['INPUT_RULES'])
elif name=='ip6tables-restore':
    rules=sys.stdin.read()
    with open(os.environ['CAPTURE'],'a') as out:
        out.write(json.dumps({'args':sys.argv[1:],'rules':rules})+'\\n')
    if '--test' in sys.argv and os.environ.get('FAIL_TEST')=='1': sys.exit(1)
''')
            command.chmod(0o755)
            for name in ['wg', 'ip6tables', 'ip6tables-restore']:
                (folder / name).symlink_to(command)
            env = os.environ | dict(PATH=str(folder)+':'+os.environ['PATH'],
                CAPTURE=str(folder/'capture'), WAN_INTERFACE='wt0', SSH_PORT='5822',
                ALLOW_WEB='1', ALLOW_PING='0', TCP_PORTS='514', UDP_PORTS='5551,5552',
                SSH_ALLOWED_SOURCES='100.68.10.7', SSH_ALLOWED_IPV6_SOURCES='',
                IPV6_TRANSPORT_UDP_PORTS='',
                INPUT_RULES='-P INPUT ACCEPT\n-A INPUT -p tcp --dport 5822 -j TARASEC_SSH_SOURCE\n-A INPUT -i wt0 -j ACCEPT')
            env.update(settings)
            script = '''set -e
valid_port() { [[ "$1" =~ ^[0-9]+$ ]] && [ "$1" -ge 1 ] && [ "$1" -le 65535 ]; }
source "$1"
tarasec_ipv6_prepare
trap 'rm -f "${TARASEC_IPV6_RULE_FILE:-}"' EXIT
tarasec_ipv6_apply
'''
            result = subprocess.run(['bash','-c',script,'test',str(ROOT/'misc/firewall_ipv6.sh')],
                env=env, capture_output=True, text=True)
            captured = [json.loads(line) for line in (folder/'capture').read_text().splitlines()] if (folder/'capture').exists() else []
            return result, captured

    def accepts(self, rules, *, interface='wt0', proto='tcp', port=0,
                source='fdd1::2', state='NEW', icmp_type=128, sport=0):
        for line in rules.splitlines():
            fields = shlex.split(line)
            if fields[:2] != ['-A','TARASEC_IPV6_INPUT']:
                continue
            def option(name):
                return fields[fields.index(name)+1] if name in fields else None
            if option('-i') and option('-i') != interface: continue
            if option('-p') and option('-p') != proto: continue
            if option('--dport') and int(option('--dport')) != port: continue
            if option('--sport') and int(option('--sport')) != sport: continue
            if option('-s') and ipaddress.ip_address(source) not in ipaddress.ip_network(option('-s'), strict=False): continue
            if option('--ctstate') and state not in option('--ctstate').split(','): continue
            if option('--icmpv6-type') and int(option('--icmpv6-type')) != icmp_type: continue
            if option('-j') == 'ACCEPT': return True
            if option('-j') == 'DROP': return False
        return False

    def policy(self, **settings):
        result, captured = self.generate(**settings)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual([c['args'] for c in captured], [['--test','--noflush'],['--noflush']])
        self.assertEqual(captured[0]['rules'],captured[1]['rules'])
        return captured[1]['rules']

    def test_configured_services_and_unconfigured_ports(self):
        rules = self.policy()
        for port in [80,443,514]: self.assertTrue(self.accepts(rules,port=port))
        for port in [22,3306,9090,5822]: self.assertFalse(self.accepts(rules,port=port))
        for port in [5551,5552]: self.assertTrue(self.accepts(rules,proto='udp',port=port))
        self.assertFalse(self.accepts(rules,proto='udp',port=9999))
        self.assertFalse(self.accepts(rules,interface='enp1s0',port=80))

    def test_transport_is_explicit_and_not_all_udp(self):
        rules = self.policy(IPV6_TRANSPORT_UDP_PORTS='45000')
        for port in [51820,56300,45000]:
            self.assertTrue(self.accepts(rules,interface='enp1s0',proto='udp',port=port))
        self.assertFalse(self.accepts(rules,interface='enp1s0',proto='udp',port=45001))

    def test_loopback_and_existing_sessions(self):
        rules = self.policy()
        self.assertTrue(self.accepts(rules,interface='lo',port=3306))
        for state in ['ESTABLISHED','RELATED']:
            self.assertTrue(self.accepts(rules,port=5822,state=state))

    def test_ipv6_control_traffic_without_echo_request(self):
        rules = self.policy()
        for icmp_type in [1,2,3,4,129,133,134,135,136]:
            self.assertTrue(self.accepts(rules,proto='ipv6-icmp',icmp_type=icmp_type))
        self.assertFalse(self.accepts(rules,proto='ipv6-icmp',icmp_type=128))
        self.assertTrue(self.accepts(self.policy(ALLOW_PING='1'),proto='ipv6-icmp',icmp_type=128))
        self.assertTrue(self.accepts(rules,source='fe80::1',proto='udp',sport=547,port=546))
        self.assertFalse(self.accepts(rules,source='fdd1::1',proto='udp',sport=547,port=546))

    def test_ipv4_ssh_permission_does_not_open_ipv6(self):
        rules = self.policy(TCP_PORTS='514,5822')
        self.assertFalse(self.accepts(rules,port=5822))
        rules = self.policy(SSH_ALLOWED_IPV6_SOURCES='fdd1::7/128')
        self.assertTrue(self.accepts(rules,source='fdd1::7',port=5822))
        self.assertFalse(self.accepts(rules,source='fdd1::8',port=5822))
        self.assertFalse(self.accepts(self.policy(ALLOW_SSH='0',SSH_ALLOWED_IPV6_SOURCES='fdd1::7'),source='fdd1::7',port=5822))

    def test_only_blanket_rule_and_owned_hook_are_removed(self):
        snapshot='-P INPUT ACCEPT\n-A INPUT -p tcp --dport 5822 -j TARASEC_SSH_SOURCE\n-A INPUT -i wt0 -j ACCEPT\n-A INPUT -i wt0 -j ACCEPT\n-A INPUT -j TARASEC_IPV6_INPUT'
        rules=self.policy(INPUT_RULES=snapshot)
        self.assertEqual(rules.count('-D INPUT -i wt0 -j ACCEPT'),2)
        self.assertEqual(rules.count('-D INPUT -j TARASEC_IPV6_INPUT'),1)
        self.assertEqual(rules.count('-A INPUT -j TARASEC_IPV6_INPUT'),1)
        self.assertNotIn('-D INPUT -p tcp',rules)
        self.assertNotIn('FORWARD',rules)
        self.assertNotIn('*nat',rules)
        self.assertIn(':INPUT DROP [0:0]',rules)

    def test_invalid_config_and_failed_validation_never_apply(self):
        for values in [dict(TCP_PORTS='99999'),dict(UDP_PORTS='bad'),
                dict(SSH_ALLOWED_IPV6_SOURCES='100.68.10.7'),dict(WAN_INTERFACE='wt0 injected')]:
            result,captured=self.generate(**values)
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(captured,[])
        result,captured=self.generate(FAIL_TEST='1')
        self.assertNotEqual(result.returncode,0)
        self.assertEqual(len(captured),1)
        self.assertIn('--test',captured[0]['args'])

    def test_gateway_integration_preflights_before_ipv4_changes(self):
        script=(ROOT/'misc/firewall.sh').read_text()
        self.assertLess(script.index('tarasec_ipv6_prepare'),script.index('iptables -F'))
        self.assertLess(script.index('tarasec_ipv6_apply'),script.index('netfilter-persistent save'))
        self.assertLess(script.index('Node firewall logging installed'),script.index('source "$(dirname'))
        self.assertLess(script.index('if [[ "$IPV6_ONLY" == 1 ]]'),script.index('iptables -F'))
        isolated=script.split('if [[ "$IPV6_ONLY" == 1 ]]',1)[1].split('fi',1)[0]
        self.assertIn('tarasec_ipv6_apply',isolated)
        self.assertIn('exit 0',isolated)
        self.assertNotIn('netfilter-persistent',isolated)


if __name__ == '__main__': unittest.main()
