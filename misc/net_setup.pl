#!/usr/bin/perl
#net_setup.pl
use strict;
use warnings;
use FindBin;

my $conf_file = "/root/taransvar/net_setup.conf";
my $out_file  = "/root/taransvar/iptables.sh";

if (!-e $conf_file) {
    print "\nYou need to copy taransvar/misc/net_setup.conf to /root/taransvar and apply your personal setup before running this script.\n\n";
    print "sudo cp net_setup.conf /root/taransvar\n";
    print "sudo nano /root/taransvar/net_setup.conf\n\n";
    print "For now, it's writing a bash file for setting up iptables, but NOT running it.\n";
    print "To run it: sudo bash $out_file\n";
    exit;
}

my %cfg = read_config($conf_file);
validate_config(\%cfg);

# NetBird is TaraSec's management plane. Do not accidentally make wt0 the
# normal WAN or hotspot/client interface simply because it has connectivity.
for my $role (qw(LAN_IF WAN_IF)) {
    if (($cfg{$role} // '') =~ /^wt\d+$/) {
        die "$role=$cfg{$role} looks like a NetBird interface. wt* is reserved for TaraSec management; choose the physical WAN/client interface instead.\n";
    }
}

my $script = build_iptables_script(\%cfg);

open(my $out, ">", $out_file) or die "Cannot write $out_file: $!";
print $out $script;
close($out);
chmod 0755, $out_file or warn "Could not chmod +x $out_file: $!";

print "Generated $out_file\nTo run it: sudo bash $out_file\n\n";

sub read_config {
    my ($file) = @_;
    my %c;
    open(my $fh, "<", $file) or die "Cannot open $file: $!";
    while (my $line = <$fh>) {
        chomp $line;
        $line =~ s/\r$//;
        $line =~ s/^\s+|\s+$//g;
        next if $line eq '';
        next if $line =~ /^\s*#/;
        my ($k, $v) = split(/\s*=\s*/, $line, 2);
        next unless defined $k && defined $v;
        $k =~ s/^\s+|\s+$//g;
        $v =~ s/^\s+|\s+$//g;
        $c{$k} = $v;
    }
    close($fh);
    return %c;
}

sub validate_config {
    my ($c) = @_;
    for my $required (qw(NAME LAN_IF WAN_IF LAN_NET LAN_IP WAN_NET WAN_GW ALLOW_SSH SSH_PORT ALLOW_DHCP ALLOW_DNS ENABLE_NAT ENABLE_FORWARD_LAN_TO_WAN LOG_DROPS EXTRA_FORWARD_SRC EXTRA_FORWARD_IF)) {
        die "Missing required config key: $required\n" unless exists $c->{$required};
    }
    for my $key (grep { /^PORT_FORWARD\d+$/ } keys %{$c}) { parse_port_forward($c->{$key}); }
    for my $key (grep { /^OPEN_INCOMING_PORT\d+$/ } keys %{$c}) { parse_open_incoming_port($c->{$key}); }
}

sub build_iptables_script {
    my ($c) = @_;
    my $name                    = shell_quote($c->{NAME});
    my $lan_if                  = shell_quote($c->{LAN_IF});
    my $wan_if                  = shell_quote($c->{WAN_IF});
    my $lan_net                 = ensure_cidr_24(shell_quote($c->{LAN_NET}));
    my $lan_ip                  = shell_quote($c->{LAN_IP});
    my $wan_net                 = shell_quote($c->{WAN_NET});
    my $wan_gw                  = shell_quote($c->{WAN_GW});
    my $allow_ssh               = is_true($c->{ALLOW_SSH});
    my $ssh_port                = int($c->{SSH_PORT});
    my $allow_dhcp              = is_true($c->{ALLOW_DHCP});
    my $allow_dns               = is_true($c->{ALLOW_DNS});
    my $enable_nat              = is_true($c->{ENABLE_NAT});
    my $enable_fwd_lan_to_wan   = is_true($c->{ENABLE_FORWARD_LAN_TO_WAN});
    my $log_drops               = is_true($c->{LOG_DROPS});
    my $extra_forward_src       = shell_quote($c->{EXTRA_FORWARD_SRC});
    my $extra_forward_if        = shell_quote($c->{EXTRA_FORWARD_IF});

    my $szLanOnWan = '';
    if ($c->{LAN_IF} ne '' && $c->{LAN_NET} ne '') {
        $szLanOnWan = "# Allow all traffic from LAN subnet on LAN interface\niptables -A INPUT -i \$LAN_IF -s \$LAN_NET -j ACCEPT\n";
    } else {
        $allow_dhcp = 0;
        $enable_fwd_lan_to_wan = 0;
    }

    my $s = <<"EOF";
#!/bin/bash
set -e
NAME=$name
LAN_IF=$lan_if
WAN_IF=$wan_if
LAN_NET=$lan_net
LAN_IP=$lan_ip
WAN_NET=$wan_net
WAN_GW=$wan_gw
SSH_PORT=$ssh_port
EXTRA_FORWARD_SRC=$extra_forward_src
EXTRA_FORWARD_IF=$extra_forward_if

get_if_ip() {
    local ifname="\$1"
    ip -4 -o addr show dev "\$ifname" | awk '{print \$4}' | head -n1 | cut -d/ -f1
}

echo "Applying iptables rules for \$NAME..."
echo "LAN_IF=\$LAN_IF  WAN_IF=\$WAN_IF"
echo "LAN_NET=\$LAN_NET  LAN_IP=\$LAN_IP"
echo "WAN_NET=\$WAN_NET  WAN_GW=\$WAN_GW"
sysctl -w net.ipv4.ip_forward=1
iptables -F
iptables -X
iptables -t nat -F
iptables -t nat -X
iptables -t mangle -F
iptables -t mangle -X
iptables -t raw -F
iptables -t raw -X
iptables -P INPUT DROP
iptables -P FORWARD DROP
iptables -P OUTPUT ACCEPT
iptables -A INPUT -i lo -j ACCEPT
iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT
$szLanOnWan
EOF

    if ($allow_ssh) {
        $s .= "# Allow SSH\niptables -A INPUT -p tcp --dport \$SSH_PORT -j ACCEPT\n\n";
    }
    if ($allow_dhcp) {
        $s .= "# Allow DHCP server/client traffic on LAN side\niptables -A INPUT -i \$LAN_IF -p udp --sport 68 --dport 67 -j ACCEPT\niptables -A INPUT -i \$LAN_IF -p udp --sport 67 --dport 68 -j ACCEPT\n\n";
    }
    if ($allow_dns) {
        $s .= "# Allow DNS requests to this box\niptables -A INPUT -p udp --dport 53 -j ACCEPT\niptables -A INPUT -p tcp --dport 53 -j ACCEPT\n\n";
    }
    $s .= "iptables -A INPUT -p icmp --icmp-type echo-request -j ACCEPT\n\n";

    my @open_keys = sort { key_index($a, 'OPEN_INCOMING_PORT') <=> key_index($b, 'OPEN_INCOMING_PORT') } grep { /^OPEN_INCOMING_PORT\d+$/ } keys %{$c};
    if (@open_keys) {
        $s .= "# Additional incoming ports\n";
        for my $key (@open_keys) {
            my $oip = parse_open_incoming_port($c->{$key});
            my $ifname = shell_quote($oip->{ifname});
            my $proto  = lc($oip->{proto});
            for my $port (@{$oip->{ports}}) {
                if ($port =~ /^(\d+)-(\d+)$/) {
                    my $range = "$1:$2";
                    $s .= "iptables -A INPUT -i $ifname -p tcp --dport $range -j ACCEPT\n" if $proto eq 'tcp' || $proto eq 'both';
                    $s .= "iptables -A INPUT -i $ifname -p udp --dport $range -j ACCEPT\n" if $proto eq 'udp' || $proto eq 'both';
                } else {
                    my $p = int($port);
                    $s .= "iptables -A INPUT -i $ifname -p tcp --dport $p -j ACCEPT\n" if $proto eq 'tcp' || $proto eq 'both';
                    $s .= "iptables -A INPUT -i $ifname -p udp --dport $p -j ACCEPT\n" if $proto eq 'udp' || $proto eq 'both';
                }
            }
            $s .= "\n";
        }
    }

    $s .= "iptables -A FORWARD -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT\n";
    if ($enable_fwd_lan_to_wan) {
        $s .= "iptables -A FORWARD -i \$LAN_IF -s \$LAN_NET -o \$WAN_IF -j ACCEPT\n";
    }
    $s .= "iptables -A FORWARD -i \$EXTRA_FORWARD_IF -s \$EXTRA_FORWARD_SRC -j ACCEPT\n";
    $s .= "iptables -A FORWARD -o \$EXTRA_FORWARD_IF -d \$EXTRA_FORWARD_SRC -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT\n\n";

    if ($enable_nat) {
        $s .= "iptables -t nat -A POSTROUTING -s \$LAN_NET -o \$WAN_IF -j MASQUERADE\n";
        $s .= "iptables -t nat -A POSTROUTING -s \$EXTRA_FORWARD_SRC -o \$WAN_IF -j MASQUERADE\n\n";
    }

    my @pf_keys = sort { key_index($a, 'PORT_FORWARD') <=> key_index($b, 'PORT_FORWARD') } grep { /^PORT_FORWARD\d+$/ } keys %{$c};
    for my $key (@pf_keys) {
        my $pf = parse_port_forward($c->{$key});
        my $in_if = shell_quote($pf->{incoming_if});
        my $out_if = shell_quote($pf->{outgoing_if});
        my $pub = int($pf->{public_port});
        my $tip = shell_quote($pf->{target_ip});
        my $tp = int($pf->{target_port});
        $s .= "iptables -t nat -A PREROUTING -i $in_if -p tcp --dport $pub -j DNAT --to-destination $tip:$tp\n";
        $s .= "iptables -A FORWARD -i $in_if -o $out_if -p tcp -d $tip --dport $tp -j ACCEPT\n";
    }

    if ($log_drops) {
        $s .= "iptables -A INPUT -m limit --limit 10/second --limit-burst 20 -j LOG --log-prefix 'TARASEC_INPUT_DROP ' --log-level 6\n";
        $s .= "iptables -A FORWARD -m limit --limit 10/second --limit-burst 20 -j LOG --log-prefix 'TARASEC_FORWARD_DROP ' --log-level 6\n";
    }
    return $s;
}

sub shell_quote {
    my ($v) = @_;
    $v //= '';
    $v =~ s/'/'"'"'/g;
    return "'$v'";
}
sub is_true { my ($v) = @_; return defined($v) && $v =~ /^(?:1|yes|true|on)$/i ? 1 : 0; }
sub ensure_cidr_24 { my ($v) = @_; $v =~ s/^'(.*)'$/$1/; $v .= '/24' if $v ne '' && $v !~ m{/\d+$}; return shell_quote($v); }
sub key_index { my ($key, $prefix) = @_; return $key =~ /^\Q$prefix\E(\d+)$/ ? $1 : 0; }
sub parse_port_forward {
    my ($v) = @_;
    die "Invalid PORT_FORWARD: $v\n" unless $v =~ /^([^:]+):(\d+),([^,]+),([^:]+):(\d+)$/;
    return { incoming_if=>$1, public_port=>$2, outgoing_if=>$3, target_ip=>$4, target_port=>$5 };
}
sub parse_open_incoming_port {
    my ($v) = @_;
    my @p = split /,/, $v;
    die "Invalid OPEN_INCOMING_PORT: $v\n" unless @p >= 3;
    my ($ifname, $proto, @ports) = @p;
    die "Invalid protocol in OPEN_INCOMING_PORT: $proto\n" unless $proto =~ /^(?:tcp|udp|both)$/i;
    for my $port (@ports) { die "Invalid port/range: $port\n" unless $port =~ /^\d+(?:-\d+)?$/; }
    return { ifname=>$ifname, proto=>$proto, ports=>\@ports };
}
