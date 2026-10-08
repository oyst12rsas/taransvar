import json
import sys
import tempfile
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
from operations_agent import save
from operations_prompt import completed_check_feedback
from operations_diagnostics import diagnose

class ProgressTests(unittest.TestCase):
    def test_history_survives_new_result_without_duplicate_saves(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'state.json'
            state = {'boot_id': 'boot', 'last_action_result': {'argv': ['first'], 'exit_code': 0}}
            save(path, state)
            save(path, state)
            state['last_action_result'] = {'argv': ['second'], 'exit_code': 1}
            save(path, state)
            self.assertEqual(len(json.loads(path.read_text())['action_history']), 2)
            self.assertEqual(state['action_history'][0]['argv'], ['first'])

    def test_completed_check_guard_requires_same_boot_and_identity(self):
        argv = ['/usr/bin/bash', '-n', '/script']
        identity = {'inode': 1}
        results = {'gateway_startup': {'result': {'properties': {'ActiveState': 'active',
            'executable': {'path': '/script', 'file_identity': identity}}}}}
        snapshot = {'boot_id': 'a', 'action_history': [{'boot_id': 'a', 'argv': argv,
            'read_only': True, 'exit_code': 0, 'startup_file_identity': identity}]}
        decision = {'action': 'command', 'argv': argv}
        self.assertTrue(completed_check_feedback(decision, results, snapshot))
        snapshot['boot_id'] = 'b'
        self.assertIsNone(completed_check_feedback(decision, results, snapshot))
        snapshot['boot_id'] = 'a'
        results['gateway_startup']['result']['properties']['executable']['file_identity'] = {'inode': 2}
        self.assertIsNone(completed_check_feedback(decision, results, snapshot))

    def test_logging_diagnostic_never_reads_agent_credentials(self):
        calls = []
        diagnose('logging_policy', lambda argv, timeout: calls.append(argv) or {'exit_code': 0, 'output': ''})
        self.assertEqual(len(calls), 4)
        self.assertFalse(any('/etc/tarasec' in argument for argv in calls for argument in argv))

    def test_journal_tool_requires_demo_policy_task_and_missing_limit(self):
        from operations_tools import gateway_tools
        from operations_prompt import progress_feedback
        policy = dict(mode='demo', execute=True, allow_experimental_commands=True, task='Inspect log retention')
        result = {'logging_policy': {'result': {'journald_effective': {'exit_code': 0, 'output': '#SystemMaxUse=\n'}}}}
        self.assertIn('journal_configure_limit', gateway_tools(policy, result, {}))
        self.assertTrue(progress_feedback({'action': 'report', 'task_state': {}}, policy, result, {}))
        policy['mode'] = 'production'
        self.assertNotIn('journal_configure_limit', gateway_tools(policy, result, {}))
        policy['mode'] = 'demo'
        result['logging_policy']['result']['journald_effective']['output'] = 'SystemMaxUse=2G'
        self.assertNotIn('journal_configure_limit', gateway_tools(policy, result, {}))

    def test_journal_dropin_preserves_owner_config_and_is_idempotent(self):
        from configure_operations_journal import install_limit
        with tempfile.TemporaryDirectory() as directory:
            self.assertTrue(install_limit(directory))
            self.assertFalse(install_limit(directory))
            (Path(directory) / '60-tarasec-operations.conf').write_text('owner setting')
            with self.assertRaises(ValueError):
                install_limit(directory)
