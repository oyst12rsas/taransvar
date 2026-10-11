import sys
from pathlib import Path
import unittest
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
from operations_policy import maintenance_enabled, experimental_enabled, effective_policy
from operations_prompt import build_prompt


class PolicyTests(unittest.TestCase):
    def test_modes_have_one_unambiguous_authority(self):
        for mode, maintenance, experiment in [('demo', True, True), ('production', True, False),
                ('inspect', False, False), ('invalid', False, False), (None, False, False)]:
            policy = dict(mode=mode, execute=True, allow_reboot=True, allow_experimental_commands=True)
            self.assertEqual(maintenance_enabled(policy), maintenance)
            self.assertEqual(experimental_enabled(policy), experiment)

    def test_legacy_flags_are_removed_without_mutating_owner_configuration(self):
        source = dict(mode='demo', execute=False, allow_reboot=False, allow_experimental_commands=False,
                      resource_protection=dict(enabled=False, disposable_logs=['/var/log/disposable.log']))
        clean = effective_policy(source)
        self.assertEqual(clean, dict(mode='demo', resource_protection=dict(disposable_logs=['/var/log/disposable.log'])))
        self.assertFalse(source['execute'])
        self.assertIn('enabled', source['resource_protection'])
        prompt = build_prompt('', source, {}, {}, {}, {}, {})
        self.assertIn('Experimental command action is ENABLED', prompt)
        self.assertNotIn('"execute": false', prompt)
        self.assertNotIn('"allow_reboot": false', prompt)
