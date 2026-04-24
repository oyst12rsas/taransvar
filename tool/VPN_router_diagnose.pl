#!/usr/bin/perl
use strict;
use warnings;

# VPN_router_diagnose.pl
#
# Reads configured peers from /etc/swanctl/conf.d/*.conf
# Compares them with active SAs from swanctl --list-sas
# Uses a small static test-target map for exact overlay IPs / ports
#
# Adjust only these few values for this node:

my $NODE_NAME      = "VPS";
my $OVERLAY_LOCAL  = "10.47.0.1";
my $CONF_GLOB      = "/etc/swanctl/conf.d/*.conf";

# Optional exact test IP / ports per configured connection name.
# If test_ip is omitted, the script will try to derive a gateway-like IP
# from remote_ts (e.g. 10.47.1.0/24 -> 10.47.1.1).
my %TEST_TARGETS = (
    'TORSAS_ROUTERVM' => {
        test_ip => '10.47.1.1',
        ports   => [80, 443],
    },
    'TORSAS_COWRIE' => {
        test_ip => '10.47.0.2',
        ports   => [2222],
    },
);

print_banner();

my %configured = parse_swanctl_conf($CONF_GLOB);
my ($sas_text, %active) = get_active_sas();

section("Node");
print "Node name     : $NODE_NAME\n";
print "Overlay local : $OVERLAY_LOCAL\n";
print "Config glob   : $CONF_GLOB\n";

section("Interfaces");
run("ip -br a");

section("Routes");
run("ip route");
run("ip rule");
run("ip route show table 220 2>/dev/null || true");

section("strongSwan active SAs");
print $sas_text || "(no output)\n";

