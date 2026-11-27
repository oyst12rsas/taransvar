"""Sensor telemetry reaches the DB server over TCP/5514 only.

Checks that no sensor component sends UDP/514, and that the lines the
sinkhole monitor writes are turned into syslogThreat events by the DB
server's remote_syslog_normalizer.pl parser.
"""
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
FORWARD = ROOT / 'misc/rsyslog/30-tarasec-forward.conf.example'
SINKHOLE = ROOT / 'misc/log_iptables_drops_to_dbservers.pl'
COWRIE = ROOT / 'tool/cowrie_send_log.pl'
NORMALIZER = ROOT / 'misc/remote_syslog_normalizer.pl'


def code_only(path):
    return '\n'.join(l for l in path.read_text().splitlines() if not l.lstrip().startswith('#'))


def parse_with_normalizer(program, messages):
    """Run the normalizer's own parse_event() on each message."""
    src = NORMALIZER.read_text()
    subs = src[src.index('sub ip_ok'):src.index('sub queue_ai')]
    script = ('use strict; use warnings; use JSON qw(decode_json encode_json);\n' + subs +
              'while (my $m = <STDIN>) { chomp $m; print encode_json(parse_event($ARGV[0], $m)), "\\n"; }')
    out = subprocess.run(['perl', '-e', script, program], input='\n'.join(messages) + '\n',
                         capture_output=True, text=True, check=True).stdout
    return [json.loads(l) for l in out.splitlines()]


class ForwarderTests(unittest.TestCase):
    def test_forwarder_uses_tcp_5514_only(self):
        conf = code_only(FORWARD)
        self.assertEqual(re.findall(r'protocol="(\w+)"', conf), ['tcp'])
        self.assertEqual(re.findall(r'port="(\d+)"', conf), ['5514'])

    def test_forwarder_matches_sensor_programs(self):
        conf = code_only(FORWARD)
        for needle in ('"TARASEC_"', '"cowrie"', '"tarasec-ssh-honeypot"'):
            self.assertIn(needle, conf)

    def test_sensor_scripts_send_no_udp(self):
        for path in (SINKHOLE, COWRIE):
            code = code_only(path)
            self.assertNotIn('IO::Socket', code, path)
            self.assertNotRegex(code, r'\b514\b', path)
            self.assertIn('Sys::Syslog', code, path)

    def test_upgrade_refreshes_forwarder_when_template_changes(self):
        # setup_ssh_honeypot.sh --refresh-only re-renders the forwarder, so a
        # template change must mark the honeypot component as changed.
        import sys
        sys.path.insert(0, str(ROOT / 'misc'))
        import managed_upgrade
        paths = managed_upgrade.PATHS['honeypot']
        self.assertIn('misc/rsyslog/30-tarasec-forward.conf.example', paths)
        self.assertIn('misc/setup_backoffice_ai.sh', paths)

    def test_cowrie_ident_matches_forwarder(self):
        self.assertRegex(COWRIE.read_text(), r'\$SYSLOG_IDENT\s*=\s*"cowrie"')


@unittest.skipUnless(shutil.which('perl') and
                     subprocess.run(['perl', '-MJSON', '-e1'], capture_output=True).returncode == 0,
                     'perl with JSON module required')
class SinkholeToNormalizerTests(unittest.TestCase):
    def sinkhole(self, kernel_lines):
        return subprocess.run(['perl', str(SINKHOLE), '--stdin'], input='\n'.join(kernel_lines) + '\n',
                              capture_output=True, text=True, check=True).stdout.splitlines()

    def test_drop_line_becomes_iptables_attack(self):
        lines = self.sinkhole([
            'kernel: DROP_INPUT: IN=eth0 OUT= MAC=aa:bb SRC=203.0.113.9 DST=192.0.2.10 '
            'LEN=60 PROTO=TCP SPT=4444 DPT=23 SYN',
        ])
        self.assertEqual(len(lines), 1)
        self.assertTrue(lines[0].startswith('TARASEC_SINKHOLE DROP_INPUT '))
        (event,) = parse_with_normalizer('tarasec-sinkhole', lines)
        self.assertEqual(event['src_ip'], '203.0.113.9')
        self.assertEqual(event['dst_ip'], '192.0.2.10')
        self.assertEqual(event['dst_port'], '23')
        self.assertEqual(event['service'], 'iptables')
        self.assertEqual(event['is_attack'], 1)

    def test_other_kernel_lines_are_ignored(self):
        self.assertEqual(self.sinkhole([
            'kernel: usb 1-1: new device',
            'kernel: ACCEPT_INPUT: SRC=203.0.113.9 DST=192.0.2.10',
            'kernel: DROP_INPUT: SRC=fe80::1 DST=fe80::2 PROTO=TCP',
        ]), [])

    def test_control_characters_are_stripped(self):
        (line,) = self.sinkhole(['DROP_FORWARD: IN=eth0\x1b[31m SRC=203.0.113.9 DST=192.0.2.10 PROTO=UDP'])
        self.assertNotIn('\x1b', line)

    def test_cowrie_json_is_parsed(self):
        msg = json.dumps({'src_ip': '203.0.113.9', 'dst_ip': '192.0.2.10', 'src_port': 5000,
                          'dst_port': 22, 'proto': 'tcp', 'event': 'cowrie.login.failed',
                          'sensor': 'cowrie', 'severity': 7})
        (event,) = parse_with_normalizer('cowrie', [msg])
        self.assertEqual(event['service'], 'cowrie')
        self.assertEqual(event['severity'], 7)
        self.assertEqual(event['is_attack'], 1)


if __name__ == '__main__':
    unittest.main()
