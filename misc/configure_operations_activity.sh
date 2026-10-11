#!/bin/bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Run as root'; exit 1; }
[[ $# -eq 2 || ( $# -eq 3 && $3 == --netbird-http ) ]] || { echo 'Usage: configure_operations_activity.sh FEED_URL NODE_KEY_FILE [--netbird-http]'; exit 1; }
python3 - "$1" "$2" "${3:-}" <<'PY'
from pathlib import Path
import json,re,sys,urllib.parse
url=urllib.parse.urlsplit(sys.argv[1])
netbird_http=sys.argv[3]=='--netbird-http'
if (url.scheme not in (('http','https') if netbird_http else ('https',))
    or not url.hostname or url.username or url.password):
    raise SystemExit('Use HTTPS feed URL without embedded credentials')
token=Path(sys.argv[2]).read_text().strip()
if not re.fullmatch('[a-f0-9]{64}',token):raise SystemExit('Invalid node key')
root=Path('/etc/tarasec');root.mkdir(mode=0o700,exist_ok=True)
key=root/'operations-activity.key';key.write_text(token+'\n');key.chmod(0o600)
path=root/'operations-activity.json'
config=json.loads(path.read_text()) if path.exists() else {}
config.update(demo_url=sys.argv[1],demo_key_file=str(key),allow_netbird_http=netbird_http,
    demo_collector='/usr/local/lib/tarasec-operations/demo-collector',
    traffic_collector='/usr/local/lib/tarasec-operations/traffic-collector')
path.write_text(json.dumps(config,indent=2)+'\n');path.chmod(0o600)
PY
/usr/local/lib/tarasec-operations/demo-collector
systemctl enable --now tarasec-operations-activity.service
systemctl restart tarasec-operations-activity.service
echo 'Observer started. Complete, continuously quiet evidence is still required before mutations.'
