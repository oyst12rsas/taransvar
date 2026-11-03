import base64
import importlib.util
import json
import os
from pathlib import Path
import struct
import tempfile
import time
import types
import unittest
from unittest import mock

path=Path(__file__).resolve().parents[1]/'misc/ssh_device_enrollment.py'
spec=importlib.util.spec_from_file_location('enrollment',path)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
KEY='ssh-ed25519 '+base64.b64encode(struct.pack('!I',11)+b'ssh-ed25519'+struct.pack('!I',32)+bytes(range(32))).decode()

class EnrollmentTest(unittest.TestCase):
    def test_key_format_rejects_options_injection_and_wrong_type(self):
        for value in ['command="id" '+KEY,KEY+'\n'+KEY,'ssh-rsa AAAA',KEY+' comment','ssh-ed25519 AAAA']:
            with self.assertRaises(ValueError):m.public_key(value)
        self.assertTrue(m.public_key(KEY)[1].startswith('SHA256:'))

    def test_install_preserves_keys_is_idempotent_and_rejects_symlink(self):
        with tempfile.TemporaryDirectory() as d:
            home=Path(d);home.chmod(0o700)
            user=types.SimpleNamespace(pw_dir=d,pw_uid=os.getuid(),pw_gid=os.getgid())
            # The production path validates every ancestor; /tmp is shared here.
            with mock.patch.object(m,'open_home',side_effect=lambda _:os.open(d,os.O_RDONLY|os.O_DIRECTORY)):
                self.assertTrue(m.install_key(user,KEY))
                self.assertFalse(m.install_key(user,KEY))
                keyfile=home/'.ssh/authorized_keys'
                self.assertEqual(keyfile.read_text().count(KEY),1)
                keyfile.unlink(); outside=home/'outside';outside.write_text('unchanged')
                keyfile.symlink_to(outside)
                with self.assertRaises(OSError):m.install_key(user,KEY)
                self.assertEqual(outside.read_text(),'unchanged')

    def test_home_symlink_is_refused(self):
        with tempfile.TemporaryDirectory() as d:
            link=Path(d)/'link';link.symlink_to(Path(d),target_is_directory=True)
            user=types.SimpleNamespace(pw_dir=str(link),pw_uid=os.getuid())
            with self.assertRaises((ValueError,OSError)):m.open_home(user)

    @unittest.skipUnless(os.getuid()==0,'Root-owned policy validation')
    def test_policy_freshness_target_and_fingerprint(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'policy';p.write_text(json.dumps({'allowed_users':['squash']}));p.chmod(0o600)
            job={'ssh_user':'squash','public_key':KEY,'fingerprint':m.public_key(KEY)[1],'approved_at':1000}
            user=types.SimpleNamespace(pw_uid=1000,pw_shell='/bin/bash')
            with mock.patch.object(m.pwd,'getpwnam',return_value=user):
                m.account_for(job,p,now=1001)
                for patch in [{'approved_at':0},{'approved_at':True},{'ssh_user':'root'},{'fingerprint':'wrong'}]:
                    with self.assertRaises(ValueError):m.account_for(dict(job,**patch),p,now=1001)
                p.chmod(0o644)
                with self.assertRaises(ValueError):m.account_for(job,p,now=1001)

if __name__=='__main__':unittest.main()
