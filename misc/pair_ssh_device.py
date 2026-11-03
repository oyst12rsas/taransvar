#!/usr/bin/env python3
"""Acer/Termux enrollment client. The private key never leaves this device."""
import argparse
import getpass
import json
from pathlib import Path
import re
import subprocess
import tempfile
import time
import socket
import hashlib
import base64
import urllib.error
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
    try:
        with urllib.request.build_opener(NoRedirect()).open(req, timeout=15) as response:
            raw = response.read(16385)
    except urllib.error.HTTPError as error:
        raise ValueError('Enrollment service rejected the request (HTTP %s). Check that the hosted approval service is updated and retry.' % error.code) from None
    if len(raw)>16384:
        raise ValueError('Enrollment response too large')
    value = json.loads(raw)
    if value.get('ok') is not True:
        raise ValueError('Enrollment rejected')
    return value


def discover(server, service):
    value = server if '://' in server else 'http://' + server
    url = urllib.parse.urlsplit(value)
    if url.scheme not in ('http', 'https') or not url.hostname or url.username or url.password or url.query or url.fragment or url.path not in ('', '/'):
        raise ValueError('Use the server management address, for example http://100.68.165.190')
    req = urllib.request.Request(value.rstrip('/') + '/script/sshDeviceTarget.php')
    with urllib.request.build_opener(NoRedirect()).open(req, timeout=15) as response:
        raw = response.read(16385)
    if len(raw) > 16384:
        raise ValueError('Server discovery response too large')
    target = json.loads(raw).get('approvalTarget', {})
    # Discovery never chooses a different approval service or receives private data.
    if target.get('approval_url') != service or not re.fullmatch('[a-f0-9]{64}', target.get('server_ref', '')):
        raise ValueError('Server approval identity does not match this service')
    return target['server_ref']


def local_key(key):
    key = key.expanduser()
    if not key.exists():
        key.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        subprocess.run(['ssh-keygen', '-t', 'ed25519', '-f', str(key)], check=True)
    public = subprocess.run(['ssh-keygen', '-y', '-f', str(key)], stdout=subprocess.PIPE, text=True, check=True).stdout.strip()
    if not public.startswith('ssh-ed25519 '):
        raise ValueError('Use an Ed25519 private key')
    return key, ' '.join(public.split()[:2])


def sign(key, challenge):
    with tempfile.TemporaryDirectory(prefix='tarasec-ssh-pair-') as folder:
        message = Path(folder) / 'challenge'
        message.write_bytes(challenge.encode('utf-8'))
        subprocess.run(['ssh-keygen', '-Y', 'sign', '-f', str(key), '-n', 'tarasec-ssh-enrollment', str(message)], check=True)
        return Path(str(message) + '.sig').read_text()


def automatic(args):
    user = args.user or input('Linux username on the selected server: ').strip()
    label = args.device or re.sub('[^A-Za-z0-9 ._-]', '-', socket.gethostname())[:48]
    if not re.fullmatch('[a-z_][a-z0-9_-]{0,31}', user) or not re.fullmatch('[A-Za-z0-9][A-Za-z0-9 ._-]{0,47}', label):
        raise ValueError('Enter a Linux username and a device name, not an IP address')
    ref = discover(args.server, args.service)
    key, public = local_key(args.key)
    fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(public.split()[1], validate=True)).digest()).decode().rstrip('=')
    challenge = request(args.service, 'device_start', {'server_ref': ref, 'ssh_user': user, 'device_name': label, 'public_key': public})
    token = challenge.get('pairing_token', '')
    if (not re.fullmatch('[a-f0-9]{64}', token) or challenge.get('server_ref') != ref
            or challenge.get('ssh_user') != user or challenge.get('device_name') != label
            or not isinstance(challenge.get('message'), str) or len(challenge['message']) > 4096):
        raise ValueError('Invalid device enrollment challenge')
    signature = sign(key, challenge['message'])
    reply = request(args.service, 'submit', {'pairing_token': token, 'public_key': public, 'signature': signature})
    if reply.get('server_ref') != ref or reply.get('fingerprint') != fingerprint:
        raise ValueError('Enrollment response does not match this server and key')
    print('Request sent automatically for', label, 'on', args.server, 'as', user)
    print('Fingerprint:', fingerprint)
    print('In TaraSec: Manage this server → SSH → approve this device with your Authenticator.')
    print('Waiting for the server to confirm key installation…', flush=True)
    last = None
    deadline = time.monotonic() + 900
    while time.monotonic() < deadline:
        result = request(args.service, 'status', {'pairing_token': token})
        if result.get('server_ref') != ref or result.get('fingerprint') != fingerprint or result.get('id') != reply.get('id'):
            raise ValueError('Installation status does not match your request')
        state = result.get('state')
        if state != last:
            print('Device key status:', state, flush=True)
            last = state
        if state == 'done':
            print('The server confirmed installation. You can now test SSH using this key:', key)
            return
        if state in ('failed', 'rejected', 'expired', 'cancelled'):
            raise ValueError('Key was not installed: ' + state + '. Check this request in the app.')
        if state not in ('pending', 'approved', 'running'):
            raise ValueError('Unknown device installation status')
        time.sleep(3)
    raise ValueError('Timed out waiting for server confirmation. Check the device status in the app before retrying.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--service', default='https://tarasec.org/ops/agent/')
    parser.add_argument('--key', type=Path, default=Path.home()/'.ssh/id_ed25519')
    parser.add_argument('--server', help='Server management address; submits a device request without copying pairing codes')
    parser.add_argument('--user', help='Linux username on that server')
    parser.add_argument('--device', help='Device name (defaults to this computer hostname)')
    args = parser.parse_args()
    endpoint(args.service)
    if args.server:
        return automatic(args)
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
    try:
        main()
    except (ValueError, OSError, subprocess.CalledProcessError) as error:
        raise SystemExit(str(error))

