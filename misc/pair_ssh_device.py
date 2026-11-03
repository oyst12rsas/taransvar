#!/usr/bin/env python3
"""Acer/Termux enrollment client. The private key never leaves this device."""
import argparse
import getpass
import json
from pathlib import Path
import re
import subprocess
import tempfile
import urllib.parse
import urllib.request

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def endpoint(service):
    url = urllib.parse.urlsplit(service)
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment or not url.path.endswith('/ops/agent/'):
        raise ValueError('An HTTPS /ops/agent/ service URL without credentials is required')
    return service+'ssh-device.php'


def request(service, action, body):
    req = urllib.request.Request(endpoint(service)+'?action='+action,
        data=json.dumps(body).encode(), headers={'Content-Type':'application/json'}, method='POST')
    with urllib.request.build_opener(NoRedirect()).open(req, timeout=15) as response:
        raw = response.read(16385)
    if len(raw)>16384:
        raise ValueError('Enrollment response too large')
    value = json.loads(raw)
    if value.get('ok') is not True:
        raise ValueError('Enrollment rejected')
    return value


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--service', default='https://tarasec.org/ops/agent/')
    parser.add_argument('--key', type=Path, default=Path.home()/'.ssh/id_ed25519')
    args = parser.parse_args()
    endpoint(args.service)
    token = getpass.getpass('Pairing code from TaraSec app (hidden): ').strip()
    if not re.fullmatch('[a-f0-9]{64}', token):
        raise SystemExit('Invalid pairing code')
    challenge = request(args.service, 'challenge', {'pairing_token':token})
    print('Pairing device:',challenge['device_name'],'Account:',challenge['ssh_user'])
    key = args.key.expanduser()
    if not key.exists():
        key.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        subprocess.run(['ssh-keygen','-t','ed25519','-f',str(key)], check=True)
    public = subprocess.run(['ssh-keygen','-y','-f',str(key)], capture_output=False, stdout=subprocess.PIPE, text=True, check=True).stdout.strip()
    if not public.startswith('ssh-ed25519 '):
        raise SystemExit('Use an Ed25519 private key')
    # ssh-keygen handles encrypted keys and private-key possession locally.
    with tempfile.TemporaryDirectory(prefix='tarasec-ssh-pair-') as folder:
        message = Path(folder)/'challenge'
        message.write_bytes(challenge['message'].encode('utf-8'))
        subprocess.run(['ssh-keygen','-Y','sign','-f',str(key),'-n','tarasec-ssh-enrollment',str(message)], check=True)
        signature = Path(str(message)+'.sig').read_text()
    result = request(args.service,'submit',{'pairing_token':token,'public_key':' '.join(public.split()[:2]),'signature':signature})
    print('Key submitted. Confirm this device, account and fingerprint in TaraSec Ops:')
    print(result['fingerprint'])
    print(args.service+'?server='+result['server_ref']+'&locked=1')
    print('Nothing is installed until you approve with Google and Authenticator.')

if __name__ == '__main__':
    main()
