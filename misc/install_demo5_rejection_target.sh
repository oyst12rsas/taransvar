#!/bin/bash
# Explicit decoy port for real, normally ingested receiver rejection evidence.
set -euo pipefail
PORT=${1:-4205}
[[ "$PORT" =~ ^[0-9]+$ ]] && (( PORT >= 1024 && PORT <= 65535 )) || exit 1
if ss -H -lnt "sport = :$PORT" | grep -q .; then
    echo 'Port already has a listener; refusing to install decoy rejection.'; exit 1
fi
iptables -w 5 -N TARASEC_DEMO5_REJECT 2>/dev/null || true
iptables -w 5 -F TARASEC_DEMO5_REJECT
iptables -w 5 -A TARASEC_DEMO5_REJECT -m limit --limit 30/min --limit-burst 30 -j LOG --log-prefix 'TARASEC_HONEYPOT_REJECT: ' --log-level 5
iptables -w 5 -A TARASEC_DEMO5_REJECT -j REJECT --reject-with tcp-reset
iptables -w 5 -C INPUT -p tcp --dport "$PORT" -j TARASEC_DEMO5_REJECT 2>/dev/null ||
    iptables -w 5 -I INPUT 1 -p tcp --dport "$PORT" -j TARASEC_DEMO5_REJECT
echo "Decoy rejection target installed on TCP/$PORT. Existing forwarding, NAT and other listeners preserved."
echo 'Require existing TARASEC_ rsyslog forwarding, updated normalizer and receiver traffic collection.'
