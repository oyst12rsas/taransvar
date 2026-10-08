import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1] / 'misc'))
import unittest
from operations_agent import recent_diagnostics

class CacheTest(unittest.TestCase):
    def setUp(self):
        self.state=dict(boot_id='boot',status='reported',diagnostics={
            'gateway_startup':dict(checked_at=950,result={'properties':{'ActiveState':'failed'}})})
    def test_recent_evidence_is_retained(self):
        self.assertEqual(recent_diagnostics(self.state,1000,'boot'),self.state['diagnostics'])
    def test_expired_future_and_other_boot_evidence_are_discarded(self):
        self.assertEqual(recent_diagnostics(self.state,1301,'boot'),{})
        self.assertEqual(recent_diagnostics(self.state,949,'boot'),{})
        self.assertEqual(recent_diagnostics(self.state,1000,'newboot'),{})
    def test_evidence_after_command_or_resource_action_is_discarded(self):
        for status in ('command_executed','command_failed','resource_ok','procedure_ok','reboot_requested'):
            self.state['status']=status
            self.assertEqual(recent_diagnostics(self.state,1000,'boot'),{})
    def test_malformed_or_unrecognized_cache_is_discarded(self):
        self.state['diagnostics']={'unknown':dict(checked_at=950),'gateway_startup':dict(checked_at=True)}
        self.assertEqual(recent_diagnostics(self.state,1000,'boot'),{})

if __name__ == '__main__':unittest.main()
