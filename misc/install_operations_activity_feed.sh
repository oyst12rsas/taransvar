#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root'; exit 1; }
[[ $# -eq 2 ]] || { echo 'Usage: install_operations_activity_feed.sh NODE_SOURCE_IP WEB_ROOT'; exit 1; }
source_dir="$(cd "$(dirname "$0")" && pwd)"
python3 - "$1" <<'PY'
import ipaddress,sys
ipaddress.ip_address(sys.argv[1])
PY
[[ -d "$2/script" && -f "$2/dbfunc.php" ]] || { echo 'Invalid TaraSec web root'; exit 1; }
php -l "$source_dir/../html/script/operationsActivity.php"
install -d -o root -g www-data -m 0750 /etc/tarasec-operations-feed
install -o root -g root -m 0644 "$source_dir/../html/script/operationsActivity.php" "$2/script/operationsActivity.php"
python3 - "$1" <<'PY'
from pathlib import Path
import hashlib,json,os,secrets,sys,grp,fcntl
root=Path('/etc/tarasec-operations-feed')
with (root/'lock').open('w') as lock:
    fcntl.flock(lock,fcntl.LOCK_EX)
    path=root/'tokens.json'
    tokens=json.loads(path.read_text()) if path.exists() else {}
    key=root/('node-'+sys.argv[1].replace(':','_')+'.key')
    token=key.read_text().strip() if key.exists() else secrets.token_hex(32)
    key.write_text(token+'\n');os.chmod(key,0o600)
    tokens[sys.argv[1]]=hashlib.sha256(token.encode()).hexdigest()
    temporary=root/'tokens.tmp';temporary.write_text(json.dumps(tokens)+'\n')
    os.chmod(temporary,0o640);os.chown(temporary,0,grp.getgrnam('www-data').gr_gid)
    temporary.replace(path)
    print('Node key file: '+str(key)+'; transfer securely to node, never paste into chat.')
PY
php -l "$2/script/operationsActivity.php"
echo 'Feed installed. Verify HTTPS access from the enrolled node; unknown/missing schema remains incomplete.'
