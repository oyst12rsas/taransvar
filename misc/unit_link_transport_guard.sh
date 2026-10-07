#!/usr/bin/env bash
set -euo pipefail
# Root-only service: keep HTTP destined for the configured NetBird address off
# every non-WireGuard interface. It never changes SSH or general forwarding.
config=/etc/tarasec/unit-link.php
readarray -t settings < <(php -r '$c=require $argv[1]; $u=parse_url($c["base_url"]); foreach([$c["gateway_id"],$c["netbird_interface"],$u["host"],$u["port"]??80,$c["base_url"]] as $v) echo $v,"\n";' "$config")
gateway_id=${settings[0]}; iface=${settings[1]}; address=${settings[2]}; port=${settings[3]}; origin=${settings[4]}
[[ $gateway_id =~ ^[a-f0-9]{32}$ && $iface =~ ^[A-Za-z0-9_.-]{1,15}$ && $address =~ ^100\.68\.[0-9]{1,3}\.[0-9]{1,3}$ && $port =~ ^[0-9]+$ ]]
wg show "$iface" >/dev/null
ip -4 -o addr show dev "$iface" | awk '{print $4}' | cut -d/ -f1 | rg -Fx "$address" >/dev/null
iptables -C INPUT -d "$address" -p tcp --dport "$port" ! -i "$iface" -j REJECT 2>/dev/null ||
  iptables -I INPUT 1 -d "$address" -p tcp --dport "$port" ! -i "$iface" -j REJECT
install -d -m 0755 /run/tarasec-unit-link
printf '%s|%s|%s\n' "$gateway_id" "$iface" "$origin" > /run/tarasec-unit-link/transport.tmp
chmod 0644 /run/tarasec-unit-link/transport.tmp
mv /run/tarasec-unit-link/transport.tmp /run/tarasec-unit-link/transport
