#sudo iptables -L
sudo iptables -F INPUT
sudo iptables -F OUTPUT
sudo iptables -F FORWARD
sudo iptables -P INPUT ACCEPT
sudo iptables -P FORWARD ACCEPT
sudo iptables -P OUTPUT ACCEPT
sudo iptables -A FORWARD -i wlp0s20f3 -o enp3s0 -m state --state ESTABLISHED,RELATED -j ACCEPT
sudo iptables -A FORWARD -i enp3s0 -o wlp0s20f3 -j ACCEPT
sudo iptables -t nat -A POSTROUTING -j MASQUERADE


sudo iptables -F INPUT
sudo iptables -F OUTPUT
sudo iptables -F FORWARD
sudo iptables -P INPUT ACCEPT
sudo iptables -P FORWARD ACCEPT
sudo iptables -P OUTPUT ACCEPT
sudo iptables -t nat -A POSTROUTING -o wlp0s20f3 -j MASQUERADE
sudo iptables -A FORWARD -i wlp0s20f3 -o enp3s0 -m state --state RELATED,ESTABLISHED -j ACCEPT
sudo iptables -A FORWARD -i enp3s0 -o wlp0s20f3 -j ACCEPT

(sudo apt install iptables-persistent)
sudo netfilter-persistent save

sudo iptables -t nat -A PREROUTING -p tcp --dport 8080 -j DNAT --to-destination 192.168.50.nn:80
sudo iptables -t nat -A POSTROUTING -p tcp --dport 80 -j MASQUERADE");



#sudo iptables -t nat -A POSTROUTING -o enp3s0 -j MASQUERADE
#iptables -t nat -A PREROUTING -p tcp --dport 1000 -j NFQUEUE 

#Ping and more (no effect)
#sudo iptables -A INPUT -p icmp -j ACCEPT
#sudo iptables -A OUTPUT -p icmp -j ACCEPT





	#sudo iptables -F INPUT

	#allow loopback
	sudo iptables -A INPUT -i lo -j ACCEPT
	sudo iptables -A OUTPUT -o lo -j ACCEPT
	
	#Allow outgoing icmp connections (pings,...)"
	sudo iptables -A OUTPUT -p icmp -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT
	sudo iptables -A INPUT  -p icmp -m state --state ESTABLISHED,RELATED     -j ACCEPT

	#Allow outgoing connections to port 123 (ntp syncs)
	sudo iptables -A OUTPUT -p udp --dport 123 -m state --state NEW,ESTABLISHED -j ACCEPT
	sudo iptables -A INPUT  -p udp --sport 123 -m state --state ESTABLISHED     -j ACCEPT

	#Allowing DNS lookups (tcp, udp port 53) to Google DNS server 
	sudo iptables -A OUTPUT -p udp -d 8.8.8.8 --dport 53 -m state --state NEW,ESTABLISHED -j ACCEPT
	sudo iptables -A INPUT  -p udp -s 8.8.8.8 --sport 53 -m state --state ESTABLISHED     -j ACCEPT
	sudo iptables -A OUTPUT -p tcp -d 8.8.8.8 --dport 53 -m state --state NEW,ESTABLISHED -j ACCEPT
	sudo iptables -A INPUT  -p tcp -s 8.8.8.8 --sport 53 -m state --state ESTABLISHED     -j ACCEPT

	#Allow connection to '$ip' on port 21"
	sudo iptables -A OUTPUT -p tcp  --dport 21  -m state --state NEW,ESTABLISHED -j ACCEPT
	sudo iptables -A INPUT  -p tcp  --sport 21  -m state --state ESTABLISHED     -j ACCEPT

	#Allow connection to '$ip' on port 80"
	sudo iptables -A OUTPUT -p tcp  --dport 80  -m state --state NEW,ESTABLISHED -j ACCEPT
	sudo iptables -A INPUT  -p tcp  --sport 80  -m state --state ESTABLISHED     -j ACCEPT

	#Allow connection to '$ip' on port 443"
	sudo iptables -A OUTPUT -p tcp  --dport 443  -m state --state NEW,ESTABLISHED -j ACCEPT
	sudo iptables -A INPUT  -p tcp  --sport 443  -m state --state ESTABLISHED     -j ACCEPT

	sudo iptables -A INPUT -p udp --dport 53 -j ACCEPT
	#sudo iptables -A INPUT -p udp --dport 53 -m conntrack --cstate NEW -j ACCEPT
	sudo iptables -A INPUT -p udp --dport 53 -m conntrack --ctstate NEW -j ACCEPT
	sudo iptables -A INPUT -p udp -m state --state NEW --dport 53 -j ACCEPT
	
	sudo iptables -I INPUT -i wlp0s20f3 -p tcp --dport 80 -m comment --comment "# web traffic #" -j ACCEPT
	sudo iptables -A INPUT -i wlp0s20f3 -m limit --limit 100/min -j LOG --log-prefix "iptables dropped " --log-level 7
	sudo iptables -A INPUT -i wlp0s20f3 -j DROP	
	

