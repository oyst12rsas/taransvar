#!/usr/bin/perl

# This script assumes:
# - no NAT for the site subnet
# - local devices behind ASA use fixed IPs in the assigned 10.<countryId>.<partnerId>.0/24
# - the VPS will route those subnets through the corresponding IPsec tunnels

use strict;
use warnings;

my $CONF = "/root/taransvar/vpn_vps.conf";

my %cfg = read_config($CONF);

my $wan_if     = required(\%cfg, "VPS_WAN_IF");
my $public_ip  = required(\%cfg, "VPS_PUBLIC_IP");
my $core_local = $cfg{CORE_LOCAL_SUBNET} // "10.0.0.0/8";

my @sites = get_sites(\%cfg);
die "No SITE<n> entries found in $CONF\n" unless @sites;

install_packages();
write_sysctl();
write_swanctl_conf($public_ip, $core_local, \@sites);
write_secrets_conf(\@sites);
write_iptables_script($wan_if, $core_local, \@sites);
enable_and_restart_services();

print "\nDone.\n";
print "Wrote:\n";
print "  /etc/sysctl.d/99-taransvar-ipsec.conf\n";
print "  /etc/swanctl/conf.d/taransvar-sites.conf\n";
print "  /etc/swanctl/conf.d/taransvar-secrets.conf\n";
print "  /root/taransvar/iptables_ipsec_vps.sh\n\n";
print "Next: apply matching Cisco ASA config for each site.\n";

