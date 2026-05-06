#!/usr/bin/perl
use strict;
use warnings;

# ============================================================
# Taransvar dual-mode IPsec setup script
#
# Modes:
#   ROLE=vps   - configure VPS hub accepting one or more SITE<n> peers
#   ROLE=peer  - configure one endpoint/peer connecting to the VPS
#
# Default config path:
#   /root/taransvar/vpn.conf
#
# Usage:
#   sudo perl vpn_dual.pl
#   sudo perl vpn_dual.pl /path/to/config.conf
#
# strongSwan stack:
#   charon-systemd + swanctl
#
# MTU/offload tuning:
#   SAFE_MTU=1375                # default if omitted
#   MTU_INTERFACES=enp1s0,ipsec0 # optional, comma/space separated
#   DISABLE_OFFLOAD=yes          # default yes
# ============================================================

my $CONF = $ARGV[0] || "/root/taransvar/vpn.conf";

my %cfg = read_config($CONF);
my $role = lc(required(\%cfg, "ROLE"));

if ($role eq 'vps') {
    run_vps_mode(\%cfg);
}
elsif ($role eq 'peer') {
    run_peer_mode(\%cfg);
}
else {
    die "ROLE must be either 'vps' or 'peer' in $CONF\n";
}

print "\nDone.\n";

# ------------------------------------------------------------
# VPS MODE
# ------------------------------------------------------------

sub run_vps_mode {
    my ($cfg) = @_;

    my $wan_if     = required($cfg, "VPS_WAN_IF");
    my $public_ip  = required($cfg, "VPS_PUBLIC_IP");
    my $core_local = $cfg->{CORE_LOCAL_SUBNET} // "10.47.0.1/32";
    my $vps_vpn_ip = $cfg->{VPS_VPN_IP} // "";
    my @sites      = get_sites($cfg);

    die "No SITE<n> entries found in $CONF\n" unless @sites;

    install_packages();
    write_sysctl(1);
    write_vps_swanctl_conf($public_ip, $core_local, \@sites);
    write_vps_secrets_conf($public_ip, \@sites);
    assign_loopback_ip($vps_vpn_ip, "VPS_VPN_IP") if $vps_vpn_ip ne '';
    write_loopback_service($vps_vpn_ip, '') if $vps_vpn_ip ne '';
    write_vps_iptables_script($wan_if, $core_local, $vps_vpn_ip, \@sites);
    enable_and_restart_services();

    print "\nVPS mode configured.\n";
    print "Wrote /etc/swanctl/conf.d/taransvar-vps.conf\n";
    print "Wrote /etc/swanctl/conf.d/taransvar-vps-secrets.conf\n";
    print "Wrote /root/taransvar/iptables_ipsec_vps.sh\n";
}

sub get_sites {
    my ($cfg) = @_;
    my %tmp;

    for my $k (keys %$cfg) {
        if ($k =~ /^SITE(\d+)_(NAME|REMOTE_ID|PSK|REMOTE_SUBNET)$/) {
            $tmp{$1}{$2} = $cfg->{$k};
        }
    }

    my @sites;
    for my $n (sort { $a <=> $b } keys %tmp) {
        my $s = $tmp{$n};
        for my $req (qw(NAME REMOTE_ID PSK REMOTE_SUBNET)) {
            die "SITE$n missing $req\n" unless exists $s->{$req} && $s->{$req} ne '';
        }
        push @sites, {
            num           => $n,
            name          => safe_name($s->{NAME}),
            remote_id     => $s->{REMOTE_ID},
            psk           => $s->{PSK},
            remote_subnet => $s->{REMOTE_SUBNET},
        };
    }
    return @sites;
}

sub write_vps_swanctl_conf {
    my ($public_ip, $core_local, $sites) = @_;
    my $file = "/etc/swanctl/conf.d/taransvar-vps.conf";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";

    print $fh "connections {\n";
    for my $s (@$sites) {
        my $name = $s->{name};
        my $rid  = $s->{remote_id};
        my $rsub = $s->{remote_subnet};
        print $fh <<"EOF";
  $name {
    version = 2
    proposals = aes256-sha256-modp2048,aes128-sha256-modp2048,aes256-sha1-modp2048
    local_addrs = $public_ip

    local {
      auth = psk
      id = $public_ip
    }
    remote {
      auth = psk
      id = $rid
    }

    children {
      ${name}_child {
        local_ts = $core_local
        remote_ts = $rsub
        esp_proposals = aes256-sha256-modp2048,aes128-sha256-modp2048,aes256-sha1-modp2048
        start_action = trap
        dpd_action = restart
      }
    }

    encap = yes
    mobike = no
    dpd_delay = 30s
    rekey_time = 1h
  }

EOF
    }
    print $fh "}\n";
    close($fh);
}

