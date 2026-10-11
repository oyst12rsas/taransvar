import sys,time
from pathlib import Path
sys.path.insert(0,str(Path(__file__).parents[1]/'misc'))
import unittest
from unittest.mock import patch
from operations_prompt import activity_gate,progress_feedback,build_prompt

class GateTest(unittest.TestCase):
    def setUp(self):
        self.policy=dict(mode='demo',execute=True,allow_experimental_commands=True,quiet_seconds=300)
        self.snapshot={'activity':dict(checked_at=1000,complete=True,active_demo=False,
            meaningful_traffic=False,quiet_for_seconds=600)}
        self.results={'gateway_startup':{'result':{'properties':dict(ActiveState='failed',
            executable=dict(exists=True,executable_by_root=False,regular_file=True,path='/example'))}}}
        self.decision=dict(action='report',reason='No quiet evidence',task_state=dict(goal='Inspect',
            verified=[],pending=[],blockers=['quiet_time'],next_check='Wait'),prerequisite=dict(
            kind='quiet_time',detail='Missing observation',next_check='Wait for active traffic'))
    def test_complete_continuous_quiet_evidence_satisfies_gate(self):
        self.assertTrue(activity_gate(self.policy,self.snapshot,1001)['quiet_time_satisfied'])
    def test_stale_future_and_unknown_do_not_satisfy_gate(self):
        for now in (999,1031):self.assertFalse(activity_gate(self.policy,self.snapshot,now)['quiet_time_satisfied'])
        self.assertFalse(activity_gate(self.policy,{},1001)['quiet_time_satisfied'])
    def test_incomplete_active_or_short_duration_does_not_satisfy_gate(self):
        for field,value in [('complete',False),('active_demo',True),('meaningful_traffic',True),
                            ('quiet_for_seconds',299),('quiet_for_seconds',True),('quiet_for_seconds',float('inf'))]:
            sample=dict(self.snapshot['activity']);sample[field]=value
            self.assertFalse(activity_gate(self.policy,{'activity':sample},1001)['quiet_time_satisfied'])
    def test_exact_audi_contradictory_prerequisite_is_rejected(self):
        with patch('operations_prompt.time.time',return_value=1001):
            feedback=progress_feedback(self.decision,self.policy,self.results,self.snapshot)
        self.assertIn('contradicts',feedback)
    def test_genuine_unknown_quiet_prerequisite_is_accepted(self):
        self.assertIsNone(progress_feedback(self.decision,self.policy,self.results,{}))
    def test_gate_and_correction_follow_reference_manual(self):
        with patch('operations_prompt.time.time',return_value=1001):
            text=build_prompt('REFERENCE_MARKER',self.policy,self.snapshot,self.results,{},{},{})
        self.assertGreater(text.index('Current worker gate assessment'),text.index('REFERENCE_MARKER'))
        self.assertIn('"quiet_time_satisfied": true',text)

if __name__=='__main__':unittest.main()
