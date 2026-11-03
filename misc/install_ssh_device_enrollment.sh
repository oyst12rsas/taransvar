#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 && $# -eq 1 ]] || { echo 'Usage: sudo bash misc/install_ssh_device_enrollment.sh <local-SSH-account>'; exit 1; }
root="$(cd "$(dirname "$0")" && pwd)"
# Update worker first; enrollment grants remain entirely node-owned.
bash "$root/install_agent_approvals.sh" --update-only
python3 - "$1" <<'PY'
import json, os, pwd, re, stat, sys, tempfile
from pathlib import Path
name=sys.argv[1]
if not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}',name): raise SystemExit('Invalid account')
account=pwd.getpwnam(name)
if account.pw_uid<1000 or account.pw_shell.endswith(('nologin','/false')): raise SystemExit('Interactive non-root account required')
path=Path('/etc/tarasec/ssh-key-enrollment.json')
users=[]
if path.exists() or path.is_symlink():
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    with os.fdopen(fd) as f:
        st=os.fstat(f.fileno())
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o077: raise SystemExit('Unsafe enrollment policy')
        users=json.load(f)['allowed_users']
fd,tmp=tempfile.mkstemp(dir=path.parent,prefix='ssh-key-policy-')
with os.fdopen(fd,'w') as f:
    json.dump({'allowed_users':sorted(set(users+[name]))},f)
os.replace(tmp,path)
print('SSH key enrollment enabled for:',name)
print('Authentication and firewall policy unchanged; verify a new key login before enforcing key + password.')
PY