sub write_vps_secrets_conf {
    my ($public_ip, $sites) = @_;
    my $file = "/etc/swanctl/conf.d/taransvar-vps-secrets.conf";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";

    print $fh "secrets {\n";
    for my $s (@$sites) {
        my $name = $s->{name};
        my $rid  = $s->{remote_id};
        my $psk  = escape_dq($s->{psk});
        print $fh <<"EOF";
  ike-$name {
    id-1 = $public_ip
    id-2 = $rid
    secret = "$psk"
  }

EOF
    }
    print $fh "}\n";
    close($fh);
}

sub write_vps_iptables_script {
    my ($wan_if, $core_local, $vps_vpn_ip, $sites) = @_;
    ensure_dir("/root/taransvar");
    my $file = "/root/taransvar/iptables_ipsec_vps.sh";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";

    print $fh <<"EOF";
#!/bin/bash
set -e

WAN_IF='$wan_if'
VPS_VPN_IP='$vps_vpn_ip'
CORE_LOCAL='$core_local'

echo "Applying Taransvar VPS IPsec firewall rules..."

iptables -F
iptables -t nat -F
iptables -t mangle -F
iptables -X

iptables -P INPUT DROP
iptables -P FORWARD DROP
iptables -P OUTPUT ACCEPT

iptables -A INPUT -i lo -j ACCEPT
iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -A FORWARD -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu

# Management + IPsec
iptables -A INPUT -p tcp --dport 22 -j ACCEPT
iptables -A INPUT -i "\$WAN_IF" -p udp --dport 500  -j ACCEPT
iptables -A INPUT -i "\$WAN_IF" -p udp --dport 4500 -j ACCEPT
iptables -A INPUT -i "\$WAN_IF" -p esp -j ACCEPT

EOF

    if ($vps_vpn_ip ne '') {
        print $fh <<"EOF";
# VPS overlay IP
iptables -A INPUT -d "\$VPS_VPN_IP" -p icmp -j ACCEPT
iptables -A INPUT -d "\$VPS_VPN_IP" -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

EOF
    }

    for my $s (@$sites) {
        my $subnet = $s->{remote_subnet};
        print $fh <<"EOF";
# Routed site subnet for $s->{name}
iptables -A FORWARD -s '$subnet' -d '$core_local' -j ACCEPT
iptables -A FORWARD -s '$core_local' -d '$subnet' -j ACCEPT

EOF
    }

    print $fh <<"EOF";
iptables -A INPUT   -m limit --limit 5/second --limit-burst 20 -j LOG --log-prefix "IPSEC_VPS_INPUT_DROP: " --log-level 4
iptables -A FORWARD -m limit --limit 5/second --limit-burst 20 -j LOG --log-prefix "IPSEC_VPS_FORWARD_DROP: " --log-level 4

echo "Firewall applied."
EOF
    close($fh);
    chmod 0755, $file;

    apply_with_rollback($file);
}

# ------------------------------------------------------------
# PEER MODE
# ------------------------------------------------------------

