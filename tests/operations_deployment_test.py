import json
from pathlib import Path
import sys
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
from operations_deployment import deployment_inventory

class DeploymentTests(unittest.TestCase):
    def inspect(self, root, cron, units=None):
        def run(argv, timeout):
            if argv[0].endswith('crontab'):
                return {'exit_code': 0, 'output': cron}
            return {'exit_code': 0, 'output': (units or {}).get(argv[2], 'LoadState=not-found\nActiveState=inactive\n')}
        return deployment_inventory(run, Path(root), now=1000)

    def test_audi_layout_detected_without_secrets_or_cron_output(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            reporter = root / 'root/taransvar/perl/crontasks.pl'
            reporter.parent.mkdir(parents=True)
            reporter.write_text('old reporter')
            token = root / 'etc/tarasec/agent-node.token'
            token.parent.mkdir(parents=True)
            token.write_text('SECRET_TOKEN')
            result = self.inspect(root, '* * * * * /usr/bin/perl /root/taransvar/perl/crontasks.pl cron --secret=CRON_SECRET\n')
            self.assertEqual(result['reporters'][0]['path'], '/root/taransvar/perl/crontasks.pl')
            self.assertIn('minute_reporter_missing_security_status_field', result['findings'])
            self.assertIn('security_worker_missing', result['findings'])
            self.assertTrue(result['components']['security_enrollment']['nonempty_regular_file'])
            self.assertNotIn('SECRET', json.dumps(result))

    def test_comments_and_symlink_reporters_are_not_claimed_inspected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / 'root/taransvar/perl/crontasks.pl'
            path.parent.mkdir(parents=True)
            target = root / 'secret'
            target.write_text('SECRET')
            path.symlink_to(target)
            result = self.inspect(root, '# /home/audi/taransvar/misc/crontasks.pl\n* * * * * perl /root/taransvar/perl/crontasks.pl cron')
            self.assertEqual(len(result['reporters']), 1)
            self.assertFalse(result['reporters'][0]['regular_file'])
            self.assertNotIn('security_status_field', result['reporters'][0])

    def test_empty_or_symlink_token_does_not_count_as_enrollment(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            token = root / 'etc/tarasec/agent-node.token'
            token.parent.mkdir(parents=True)
            token.write_text('')
            self.assertIn('security_enrollment_missing_or_unverified', self.inspect(root, '')['findings'])
            token.unlink()
            token.symlink_to('/does/not/exist')
            self.assertFalse(self.inspect(root, '')['components']['security_enrollment']['nonempty_regular_file'])

    def test_current_fields_and_active_timer_are_not_missing(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / 'home/audi/taransvar/misc/crontasks.pl'
            path.parent.mkdir(parents=True)
            path.write_text('$json{"aiAgent"} = $security; $json{"operationsAgent"} = $operations;')
            units = {'tarasec-operations-agent.timer': 'LoadState=loaded\nActiveState=active\nUnitFileState=enabled\n'}
            result = self.inspect(root, '* * * * * perl /home/audi/taransvar/misc/crontasks.pl cron', units)
            self.assertNotIn('minute_reporter_missing_security_status_field', result['findings'])
            self.assertNotIn('tarasec-operations-agent.timer_not_active', result['findings'])
            self.assertIsNone(result['reporters'][0]['matches_deployed_source_reference'])

    def test_cron_failure_is_unknown_not_missing(self):
        with tempfile.TemporaryDirectory() as directory:
            result = deployment_inventory(lambda *args: {'exit_code': 1, 'output': 'PRIVATE ERROR'}, Path(directory))
            self.assertIn('minute_reporter_inventory_unknown', result['findings'])
            self.assertNotIn('minute_reporter_not_found_in_root_cron', result['findings'])
            self.assertNotIn('PRIVATE ERROR', json.dumps(result))
