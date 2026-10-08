#!/usr/bin/env python3
"""Configure a separate direct probe. Never change live worker policy or timers."""
import getpass
import json
import os
from pathlib import Path


def main():
    if os.geteuid()!=0:raise SystemExit('Run as root')
    name=input('Model name (use the same model configured in Flowise): ').strip()
    if not name:raise SystemExit('Model name required')
    key=getpass.getpass('OpenAI API key (hidden; not the Flowise operations key): ').strip()
    if not key:raise SystemExit('Key required')
    root=Path('/etc/tarasec')
    policy=json.loads((root/'operations-agent.json').read_text())
    keypath=root/'operations-openai.key'
    fd=os.open(keypath,os.O_WRONLY|os.O_CREAT|os.O_TRUNC|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as stream:stream.write(key+'\n');os.fchmod(stream.fileno(),0o600)
    policy.update(model_provider='openai',openai_model=name,openai_key_file=str(keypath))
    path=root/'operations-direct.json'
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_TRUNC|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as stream:json.dump(policy,stream,indent=2);os.fchmod(stream.fileno(),0o600)
    print('Separate non-executing probe configured; live worker configuration unchanged.')

if __name__=='__main__':main()
