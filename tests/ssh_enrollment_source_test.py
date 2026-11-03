import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import subprocess
import unittest
from unittest import mock

MISC=Path(__file__).resolve().parents[1]/'misc'
sys.path.insert(0,str(MISC))
import ssh_enrollment_source as source
import ssh_google_window as gate

class SourcePolicyTest(unittest.TestCase):
    def test_exact_host_added_without_changing_other_configuration(self):
        old='OTHER_CONFIG="retain # content"\nSSH_PORT="5822"\nSSH_ALLOWED_SOURCES="100.68.10.7" # owner list\n'
        new=source.updated_config(old,'10.100.0.150')
        self.assertEqual(new,old.replace('"100.68.10.7"','"100.68.10.7,10.100.0.150/32"'))
        self.assertEqual(source.updated_config(new,'10.100.0.150'),new)
        self.assertEqual(source.updated_config('SSH_ALLOWED_SOURCES="10.100.0.0/24"\n','10.100.0.150'),'SSH_ALLOWED_SOURCES="10.100.0.0/24"\n')
        self.assertIn('fd00::1234/128',source.updated_config(old,'fd00::1234'))
        self.assertEqual(source.source_address('::ffff:10.100.0.150'),'10.100.0.150')

    def test_unsafe_or_broad_source_requests_and_ambiguous_config_refused(self):
        for value in ['10.100.0.0/24','0.0.0.0/0','8.8.8.8','::1','::ffff:8.8.8.8',True,None]:
            with self.subTest(value=value),self.assertRaises(ValueError):source.source_address(value)
        for text in ['', 'SSH_ALLOWED_SOURCES="0.0.0.0/0"\n','SSH_ALLOWED_SOURCES=""\n',
                     'SSH_ALLOWED_SOURCES="100.68.10.7"\nSSH_ALLOWED_SOURCES="10.0.0.1"\n']:
            with self.assertRaises(ValueError):source.updated_config(text,'10.100.0.150')

    @unittest.skipUnless(os.getuid()==0,'Root-owned configuration guards')
    def test_atomic_update_private_backup_idempotence_and_acl_preservation(self):
        with tempfile.TemporaryDirectory() as d:
            config=Path(d)/'conf';config.write_text('SSH_PORT=5822\nSSH_ALLOWED_SOURCES="100.68.10.7"\n')
            config.chmod(0o640);old=config.read_bytes()
            has_xattr=False
            try:os.setxattr(config,'user.tarasec-test',b'preserved');has_xattr=True
            except (AttributeError,OSError):pass
            state=Path(d)/'state'
            with mock.patch.object(source,'reconcile_gate') as reconcile:
                self.assertTrue(source.allow_source('10.100.0.150',config,state))
                self.assertFalse(source.allow_source('10.100.0.150',config,state))
                self.assertEqual(reconcile.call_count,2)
            backups=list(state.glob('tarasecfw-*.conf'))
            self.assertEqual(len(backups),1);self.assertEqual(backups[0].read_bytes(),old)
            self.assertEqual(backups[0].stat().st_mode&0o777,0o600)
            self.assertEqual(state.stat().st_mode&0o777,0o700)
            self.assertEqual(config.stat().st_mode&0o777,0o640)
            if has_xattr:self.assertEqual(os.getxattr(config,'user.tarasec-test'),b'preserved')

    @unittest.skipUnless(os.getuid()==0,'Root-owned configuration guards')
    def test_unsafe_files_rejected_without_mutation(self):
        with tempfile.TemporaryDirectory() as d:
            real=Path(d)/'real';real.write_text('SSH_ALLOWED_SOURCES="100.68.10.7"\n');real.chmod(0o600)
            link=Path(d)/'link';link.symlink_to(real)
            with self.assertRaises(OSError):source.allow_source('10.100.0.150',link,Path(d)/'state')
            real.chmod(0o666)
            with self.assertRaises(ValueError):source.allow_source('10.100.0.150',real,Path(d)/'state')
            self.assertNotIn('10.100.0.150',real.read_text())

    @unittest.skipUnless(os.getuid()==0,'Root-owned configuration guards')
    def test_reconcile_failure_is_not_success_and_retry_is_safe(self):
        with tempfile.TemporaryDirectory() as d:
            config=Path(d)/'conf';config.write_text('SSH_ALLOWED_SOURCES="100.68.10.7"\n');config.chmod(0o600)
            state=Path(d)/'state'
            with mock.patch.object(source,'reconcile_gate',side_effect=RuntimeError('failed')):
                with self.assertRaises(RuntimeError):source.allow_source('10.100.0.150',config,state)
            with mock.patch.object(source,'reconcile_gate'):
                self.assertFalse(source.allow_source('10.100.0.150',config,state))
            self.assertEqual(config.read_text().count('10.100.0.150/32'),1)

    @unittest.skipUnless(os.getuid()==0,'Root-owned configuration guards')
    def test_existing_window_deadline_is_preserved_and_closed_gate_stays_closed(self):
        for until in [0,1300]:
            with self.subTest(until=until),tempfile.TemporaryDirectory() as d:
                config=Path(d)/'conf';config.write_text('SSH_PORT=5822\nSSH_ALLOWED_SOURCES="100.68.10.7"\n');config.chmod(0o600)
                policy=Path(d)/'policy';policy.write_text('SSH_GOOGLE_REOPEN_ENABLED=yes\n')
                statefile=Path(d)/'window';statefile.write_text(json.dumps({'until':until,'approved_operator_sha256':'a'*64,'id':'b'*32}))
                old=statefile.read_bytes();calls=[]
                def command(*args,**kwargs):
                    calls.append(args);return '-C' not in args
                with mock.patch.object(gate,'CONF',str(config)),mock.patch.object(gate,'POLICY',str(policy)), \
                     mock.patch.object(gate,'STATE',str(statefile)),mock.patch.object(gate.time,'time',return_value=1000), \
                     mock.patch.object(gate.local_manager_policy,'require_manager'),mock.patch.object(gate,'command',side_effect=command), \
                     mock.patch.object(source,'reconcile_gate',side_effect=gate.reconcile):
                    source.allow_source('10.100.0.150',config,Path(d)/'sources')
                self.assertEqual(statefile.read_bytes(),old)
                source_returns=[c for c in calls if '10.100.0.150/32' in c and 'RETURN' in c]
                self.assertEqual(bool(source_returns),until>1000)
                self.assertFalse(any('systemd-run' in c for c in calls))

    def test_existing_source_filter_gets_only_configured_port_scoped_returns(self):
        import ipaddress
        calls=[]
        def command(args, **kwargs):
            calls.append(args)
            if '-S' in args:
                return subprocess.CompletedProcess(args,0,'-P INPUT DROP\n-N TARASEC_SSH_SOURCE\n','')
            return subprocess.CompletedProcess(args,1 if '-C' in args else 0,'','')
        with mock.patch.object(source.subprocess,'run',side_effect=command):
            source.reconcile_source_filter(5822,[ipaddress.ip_network('100.68.10.7'),ipaddress.ip_network('10.100.0.150')])
        inserts=[args for args in calls if '-I' in args]
        self.assertEqual(len(inserts),2)
        self.assertEqual(inserts[1],['iptables','-w','5','-I','TARASEC_SSH_SOURCE','1','-p','tcp','--dport','5822','-s','10.100.0.150/32','-j','RETURN'])
        self.assertFalse(any('-F' in args or 'ACCEPT' in args or 'INPUT' in args for args in calls))
    def test_missing_source_filter_is_not_created_and_existing_rule_is_idempotent(self):
        import ipaddress
        nets=[ipaddress.ip_network('10.100.0.150')]
        for listing in ['-P INPUT DROP\n','-N TARASEC_SSH_SOURCE\n']:
            calls=[]
            def command(args,**kwargs):
                calls.append(args);return subprocess.CompletedProcess(args,0,listing,'')
            with mock.patch.object(source.subprocess,'run',side_effect=command):
                source.reconcile_source_filter(5822,nets)
            self.assertFalse(any('-I' in args or '-N' in args for args in calls))
    def test_source_filter_errors_are_not_reported_as_success(self):
        import ipaddress
        with mock.patch.object(source.subprocess,'run',return_value=subprocess.CompletedProcess([],2,'','failed')):
            with self.assertRaises(RuntimeError):source.reconcile_source_filter(5822,[ipaddress.ip_network('10.100.0.150')])

    def test_key_operation_validates_source_before_writing_and_keeps_legacy_behavior(self):
        spec=importlib.util.spec_from_file_location('enroll_source_test',MISC/'ssh_device_enrollment.py')
        enroll=importlib.util.module_from_spec(spec);spec.loader.exec_module(enroll)
        with mock.patch.object(enroll,'account_for',return_value=('account','key','fingerprint')), \
             mock.patch.object(enroll,'install_key') as install,mock.patch.object(source,'allow_source') as allow:
            self.assertEqual(enroll.execute({}),'fingerprint');allow.assert_not_called()
            install.reset_mock()
            with self.assertRaises(ValueError):enroll.execute({'ssh_source':'0.0.0.0/0'})
            install.assert_not_called();allow.assert_not_called()
            self.assertEqual(enroll.execute({'ssh_source':'10.100.0.150'}),'fingerprint')
            allow.assert_called_once_with('10.100.0.150')

if __name__=='__main__':unittest.main()
