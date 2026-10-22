import hashlib
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch
import enroll_gatekeeper_google as gk
import enroll_google_manager as agent


class GatekeeperEnrollment(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.web = self.root/'web'
        (self.web/'gatekeeper').mkdir(parents=True)
        (self.web/'gatekeeper/google_callback.php').touch()
        self.opener = Mock()
        self.opener.open.side_effect = self.answer
        self.calls = []
        agent.enroll(['owner@example.com'], root=self.root, opener=self.opener)
        self.acl = patch.object(gk.subprocess, 'run').start()
        self.addCleanup(patch.stopall)
        patch.object(gk.grp, 'getgrnam', return_value=Mock(gr_gid=0)).start()

    def answer(self, req, timeout):
        data=json.loads(req.data); self.calls.append(data)
        token=req.get_header('Authorization').split()[1]
        node='enrolled-'+hashlib.sha256(token.encode()).hexdigest()
        result=dict(ok=True,node_id=node,nickname=data['nickname'],manager_count=len(data['manager_hashes']))
        if 'gatekeeper' in data: result['gatekeeper']=dict(data['gatekeeper'],node_id=node)
        return io.BytesIO(json.dumps(result).encode())

    def enable(self):
        return gk.enable('http://100.68.1.2',self.web,self.root,self.opener)

    def test_register_separate_credential_and_retry_without_raw_email(self):
        url=self.enable(); self.assertIn('index.php?f=login',url)
        config=self.root/'etc/tarasec/gatekeeper-google.php'
        before=config.read_text()
        token=(self.root/'etc/tarasec/agent-node.token').read_text().strip()
        self.assertNotIn(token,before)
        self.assertNotIn('owner@example.com',json.dumps(self.calls[-1]))
        self.assertEqual(config.stat().st_mode&0o777,0o640)
        self.enable();self.assertEqual(config.read_text(),before)
        self.assertEqual(self.calls[-1]['gatekeeper'],self.calls[-2]['gatekeeper'])

    def test_sync_revokes_local_projection(self):
        self.enable()
        policy=self.root/'etc/tarasec/access.conf'
        policy.write_text('MANAGER_EMAILS=""\nMANAGER_EMAIL_SHA256=""\n')
        self.enable()
        self.assertEqual(json.loads((self.root/'etc/tarasec/gatekeeper-managers.json').read_text()),[])
        self.assertEqual(self.calls[-1]['manager_hashes'],[])

    def test_old_service_does_not_activate_and_retry_retains_secret(self):
        original=self.opener.open.side_effect
        def old(req,timeout):
            response=json.loads(original(req,timeout).read());response.pop('gatekeeper',None)
            return io.BytesIO(json.dumps(response).encode())
        self.opener.open.side_effect=old
        with self.assertRaises(ValueError):self.enable()
        pending=self.root/'etc/tarasec/gatekeeper-google-enrollment.json'
        before=pending.read_text()
        self.assertFalse((self.root/'etc/tarasec/gatekeeper-google.php').exists())
        self.opener.open.side_effect=original
        self.enable();self.assertEqual(pending.read_text(),before)

    def test_legacy_config_never_replaced(self):
        config=self.root/'etc/tarasec/gatekeeper-google.php'
        config.write_text('<?php return [];')
        calls=len(self.calls)
        self.enable()
        self.assertEqual(config.read_text(),'<?php return [];')
        self.assertEqual(len(self.calls),calls)

    def test_unsafe_callback_urls_rejected(self):
        for base in ['http://example.org','http://127.0.0.1','http://192.168.1.1',
                     'https://u:p@example.org','https://example.org/evil','https://example.org?x=1']:
            with self.subTest(base=base),self.assertRaises(ValueError):gk.callback_url(base)
        self.assertEqual(gk.callback_url('https://node.example.org:8443/gatekeeper'),
                         'https://node.example.org:8443/gatekeeper/google_callback.php')

if __name__=='__main__':unittest.main()
