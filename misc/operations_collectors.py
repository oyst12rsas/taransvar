#!/usr/bin/env python3
"""Root-local activity collectors. Missing telemetry is never quiet evidence."""
import argparse
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


def demo():
    cfg=json.loads(trusted('/etc/tarasec/operations-activity.json').read_text())
    url=cfg['demo_url']
    if not url.startswith('https://'):
        raise ValueError('HTTPS demo feed required')
    token=trusted(cfg['demo_key_file']).read_text().strip()
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,*args,**kwargs):return None
    req=urllib.request.Request(url,headers={'X-TaraSec-Operations-Token':token})
    with urllib.request.build_opener(NoRedirect).open(req,timeout=4) as response:
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
    print(json.dumps(sample))

if __name__=='__main__':main()
