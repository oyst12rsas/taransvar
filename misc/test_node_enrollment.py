import base64
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock
import node_enrollment as enrollment


class NodeEnrollmentTests(unittest.TestCase):
    def test_public_vpn_address_is_not_a_credential_transport(self):
        for url in ['http://100.68.126.0/ops/agent/api.php','https://user:password@host/api.php','https://host/api.php?token=x']:
            with self.assertRaises(ValueError):enrollment.endpoint(url)

    def test_private_key_signature_and_sealed_token_roundtrip(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);private,public,fingerprint=enrollment.identity(root)
            self.assertEqual(private.stat().st_mode & 0o777,0o600)
            proof=enrollment.signed_request(private,public,fingerprint,'enroll','reporting','test-node')
            message='\n'.join(['tarasec-enroll-v1','enroll',fingerprint,str(proof['timestamp']),proof['nonce'],'reporting','test-node'])
            public_path=root/'public.pem';public_path.write_text(public)
            signature=root/'signature';signature.write_bytes(base64.b64decode(proof['signature']))
            subprocess.run(['openssl','dgst','-sha256','-verify',str(public_path),'-signature',str(signature)],input=message.encode(),check=True,capture_output=True)
            token='a'*64
            sealed=enrollment.openssl('pkeyutl','-encrypt','-pubin','-inkey',str(public_path),'-pkeyopt','rsa_padding_mode:oaep',input=token.encode())
            with mock.patch.object(enrollment,'request',return_value=dict(ok=True,id=fingerprint,state='approved',role='reporting',token_until=2000000000,sealed_token=base64.b64encode(sealed).decode())):
                self.assertEqual(enrollment.enroll('https://host/api.php',state=root)['state'],'approved')
            self.assertEqual((root/'agent-node.token').read_text().strip(),token)
            self.assertEqual((root/'agent-node.token').stat().st_mode & 0o777,0o600)
            self.assertNotIn(token,(root/'node-credential.json').read_text())
            with self.assertRaises(RuntimeError):enrollment.enroll('https://another-host/api.php',state=root)

    def test_pending_has_no_token_and_revoked_removes_expired_credentials(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);_,_,fingerprint=enrollment.identity(root)
            with mock.patch.object(enrollment,'request',return_value={'id':fingerprint,'state':'pending'}):
                self.assertEqual(enrollment.enroll('https://host/api.php',state=root)['state'],'pending')
            self.assertFalse((root/'agent-node.token').exists())
            enrollment.atomic_write(root/'agent-node.token','a'*64)
            enrollment.atomic_write(root/'node-credential.json',json.dumps({'api_url':'https://host/api.php','until':0}))
            with mock.patch.object(enrollment,'request',return_value={'id':fingerprint,'state':'revoked'}):
                self.assertEqual(enrollment.enroll('https://host/api.php',state=root)['state'],'revoked')
            self.assertFalse((root/'agent-node.token').exists())


if __name__=='__main__':unittest.main()