section("Configured peers summary");
for my $name (sort keys %configured) {
    my $cfg = $configured{$name};
    my $status = exists $active{$name} ? "ACTIVE" : "INACTIVE";

    print "\n[$status] $name\n";
    print "  peer_id   : " . ($cfg->{peer_id}   // '') . "\n";
    print "  child     : " . ($cfg->{child}     // '') . "\n";
    print "  remote_ts : " . ($cfg->{remote_ts} // '') . "\n";
    print "  local_ts  : " . ($cfg->{local_ts}  // '') . "\n";
    print "  remote_ip : " . ($cfg->{remote_addrs} // '') . "\n";

    my $test_ip = get_test_ip($name, $cfg);
    print "  test_ip   : " . ($test_ip // 'UNKNOWN') . "\n";
}

section("Unexpected active SAs");
my $found_unexpected = 0;
for my $name (sort keys %active) {
    next if exists $configured{$name};
    $found_unexpected = 1;
    print "[WARN] Active SA not found in config parse: $name\n";
}
print "None\n" unless $found_unexpected;

section("Detailed peer checks");
for my $name (sort keys %configured) {
    my $cfg    = $configured{$name};
    my $sa     = $active{$name};
    my $testip = get_test_ip($name, $cfg);

    section("Peer: $name");

    print "Configured : yes\n";
    print "Active     : " . ($sa ? "yes" : "no") . "\n";
    print "peer_id    : " . ($cfg->{peer_id} // '') . "\n";
    print "local_ts   : " . ($cfg->{local_ts} // '') . "\n";
    print "remote_ts  : " . ($cfg->{remote_ts} // '') . "\n";
    print "test_ip    : " . ($testip // 'UNKNOWN') . "\n";

    if ($sa) {
        print "\n-- SA block --\n";
        print $sa->{block};

        print "\n-- SA interpretation --\n";
        print "ESTABLISHED : " . ($sa->{established} ? "yes" : "no") . "\n";
        print "IN bytes    : " . ($sa->{in_bytes}  // 0) . "\n";
        print "IN packets  : " . ($sa->{in_pkts}   // 0) . "\n";
        print "OUT bytes   : " . ($sa->{out_bytes} // 0) . "\n";
        print "OUT packets : " . ($sa->{out_pkts}  // 0) . "\n";
        print "Remote seen : " . ($sa->{remote_underlay} // 'UNKNOWN') . "\n";
    }

    if ($testip) {
        print "\n-- Route checks --\n";
        run("ip route get $testip");
        run("ip route get $testip from $OVERLAY_LOCAL");

        print "\n-- Overlay ping --\n";
        run("ping -I $OVERLAY_LOCAL -c 2 $testip");

        my $ports = $TEST_TARGETS{$name}{ports} || [];
        if (@$ports) {
            print "\n-- TCP port checks --\n";
            for my $port (@$ports) {
                run("nc -vz -w 2 $testip $port");
            }
        }
    } else {
        print "\n[WARN] No test_ip available for this peer\n";
    }
}

section("XFRM");
run("sudo ip xfrm policy");
run("sudo ip xfrm state");

section("Interpretation hints");
print <<'EOF';
- Configured but inactive:
    present in config, absent from swanctl --list-sas

- Active and ESTABLISHED:
    tunnel exists

- OUT > 0 and IN = 0:
    local node is sending into tunnel, but nothing returns

- Both IN/OUT = 0:
    traffic is not entering the tunnel at all

- ip route get <test_ip> goes via default gateway:
    overlay route is wrong for policy-based IPsec

- ip route get <test_ip> from <overlay_local> should typically stay local
  (e.g. dev lo) so XFRM can catch it in policy-based setups

- If test_ip is UNKNOWN:
    add it to %TEST_TARGETS or make remote_ts derivation fit your convention
EOF

exit 0;

sub print_banner {
    print "\n=========================================\n";
    print " VPN Router Diagnose\n";
    print "=========================================\n";
}

sub section {
    my ($title) = @_;
    print "\n-----------------------------------------\n";
    print " $title\n";
    print "-----------------------------------------\n";
}

sub run {
    my ($cmd) = @_;
    print "\n\$ $cmd\n";
    system($cmd);
}

sub get_test_ip {
    my ($name, $cfg) = @_;

    if (exists $TEST_TARGETS{$name} && $TEST_TARGETS{$name}{test_ip}) {
        return $TEST_TARGETS{$name}{test_ip};
    }

    my $rts = $cfg->{remote_ts} // '';
    return derive_test_ip_from_ts($rts);
}

sub derive_test_ip_from_ts {
    my ($ts) = @_;

    # Simple convention:
    # 10.47.1.0/24 -> 10.47.1.1
    # 10.47.0.2/32 -> 10.47.0.2
    if ($ts =~ /^(\d+\.\d+\.\d+)\.0\/24$/) {
        return "$1.1";
    }
    if ($ts =~ /^(\d+\.\d+\.\d+\.\d+)\/32$/) {
        return $1;
    }
    return undef;
}

sub parse_swanctl_conf {
    my ($glob) = @_;
    my %peers;

    for my $file (glob($glob)) {
        open my $fh, '<', $file or next;
        local $/ = undef;
        my $text = <$fh>;
        close $fh;

        my @lines = split /\n/, $text;

        my $in_connections = 0;
        my $in_conn        = 0;
        my $in_local       = 0;
        my $in_remote      = 0;
        my $in_children    = 0;
        my $in_child       = 0;

        my @stack;
        my $current_conn;
        my $current_child;

        for my $raw (@lines) {
            my $line = $raw;
            $line =~ s/#.*$//;
            $line =~ s/^\s+//;
            $line =~ s/\s+$//;
            next if $line eq '';

            if ($line =~ /^connections\s*\{$/) {
                $in_connections = 1;
                push @stack, 'connections';
                next;
            }

            if ($in_connections && !$in_conn && $line =~ /^([A-Za-z0-9_.-]+)\s*\{$/) {
                $current_conn = $1;
                $peers{$current_conn} ||= {
                    file         => $file,
                    name         => $current_conn,
                    child        => '',
                    peer_id      => '',
                    remote_addrs => '',
                    local_ts     => '',
                    remote_ts    => '',
                };
                $in_conn = 1;
                push @stack, 'conn';
                next;
            }

            if ($in_conn && $line =~ /^remote_addrs\s*=\s*(.+)$/) {
                $peers{$current_conn}{remote_addrs} = trim($1);
                next;
            }

            if ($in_conn && $line =~ /^local\s*\{$/) {
                $in_local = 1;
                push @stack, 'local';
                next;
            }

            if ($in_conn && $line =~ /^remote\s*\{$/) {
                $in_remote = 1;
                push @stack, 'remote';
                next;
            }

            if ($in_conn && $line =~ /^children\s*\{$/) {
                $in_children = 1;
                push @stack, 'children';
                next;
            }

            if ($in_children && !$in_child && $line =~ /^([A-Za-z0-9_.-]+)\s*\{$/) {
                $current_child = $1;
                $peers{$current_conn}{child} = $current_child;
                $in_child = 1;
                push @stack, 'child';
                next;
            }

            if ($in_local && $line =~ /^id\s*=\s*(.+)$/) {
                $peers{$current_conn}{local_id} = trim($1);
                next;
            }

            if ($in_remote && $line =~ /^id\s*=\s*(.+)$/) {
                $peers{$current_conn}{peer_id} = trim($1);
                next;
            }

            if ($in_child && $line =~ /^local_ts\s*=\s*(.+)$/) {
                $peers{$current_conn}{local_ts} = trim($1);
                next;
            }

            if ($in_child && $line =~ /^remote_ts\s*=\s*(.+)$/) {
                $peers{$current_conn}{remote_ts} = trim($1);
                next;
            }

            if ($line eq '}') {
                my $ctx = pop @stack // '';
                if ($ctx eq 'local') {
                    $in_local = 0;
                }
                elsif ($ctx eq 'remote') {
                    $in_remote = 0;
                }
                elsif ($ctx eq 'child') {
                    $in_child = 0;
                    $current_child = undef;
                }
                elsif ($ctx eq 'children') {
                    $in_children = 0;
                }
                elsif ($ctx eq 'conn') {
                    $in_conn = 0;
                    $current_conn = undef;
                }
                elsif ($ctx eq 'connections') {
                    $in_connections = 0;
                }
                next;
            }
        }
    }

    return %peers;
}

sub get_active_sas {
    my $text = `sudo swanctl --list-sas 2>&1`;
    my %sas;

    my @lines = split /\n/, $text;
    my @blocks;
    my $cur = '';

    for my $line (@lines) {
        if ($line =~ /^[A-Za-z0-9_.-]+:\s+#\d+,\s+/) {
            push @blocks, $cur if $cur ne '';
            $cur = $line . "\n";
        } else {
            $cur .= $line . "\n" if $cur ne '';
        }
    }
    push @blocks, $cur if $cur ne '';

    for my $block (@blocks) {
        next unless $block =~ /^([A-Za-z0-9_.-]+):\s+#\d+,\s+/m;
        my $name = $1;

        my ($remote_underlay) = $block =~ /remote\s+'.*?'\s+\@\s+(\d+\.\d+\.\d+\.\d+)/m;
        my ($in_bytes,  $in_pkts)  = $block =~ /in\s+\S+,\s+(\d+)\s+bytes,\s+(\d+)\s+packets/m;
        my ($out_bytes, $out_pkts) = $block =~ /out\s+\S+,\s+(\d+)\s+bytes,\s+(\d+)\s+packets/m;

        $sas{$name} = {
            block          => $block,
            established    => ($block =~ /ESTABLISHED/) ? 1 : 0,
            remote_underlay=> $remote_underlay,
            in_bytes       => $in_bytes  // 0,
            in_pkts        => $in_pkts   // 0,
            out_bytes      => $out_bytes // 0,
            out_pkts       => $out_pkts  // 0,
        };
    }

    return ($text, %sas);
}

sub trim {
    my ($s) = @_;
    $s =~ s/^\s+// if defined $s;
    $s =~ s/\s+$// if defined $s;
    return $s;
}