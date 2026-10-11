import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
from operations_reporting import local_report, stage_error
from operations_agent import save
from install_operations_reporter_bridge import patched_source
from operations_tools import gateway_tools

class ReportingTests(unittest.TestCase):
    def test_timeout_report_preserves_findings_without_model_text(self):
        state = {'status': 'blocked', 'checked_at': 1, 'error_stage': 'model_request',
                 'summary': 'SECRET MODEL TEXT',
                 'deployment_findings': ['security_worker_missing']}
        report = local_report(state, 2)
        self.assertEqual(report['assessment_checked_at'], 1)
        self.assertIn('not installed', report['deployment_summary'][0])
        self.assertNotIn('SECRET', json.dumps(report))
        self.assertIn('flowise', stage_error(TimeoutError('SECRET'), 'model_request', 'flowise'))
        self.assertNotIn('SECRET', stage_error(TimeoutError('SECRET'), 'model_request', 'flowise'))

    def test_state_save_always_publishes_compact_report(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'state.json'
            save(path, {'status': 'blocked', 'checked_at': 1, 'deployment_findings': ['security_config_missing']})
            report = json.loads(path.with_name('report.json').read_text())
            self.assertEqual(report['status'], 'blocked')
            self.assertFalse(report['central_report_verified'])
            self.assertLess(path.with_name('report.json').stat().st_size, 16384)

    def test_bridge_preserves_shebang_and_existing_security_field(self):
        source = '#!/usr/bin/perl\nsub reportStatus {\nmy %json;\n$json{"aiAgent"}=1;\n}\n'
        patched = patched_source(source)
        self.assertTrue(patched.startswith('#!/usr/bin/perl'))
        self.assertEqual(patched.count('$json{"aiAgent"}'), 1)
        self.assertEqual(patched.count('$json{"operationsAgent"}'), 1)
        self.assertEqual(patched_source(patched), patched)
        with tempfile.NamedTemporaryFile(mode='w') as target:
            target.write(patched)
            target.flush()
            result = subprocess.run(['/usr/bin/perl', '-c', target.name], capture_output=True)
            self.assertEqual(result.returncode, 0, result.stderr)

    def test_bridge_rejects_unsupported_layout(self):
        with self.assertRaises(ValueError):
            patched_source('different program')

    def test_bridge_tool_never_exposed_in_inspection_or_production(self):
        results = {'deployment_status': {'result': {'reporters': [{
            'regular_file': True, 'operations_status_field': False, 'path': '/root/taransvar/perl/crontasks.pl',
            'sha256': 'a' * 64}]}}}
        policy = dict(mode='demo', execute=True, allow_experimental_commands=True)
        self.assertIn('minute_reporter_bridge', gateway_tools(policy, results, {}))
        policy['execute'] = False
        self.assertNotIn('minute_reporter_bridge', gateway_tools(policy, results, {}))
        policy['execute'] = True
        policy['mode'] = 'production'
        self.assertNotIn('minute_reporter_bridge', gateway_tools(policy, results, {}))
