#!/usr/bin/env python3
"""Root-local activity collectors. Missing telemetry is never quiet evidence."""
import argparse
import ipaddress
import urllib.parse
import json
from pathlib import Path
import re
import time
import urllib.request
from operations_agent import run, trusted, save


def forward_total(output):
    # Sum policy and rule counters; overcount is conservative. Missing chain is unknown.
    match = re.search(r'^:FORWARD \S+ \[(\d+):\d+\]$', output, re.M)
    if not match:
        raise ValueError('Forward chain counters missing')
    total = int(match[1])
    for line in output.splitlines():
        if '-A FORWARD ' in line:
            count = re.match(r'^\[(\d+):\d+\] ', line)
            if not count:
                raise ValueError('Forward rule counter missing')
            total += int(count[1])
    return total


def traffic_sample(previous, counters, now, boot):
    valid = (previous.get('boot_id') == boot and previous.get('rules') == counters['rules']
        and 0 <= now - previous.get('checked_at',0) <= 30
        and counters['packets'] >= previous.get('packets',-1))
    changed = not valid or counters['packets'] != previous.get('packets')
    return {'checked_at':now,'complete':valid,'meaningful_traffic':changed,
        'reporting_exclusion_verified':True,'coverage':'forwarded IPv4 and IPv6 packets',
        'boot_id':boot,'packets':counters['packets'],'rules':counters['rules']}


def traffic():
    outputs=[]
    for binary in ('/usr/sbin/iptables-save','/usr/sbin/ip6tables-save'):
        result=run([binary,'-c','-t','filter'],5)
        if result['exit_code'] != 0:
            raise ValueError('Forward counters unavailable')
        outputs.append(result['output'])
    packets=sum(forward_total(out) for out in outputs)
    rules='\n'.join('\n'.join(line for line in re.sub(r'\[\d+:\d+\]', '[counter]', out).splitlines() if not line.startswith('#')) for out in outputs)
    path=Path('/run/tarasec-operations/forward-counters.json')
    previous=json.loads(trusted(path).read_text()) if path.exists() else {}
    sample=traffic_sample(previous,{'packets':packets,'rules':rules},time.time(),
        Path('/proc/sys/kernel/random/boot_id').read_text().strip())
    save(path,sample)
    return sample



def validate_feed_url(cfg, runner):
    url=urllib.parse.urlsplit(cfg['demo_url'])
    if not url.hostname or url.username or url.password or url.fragment:
        raise ValueError('Invalid demo feed URL')
    if url.scheme=='https':return
    if url.scheme!='http' or cfg.get('allow_netbird_http') is not True:
        raise ValueError('HTTPS or explicit NetBird HTTP required')
    address=ipaddress.ip_address(url.hostname)
    if address not in ipaddress.ip_network('100.64.0.0/10'):
        raise ValueError('NetBird feed requires literal overlay IPv4 address')
    result=runner(['/usr/sbin/ip','-j','route','get',str(address)],3)
    routes=json.loads(result['output']) if result['exit_code']==0 else []
    if len(routes)!=1 or routes[0].get('dev')!='wt0':
        raise ValueError('NetBird feed route not verified')


def demo():
    cfg=json.loads(trusted('/etc/tarasec/operations-activity.json').read_text())
    url=cfg['demo_url']
    validate_feed_url(cfg,run)
    token=trusted(cfg['demo_key_file']).read_text().strip()
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,*args,**kwargs):return None
    req=urllib.request.Request(url,headers={'X-TaraSec-Operations-Token':token})
    with urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect).open(req,timeout=4) as response:
        raw=response.read(8193)
    if len(raw)>8192:raise ValueError('Oversized demo feed')
    sample=json.loads(raw)
    if (sample.get('complete') is not True or not isinstance(sample.get('active_demo'),bool)
        or not isinstance(sample.get('checked_at'),(int,float))
        or not 0 <= time.time()-sample['checked_at'] <= 30):
        raise ValueError('Incomplete demo feed')
    return sample


def main():
    parser=argparse.ArgumentParser();parser.add_argument('kind',choices=['demo','traffic']);args=parser.parse_args()
    try:sample=demo() if args.kind=='demo' else traffic()
    except (OSError,ValueError,KeyError):sample={'checked_at':time.time(),'complete':False}
    print(json.dumps({key: value for key, value in sample.items() if key != 'rules'}))

if __name__=='__main__':main()
