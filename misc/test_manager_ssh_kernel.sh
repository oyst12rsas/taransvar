#!/usr/bin/env bash
# Run inside a disposable network namespace; never use the node's real firewall.
set -euo pipefail
ipset create tarasec_app_ssh bitmap:port range 1-65535 timeout 900
iptables -N TARASEC_APP_SSH
iptables -A TARASEC_APP_SSH -s 10.100.0.150/32 -j ACCEPT
iptables -A INPUT -p tcp --dport 5822 -m set --match-set tarasec_app_ssh dst -j TARASEC_APP_SSH
iptables -C TARASEC_APP_SSH -s 10.100.0.150/32 -j ACCEPT
if iptables -C TARASEC_APP_SSH -s 10.100.0.151/32 -j ACCEPT 2>/dev/null; then exit 1; fi
ipset add tarasec_app_ssh 5822 timeout 2
ipset test tarasec_app_ssh 5822
if ipset test tarasec_app_ssh 22 2>/dev/null; then exit 1; fi
sleep 3
if ipset test tarasec_app_ssh 5822 2>/dev/null; then echo 'Kernel lease failed to expire'; exit 1; fi
echo 'Kernel port isolation and expiration without a running worker passed'