sub read_config {
    my ($file) = @_;
    open(my $fh, "<", $file) or die "Cannot open $file: $!\n";
    my %c;

    while (my $line = <$fh>) {
        chomp $line;
        $line =~ s/\r$//;
        $line =~ s/^\s+|\s+$//g;
        next if $line eq '' || $line =~ /^\s*#/;

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

sub get_sites {
    my ($cfg) = @_;
    my %tmp;

    for my $k (keys %{$cfg}) {
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
            name          => $s->{NAME},
            remote_id     => $s->{REMOTE_ID},
            psk           => $s->{PSK},
            remote_subnet => $s->{REMOTE_SUBNET},
        };
    }

    return @sites;
}

sub install_packages {
    print "Installing strongSwan and dependencies...\n";
    system("apt-get update") == 0
        or die "apt-get update failed\n";

    system("DEBIAN_FRONTEND=noninteractive apt-get install -y strongswan strongswan-swanctl libcharon-extra-plugins iptables") == 0
        or die "Package install failed\n";
}

sub write_sysctl {
    my $file = "/etc/sysctl.d/99-taransvar-ipsec.conf";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";
    print $fh <<'EOF';
net.ipv4.ip_forward=1
net.ipv4.conf.all.accept_redirects=0
net.ipv4.conf.all.send_redirects=0
net.ipv4.conf.default.accept_redirects=0
net.ipv4.conf.default.send_redirects=0
EOF
    close($fh);

    system("sysctl --system") == 0
        or die "sysctl --system failed\n";
}

sub write_swanctl_conf {
    my ($public_ip, $core_local, $sites) = @_;

    my $file = "/etc/swanctl/conf.d/taransvar-sites.conf";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";

    print $fh "connections {\n";

    for my $s (@$sites) {
        my $name   = $s->{name};
        my $rid    = $s->{remote_id};
        my $rsub   = $s->{remote_subnet};

        print $fh <<"EOF";
  $name {
    version = 2
    proposals = aes256-sha256-modp2048
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
        esp_proposals = aes256-sha256-modp2048
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

sub write_secrets_conf {
    my ($sites) = @_;

    my $file = "/etc/swanctl/conf.d/taransvar-secrets.conf";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";

    print $fh "secrets {\n";

    for my $s (@$sites) {
        my $name = $s->{name};
        my $psk  = escape_dq($s->{psk});
        my $rid  = $s->{remote_id};

        print $fh <<"EOF";
  ${name}_psk {
    id-1 = $rid
    secret = "$psk"
  }

EOF
    }

    print $fh "}\n";
    close($fh);
}

sub write_iptables_script {
    my ($wan_if, $core_local, $sites) = @_;

    my $file = "/root/taransvar/iptables_ipsec_vps.sh";
    open(my $fh, ">", $file) or die "Cannot write $file: $!\n";

    print $fh <<"EOF";
#!/bin/bash
set -e

WAN_IF='$wan_if'

echo "Applying IPsec/VPS firewall rules..."

iptables -F
iptables -t nat -F
iptables -t mangle -F
iptables -X

iptables -P INPUT DROP
iptables -P FORWARD DROP
iptables -P OUTPUT ACCEPT

# Loopback
iptables -A INPUT -i lo -j ACCEPT

# MSS clamping
iptables -t mangle -A FORWARD -p tcp --tcp-flags SYN,RST SYN -j TCPMSS --clamp-mss-to-pmtu

# Established/related
iptables -A INPUT   -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
iptables -A FORWARD -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT

# SSH to VPS
iptables -A INPUT -p tcp --dport 22 -j ACCEPT

# IKE/IPsec
iptables -A INPUT -i "\$WAN_IF" -p udp --dport 500  -j ACCEPT
iptables -A INPUT -i "\$WAN_IF" -p udp --dport 4500 -j ACCEPT
iptables -A INPUT -i "\$WAN_IF" -p esp -j ACCEPT

EOF

    for my $s (@$sites) {
        my $subnet = $s->{remote_subnet};
        print $fh <<"EOF";
# Routed site subnet for $s->{name}
iptables -A FORWARD -s '$subnet' -d '$core_local' -j ACCEPT
iptables -A FORWARD -s '$core_local' -d '$subnet' -j ACCEPT

EOF
    }

    print $fh <<"EOF";
# Logging
iptables -A INPUT   -m limit --limit 5/second --limit-burst 20 -j LOG --log-prefix "IPSEC_VPS_INPUT_DROP: " --log-level 4
iptables -A FORWARD -m limit --limit 5/second --limit-burst 20 -j LOG --log-prefix "IPSEC_VPS_FORWARD_DROP: " --log-level 4

echo "Firewall applied."
iptables -L -v -n
iptables -t mangle -L -v -n
EOF

    close($fh);
    chmod 0755, $file or warn "Could not chmod +x $file: $!\n";

    my $rollback_pid;

    if (!defined $ARGV[0]) {
        my $cmd = "sh -c '( sleep 60; " .
                  "iptables -P INPUT ACCEPT; " .
                  "iptables -P FORWARD ACCEPT; " .
                  "iptables -P OUTPUT ACCEPT; " .
                  "iptables -F; " .
                  "iptables -t nat -F; " .
                  "iptables -t mangle -F " .
                  ") >/dev/null 2>&1 & echo \$!'";
        $rollback_pid = `$cmd`;
        chomp $rollback_pid;

        die "Starting rollback timer failed\n"
            unless defined $rollback_pid && $rollback_pid =~ /^\d+$/;
    }

    system($file) == 0
        or die "Applying iptables script failed\n";

    #If script didn't abort by now, we assume it's ok and abort the rollback
    if (defined $rollback_pid && $rollback_pid =~ /^\d+$/) {
        kill 'TERM', $rollback_pid;
        print "Rollback timer cancelled after successful apply.\n";
    }
}

sub enable_and_restart_services {
    system("systemctl enable strongswan-starter >/dev/null 2>&1") == 0
        or warn "Could not enable strongswan-starter\n";

    system("systemctl enable strongswan >/dev/null 2>&1") == 0
        or warn "Could not enable strongswan\n";

    system("systemctl restart strongswan") == 0
        or die "Restarting strongswan failed\n";

    system("swanctl --load-all") == 0
        or die "swanctl --load-all failed\n";

    system("swanctl --list-conns") == 0
        or warn "Could not list connections\n";
}

sub escape_dq {
    my ($s) = @_;
    $s =~ s/\\/\\\\/g;
    $s =~ s/"/\\"/g;
    return $s;
}