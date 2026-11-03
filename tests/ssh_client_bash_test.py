import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'misc/tarasec-client.sh'
MOCK = r'''#!/usr/bin/env python3
import base64, hashlib, json, os, pathlib, subprocess, sys
args=sys.argv[1:]
out=pathlib.Path(args[args.index('--output')+1]);url=args[args.index('--url')+1]
mode=os.environ['TS_SCENARIO'];ref='a'*64;token='b'*64
if url.endswith('/script/sshClientQueue.php'):
    service='https://other.test/ops/agent/' if mode=='wrong-service' else 'https://tarasec.org/ops/agent/'
    job=None if mode=='idle' else {'server_ref':ref,'pairing_token':token,'ssh_user':'alice','device_name':'Acer'}
    if mode=='source-bootstrap':
        body=json.loads(pathlib.Path(args[args.index('--data-binary')+1][1:]).read_text()) if '--data-binary' in args else {}
        if not body.get('public_key'): job={'id':'q','needs_key':True,'server_ref':ref,'ssh_user':'alice'}
        else: (pathlib.Path(os.environ['TS_STATE'])/'announced-key').write_text(body['public_key'])
    value={'ok':True,'server_ref':ref,'approval_service':service,'job':job}
elif url.endswith('action=challenge'):
    value={'ok':True,'server_ref':ref,'ssh_user':'mallory' if mode=='wrong-user' else 'alice','device_name':'Acer','message':'real signed challenge\n'}
elif url.endswith('action=submit'):
    body=json.loads(pathlib.Path(args[args.index('--data-binary')+1][1:]).read_text())
    assert body['pairing_token']==token and 'PRIVATE KEY' not in json.dumps(body)
    folder=pathlib.Path(os.environ['TS_STATE']);sig=folder/'signature';sig.write_text(body['signature'])
    allowed=folder/'allowed';allowed.write_text('client '+body['public_key']+'\n')
    subprocess.run(['ssh-keygen','-Y','verify','-f',str(allowed),'-I','client','-n','tarasec-ssh-enrollment','-s',str(sig)],
        input=b'real signed challenge\n',stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
    fingerprint='SHA256:'+base64.b64encode(hashlib.sha256(base64.b64decode(body['public_key'].split()[1])).digest()).decode().rstrip('=')
    value={'ok':True,'server_ref':ref,'fingerprint':'wrong' if mode=='wrong-fingerprint' else fingerprint}
    (folder/'submitted').write_text(body['public_key'])
else:
    raise AssertionError(url)
out.write_text(json.dumps(value))
'''

class BashClientTest(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.home=self.root/'home';self.home.mkdir()
        self.bin=self.root/'bin';self.bin.mkdir()
        self.env=dict(os.environ,HOME=str(self.home),PATH=str(self.bin)+':'+os.environ['PATH'],TS_STATE=str(self.root))
        (self.bin/'curl').write_text(MOCK);(self.bin/'curl').chmod(0o700)
    def run_client(self,mode,*extra):
        return subprocess.run(['bash',str(SCRIPT),'--gateway','http://100.68.165.190',*extra],
            env=dict(self.env,TS_SCENARIO=mode),text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=15)
    def test_idle_does_not_generate_a_key(self):
        r=self.run_client('idle');self.assertEqual(r.returncode,0,r.stderr)
        self.assertFalse((self.home/'.ssh').exists())
    def test_queued_key_is_signed_and_reused_without_prompts(self):
        r=self.run_client('pending');self.assertEqual(r.returncode,0,r.stderr)
        key=self.home/'.ssh/id_ed25519_tarasec';before=key.read_bytes()
        self.assertEqual(key.stat().st_mode & 0o777,0o600)
        self.assertTrue((self.root/'submitted').exists())
        self.assertIn('Finalize at',r.stdout);self.assertNotIn('b'*64,r.stdout+r.stderr)
        r=self.run_client('pending');self.assertEqual(r.returncode,0,r.stderr)
        self.assertEqual(before,key.read_bytes())
    def test_source_bootstrap_announces_exact_key_before_signing(self):
        r=self.run_client('source-bootstrap');self.assertEqual(r.returncode,0,r.stderr)
        self.assertEqual((self.root/'announced-key').read_text(),(self.root/'submitted').read_text())
        self.assertIn('Finalize at',r.stdout)
    def test_mismatches_stop_submission_or_completion(self):
        for mode in ['wrong-service','wrong-user','wrong-fingerprint']:
            with self.subTest(mode=mode):
                r=self.run_client(mode);self.assertNotEqual(r.returncode,0)
                self.assertNotIn('Key request submitted',r.stdout)
    def test_install_preserves_crontab_and_is_idempotent(self):
        cron=self.root/'crontab';cron.write_text('0 * * * * existing-job\n')
        mock='#!/bin/sh\nif [ "$1" = "-l" ]; then cat "$TS_STATE/crontab"; else cp "$1" "$TS_STATE/crontab"; fi\n'
        (self.bin/'crontab').write_text(mock);(self.bin/'crontab').chmod(0o700)
        for _ in range(2):
            r=self.run_client('idle','--install');self.assertEqual(r.returncode,0,r.stderr)
        self.assertIn('existing-job',cron.read_text())
        self.assertEqual(cron.read_text().count('# tarasec-ssh-client'),1)
        self.assertFalse((self.home/'.ssh').exists())
        config=json.loads((self.home/'.config/tarasec-client/settings.json').read_text())
        self.assertEqual(config['gateways'],['http://100.68.165.190'])
        r=subprocess.run(['bash',str(self.home/'.local/bin/tarasec-client'),'--once'],env=dict(self.env,TS_SCENARIO='idle'),capture_output=True,text=True)
        self.assertEqual(r.returncode,0,r.stderr)

if __name__=='__main__': unittest.main()

