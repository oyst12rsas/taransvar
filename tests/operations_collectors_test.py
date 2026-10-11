import sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).parents[1]/'misc'))
import unittest
from operations_collectors import forward_total,traffic_sample,validate_feed_url
from operations_actions import read_only_command

class CollectorTest(unittest.TestCase):
    def test_only_forward_counts_include_policy_and_rules(self):
        self.assertEqual(forward_total(':FORWARD ACCEPT [10:100]\n[4:40] -A FORWARD -j ACCEPT\n[500:5000] -A OUTPUT -j ACCEPT'),14)
    def test_missing_chain_or_rule_counter_is_unknown(self):
        for output in ('',':FORWARD ACCEPT [0:0]\n-A FORWARD -j ACCEPT'):
            with self.assertRaises(ValueError):forward_total(output)
    def test_first_sample_restart_reset_and_rules_change_are_not_quiet(self):
        counters=dict(packets=10,rules='rules')
        initial=traffic_sample({},counters,100,'boot')
        self.assertFalse(initial['complete'])
        idle=traffic_sample(initial,counters,110,'boot')
        self.assertTrue(idle['complete']);self.assertFalse(idle['meaningful_traffic'])
        for now,boot,c in ((150,'boot',counters),(110,'new',counters),(110,'boot',dict(packets=9,rules='rules')),(110,'boot',dict(packets=10,rules='new'))):
            self.assertFalse(traffic_sample(initial,c,now,boot)['complete'])
    def test_packet_growth_blocks_quiet(self):
        old=traffic_sample({},dict(packets=10,rules='rules'),100,'boot')
        self.assertTrue(traffic_sample(old,dict(packets=11,rules='rules'),110,'boot')['meaningful_traffic'])
    def test_only_exact_observed_syntax_check_skips_quiet(self):
        results={'gateway_startup':{'result':{'properties':{'executable':dict(path='/example',regular_file=True)}}}}
        self.assertTrue(read_only_command(['/usr/bin/bash','-n','/example'],results))
        for argv in (['/usr/bin/bash','/example'],['/bin/chmod','u+x','/example'],['/usr/bin/bash','-n','/different'],['/usr/bin/bash','-c','anything']):
            self.assertFalse(read_only_command(argv,results))
        self.assertFalse(read_only_command(['/usr/bin/bash','-n','/example'],{}))

    def test_netbird_http_requires_explicit_flag_and_overlay_route(self):
        import json
        cfg=dict(demo_url='http://100.68.126.0/script/operationsActivity.php',allow_netbird_http=True)
        runner=lambda argv,timeout:dict(exit_code=0,output=json.dumps([dict(dev='wt0')]))
        validate_feed_url(cfg,runner)
        for change in (dict(allow_netbird_http=False),dict(demo_url='http://example.org/feed'),dict(demo_url='http://192.168.122.7/feed')):
            with self.assertRaises(ValueError):validate_feed_url(dict(cfg,**change),runner)
        with self.assertRaises(ValueError):validate_feed_url(cfg,lambda argv,t:dict(exit_code=0,output='[{"dev":"enp1s0"}]'))

if __name__=='__main__':unittest.main()