sub run_peer_mode {
    my ($cfg) = @_;

    my $peer_name     = safe_name(required($cfg, "PEER_NAME"));
    my $peer_id       = required($cfg, "PEER_ID");
    my $peer_vpn_ip   = $cfg->{PEER_VPN_IP} // "";
    my $vps_public_ip = required($cfg, "VPS_PUBLIC_IP");
    my $vps_id        = $cfg->{VPS_ID} // $vps_public_ip;
    my $psk           = required($cfg, "PSK");
    my $local_subnet  = required($cfg, "LOCAL_SUBNET");
    my $remote_subnet = required($cfg, "REMOTE_SUBNET");
    my $wan_if        = $cfg->{PEER_WAN_IF} // default_interface();
    my $enable_fwd    = yes_value($cfg->{ENABLE_FORWARDING} // "no");
    my $apply_fw      = yes_value($cfg->{APPLY_FIREWALL} // "no");
    my $initiate      = yes_value($cfg->{INITIATE_TUNNEL} // "yes");
    my $safe_mtu      = valid_mtu($cfg->{SAFE_MTU} // 1375);
    my @mtu_ifaces    = mtu_interfaces($cfg->{MTU_INTERFACES} // $wan_if);
    my $offload       = yes_value($cfg->{DISABLE_OFFLOAD} // "yes");

    install_packages();
    write_sysctl($enable_fwd);
    write_mtu_service($safe_mtu, \@mtu_ifaces, $offload);

    if ($peer_vpn_ip ne '') {
        assign_loopback_ip($peer_vpn_ip, "PEER_VPN_IP");
        write_loopback_service('', $peer_vpn_ip);
    }

    write_peer_swanctl_conf($peer_name, $peer_id, $vps_public_ip, $vps_id, $local_subnet, $remote_subnet);
    write_peer_secrets_conf($peer_name, $peer_id, $vps_id, $psk);
    write_peer_iptables_script($wan_if, $local_subnet, $remote_subnet) if $apply_fw;
    enable_and_restart_services();

    if ($initiate) {
        my $child = "${peer_name}_child";
        print "Initiating CHILD_SA $child...\n";
        system("swanctl --initiate --child '$child'");
    }

    print "\nPeer mode configured.\n";
    print "Wrote /etc/swanctl/conf.d/taransvar-peer.conf\n";
    print "Wrote /etc/swanctl/conf.d/taransvar-peer-secrets.conf\n";
    print "Assigned $peer_vpn_ip to lo and made it persistent\n" if $peer_vpn_ip ne '';
    print "Test with: ping -I $peer_vpn_ip <remote-vpn-ip>\n" if $peer_vpn_ip ne '';
}

sub write_peer_swanctl_conf {
    my ($peer_name, $peer_id, $vps_public_ip, $vps_id, $local_subnet, $remote_subnet) = @_;
    my $file = "/etc/swanctl/conf.d/taransvar-peer.conf";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";

    print $fh <<"EOF";
connections {
  $peer_name {
    version = 2
    remote_addrs = $vps_public_ip
    proposals = aes256-sha256-modp2048,aes128-sha256-modp2048,aes256-sha1-modp2048

    local {
      auth = psk
      id = $peer_id
    }
    remote {
      auth = psk
      id = $vps_id
    }

    children {
      ${peer_name}_child {
        local_ts = $local_subnet
        remote_ts = $remote_subnet
        esp_proposals = aes256-sha256-modp2048,aes128-sha256-modp2048,aes256-sha1-modp2048
        start_action = start
        dpd_action = restart
      }
    }

    encap = yes
    mobike = no
    dpd_delay = 30s
    rekey_time = 1h
  }
}
EOF
    close($fh);
}

sub write_peer_secrets_conf {
    my ($peer_name, $peer_id, $vps_id, $psk) = @_;
    my $file = "/etc/swanctl/conf.d/taransvar-peer-secrets.conf";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";

    $psk = escape_dq($psk);
    print $fh <<"EOF";
secrets {
  ike-$peer_name {
    id-1 = $peer_id
    id-2 = $vps_id
    secret = "$psk"
  }
}
EOF
    close($fh);
}

sub write_peer_iptables_script {
    my ($wan_if, $local_subnet, $remote_subnet) = @_;
    ensure_dir("/root/taransvar");
    my $file = "/root/taransvar/iptables_ipsec_peer.sh";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";

    print $fh <<"EOF";
#!/bin/bash
set -e

echo "Applying Taransvar peer IPsec firewall rules..."

# Conservative: do not flush everything on a desktop/peer by default.
# Only allow IKE/NAT-T inbound and forwarding between selectors if forwarding is enabled.
iptables -C INPUT -p udp --dport 500 -j ACCEPT 2>/dev/null || iptables -A INPUT -p udp --dport 500 -j ACCEPT
iptables -C INPUT -p udp --dport 4500 -j ACCEPT 2>/dev/null || iptables -A INPUT -p udp --dport 4500 -j ACCEPT
iptables -C INPUT -p esp -j ACCEPT 2>/dev/null || iptables -A INPUT -p esp -j ACCEPT
iptables -C FORWARD -s '$local_subnet' -d '$remote_subnet' -j ACCEPT 2>/dev/null || iptables -A FORWARD -s '$local_subnet' -d '$remote_subnet' -j ACCEPT
iptables -C FORWARD -s '$remote_subnet' -d '$local_subnet' -j ACCEPT 2>/dev/null || iptables -A FORWARD -s '$remote_subnet' -d '$local_subnet' -j ACCEPT
iptables -t mangle -C FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu 2>/dev/null || iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu

echo "Peer firewall applied."
EOF
    close($fh);
    chmod 0755, $file;
    system($file) == 0 or die "Applying peer iptables failed\n";
}

# ------------------------------------------------------------
# Shared helpers
# ------------------------------------------------------------

sub read_config {
    my ($file) = @_;
    open(my $fh, "<", $file) or die "Cannot open $file: $!\n";
    my %c;
    while (my $line = <$fh>) {
        chomp $line;
        $line =~ s/\r$//;
        $line =~ s/^\s+|\s+$//g;
        next if $line eq '' || $line =~ /^#/;
        my ($k, $v) = split(/\s*=\s*/, $line, 2);
        next unless defined $k && defined $v;
        $k =~ s/^\s+|\s+$//g;
        $v =~ s/^\s+|\s+$//g;
        $c{$k} = $v;
    }
    close($fh);
    return %c;
}

sub required {
    my ($cfg, $k) = @_;
    die "Missing required key: $k\n" unless exists $cfg->{$k} && $cfg->{$k} ne '';
    return $cfg->{$k};
}

sub install_packages {
    print "Installing strongSwan swanctl/charon-systemd stack...\n";
    system("apt-get update") == 0 or die "apt-get update failed\n";
    system("DEBIAN_FRONTEND=noninteractive apt-get install -y charon-systemd strongswan-swanctl libcharon-extra-plugins iptables ethtool") == 0
        or die "Package install failed\n";
    system("DEBIAN_FRONTEND=noninteractive apt-get remove -y strongswan-starter strongswan strongswan-charon >/dev/null 2>&1");
}

sub write_sysctl {
    my ($enable_forwarding) = @_;
    my $ip_forward = $enable_forwarding ? 1 : 0;
    my $file = "/etc/sysctl.d/99-taransvar-ipsec.conf";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";
    print $fh <<"EOF";
net.ipv4.ip_forward=$ip_forward
net.ipv4.conf.all.accept_redirects=0
net.ipv4.conf.all.send_redirects=0
net.ipv4.conf.default.accept_redirects=0
net.ipv4.conf.default.send_redirects=0
EOF
    close($fh);
    system("sysctl --system") == 0 or die "sysctl --system failed\n";
}

sub assign_loopback_ip {
    my ($ip, $label) = @_;
    return unless $ip;
    print "Ensuring $label $ip/32 exists on lo...\n";
    system("sh -c \"ip -4 addr show dev lo | grep -q '\\b$ip/' || ip addr add $ip/32 dev lo\"") == 0
        or die "Failed to assign $label $ip to lo\n";
}

sub write_loopback_service {
    my ($vps_ip, $peer_ip) = @_;
    my @ips = grep { $_ ne '' } ($vps_ip, $peer_ip);
    return unless @ips;

    my $script = "/usr/local/sbin/taransvar-loopback-ips.sh";
    open(my $sh, ">", $script) or die "Cannot write $script: $!\n";
    print $sh "#!/bin/bash\nset -e\n";
    for my $ip (@ips) {
        print $sh "ip -4 addr show dev lo | grep -q '\\b$ip/' || ip addr add $ip/32 dev lo\n";
    }
    close($sh);
    chmod 0755, $script;

    my $svc = "/etc/systemd/system/taransvar-loopback-ips.service";
    open(my $fh, ">", $svc) or die "Cannot write $svc: $!\n";
    print $fh <<"EOF";
[Unit]
Description=Taransvar loopback VPN IPs
After=network-pre.target
Before=strongswan.service

[Service]
Type=oneshot
ExecStart=$script
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
    close($fh);

    system("systemctl daemon-reload") == 0 or die "systemctl daemon-reload failed\n";
    system("systemctl enable --now taransvar-loopback-ips.service") == 0
        or die "Failed to enable/start taransvar-loopback-ips.service\n";
}


sub write_mtu_service {
    my ($mtu, $ifaces, $disable_offload) = @_;
    return unless @$ifaces;

    ensure_dir("/usr/local/sbin");
    my $script = "/usr/local/sbin/taransvar-mtu-tune.sh";
    open(my $sh, ">", $script) or die "Cannot write $script: $!\n";

    print $sh "#!/bin/bash\nset -e\n\n";
    print $sh "SAFE_MTU='$mtu'\n";
    print $sh "DISABLE_OFFLOAD='" . ($disable_offload ? "yes" : "no") . "'\n\n";
    print $sh "echo \"Applying Taransvar MTU/offload tuning, MTU=\$SAFE_MTU\"\n";

    for my $iface (@$ifaces) {
        next unless defined $iface && $iface ne '';
        my $q = shell_quote($iface);
        print $sh "\nIFACE=$q\n";
        print $sh "if ip link show dev \"\$IFACE\" >/dev/null 2>&1; then\n";
        print $sh "  echo \" - setting \$IFACE mtu \$SAFE_MTU\"\n";
        print $sh "  ip link set dev \"\$IFACE\" mtu \"\$SAFE_MTU\" || true\n";
        print $sh "  if [ \"\$DISABLE_OFFLOAD\" = \"yes\" ] && command -v ethtool >/dev/null 2>&1; then\n";
        print $sh "    echo \" - disabling tso/gso/gro on \$IFACE\"\n";
        print $sh "    ethtool -K \"\$IFACE\" tso off gso off gro off 2>/dev/null || true\n";
        print $sh "  fi\n";
        print $sh "else\n";
        print $sh "  echo \" - skipping missing interface \$IFACE\"\n";
        print $sh "fi\n";
    }
    close($sh);
    chmod 0755, $script;

    my $svc = "/etc/systemd/system/taransvar-mtu-tune.service";
    open(my $fh, ">", $svc) or die "Cannot write $svc: $!\n";
    print $fh <<"EOF";
[Unit]
Description=Taransvar MTU and NIC offload tuning
After=network-online.target
Wants=network-online.target
Before=strongswan.service

[Service]
Type=oneshot
ExecStart=$script
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
    close($fh);

    system("systemctl daemon-reload") == 0 or die "systemctl daemon-reload failed\n";
    system("systemctl enable --now taransvar-mtu-tune.service") == 0
        or die "Failed to enable/start taransvar-mtu-tune.service\n";
}

sub mtu_interfaces {
    my ($raw) = @_;
    $raw //= '';
    my @out;
    my %seen;
    for my $iface (split(/[\s,]+/, $raw)) {
        next if !defined $iface || $iface eq '';
        $iface =~ s/^\s+|\s+$//g;
        next if $iface eq '' || $seen{$iface}++;
        push @out, $iface;
    }
    return @out;
}

sub valid_mtu {
    my ($mtu) = @_;
    $mtu //= 1375;
    die "SAFE_MTU must be numeric\n" unless $mtu =~ /^\d+$/;
    die "SAFE_MTU too low/high; use 576..1500\n" unless $mtu >= 576 && $mtu <= 1500;
    return $mtu;
}

sub shell_quote {
    my ($s) = @_;
    $s =~ s/'/'"'"'/g;
    return "'$s'";
}

sub enable_and_restart_services {
    system("systemctl disable strongswan-starter >/dev/null 2>&1");
    system("systemctl stop strongswan-starter >/dev/null 2>&1");
    system("systemctl enable strongswan >/dev/null 2>&1") == 0
        or warn "Could not enable strongswan\n";
    system("systemctl restart strongswan") == 0
        or die "Restarting strongswan failed\n";
    system("swanctl --load-all") == 0
        or die "swanctl --load-all failed\n";
    system("swanctl --list-conns") == 0
        or warn "Could not list connections\n";
}

sub apply_with_rollback {
    my ($file) = @_;
    my $cmd = "sh -c '( sleep 60; " .
              "iptables -P INPUT ACCEPT; " .
              "iptables -P FORWARD ACCEPT; " .
              "iptables -P OUTPUT ACCEPT; " .
              "iptables -F; " .
              "iptables -t nat -F; " .
              "iptables -t mangle -F " .
              ") >/dev/null 2>&1 & echo \\$!'";
    my $rollback_pid = `$cmd`;
    chomp $rollback_pid;
    die "Starting rollback timer failed\n" unless defined $rollback_pid && $rollback_pid =~ /^\d+$/;

    system($file) == 0 or die "Applying iptables script failed\n";
    kill 'TERM', $rollback_pid;
    print "Rollback timer cancelled after successful apply.\n";
}

sub default_interface {
    my $out = `ip route show default 2>/dev/null | awk '/default/ {print \$5; exit}'`;
    chomp $out;
    return $out || "";
}

sub ensure_dir {
    my ($dir) = @_;
    return if -d $dir;
    mkdir $dir or die "Failed to create $dir: $!\n";
}

sub yes_value {
    my ($v) = @_;
    return 1 if defined $v && $v =~ /^(1|yes|true|on)$/i;
    return 0;
}

sub safe_name {
    my ($s) = @_;
    die "Empty name\n" unless defined $s && $s ne '';
    $s =~ s/[^A-Za-z0-9_.-]/_/g;
    return $s;
}

sub escape_dq {
    my ($s) = @_;
    $s =~ s/\\/\\\\/g;
    $s =~ s/"/\\"/g;
    return $s;
}
