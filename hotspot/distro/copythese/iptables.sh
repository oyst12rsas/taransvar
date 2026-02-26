#!/bin/bash
IPT="/sbin/iptables"

INTERNAL=INTERNAL_NIC
EXTERNAL=EXTERNAL_NIC

iptables -t nat -F
iptables -F
#Allow DNS
$IPT -t nat -I PREROUTING -p udp --dport 53 -m state --state NEW,ESTABLISHED -j ACCEPT
#Redirect to internal web- paying
#iptables -t nat -A PREROUTING -i $INTERNAL -p tcp -s 192.168.0.101 -j ACCEPT
$IPT -t nat -A PREROUTING -i $INTERNAL -p tcp -j DNAT --to 192.168.0.1:80
#iptables -t nat -A PREROUTING -i $INTERNAL -p tcp --dport 80 -j REDIRECT --to-port 192.168.0.1:80
#iptables -t nat -A PREROUTING -i $EXTERNAL -p tcp --dport 206.167.200.213:80 -j REDIRECT --to-port 206.167.200.206:80
#Postrouting masquerade
$IPT -t nat -A POSTROUTING -o $EXTERNAL -j MASQUERADE
#Accept all outbound forwarding
$IPT -A FORWARD -i $EXTERNAL -o $INTERNAL -m state --state RELATED,ESTABLISHED -j ACCEPT
#iptables -A FORWARD -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
$IPT -A FORWARD -i $INTERNAL -o $EXTERNAL -j ACCEPT
#Nonsense
#iptables -N LOGGING
#iptables -A OUTPUT -j LOGGING
#iptables -A LOGGING -m limit --limit 2/min -j LOG --log-prefix "IPTables-Dropped: " --log-level 4
#iptables -A LOGGING -j ACCEPT

exit 0