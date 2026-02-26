#Field found: SSH: outwards/Inside(1)
/sbin/iptables -A OUTPUT -s  -p tcp --sport 22 -m state -- NEW, ESTABLISHED, RELATED -j ACCEPT
/sbin/iptables -A INPUT -s  -p tcp --dport 22 -m state -- ESTABLISHED, RELATED -j ACCEPT

#Field found: Samba: outwards/Outside(1)
/sbin/iptables -A OUTPUT -s  -p tcp --sport 139 -m state -- NEW, ESTABLISHED, RELATED -j ACCEPT
/sbin/iptables -A INPUT -s  -p tcp --dport 139 -m state -- ESTABLISHED, RELATED -j ACCEPT
/sbin/iptables -A OUTPUT -s  -p tcp --sport 445 -m state -- NEW, ESTABLISHED, RELATED -j ACCEPT
/sbin/iptables -A INPUT -s  -p tcp --dport 445 -m state -- ESTABLISHED, RELATED -j ACCEPT
/sbin/iptables -A OUTPUT -s  -p udp --sport 137 -m state -- NEW, ESTABLISHED, RELATED -j ACCEPT
/sbin/iptables -A INPUT -s  -p udp --dport 137 -m state -- ESTABLISHED, RELATED -j ACCEPT
/sbin/iptables -A OUTPUT -s  -p udp --sport 138 -m state -- NEW, ESTABLISHED, RELATED -j ACCEPT
/sbin/iptables -A INPUT -s  -p udp --dport 138 -m state -- ESTABLISHED, RELATED -j ACCEPT

