#!/usr/bin/perl
use strict;
use warnings;

# Configure a Wi-Fi interface as a TaraSec captive-portal hotspot without
# disturbing the currently active Internet uplink.
#
# NetworkManager owns only the Wi-Fi AP and static client-side address.
# TaraSec owns DHCP/DNS (system dnsmasq), forwarding/NAT and openNDS. Do not
# use NetworkManager "ipv4.method shared" here: its private dnsmasq lease state
# is not visible to openNDS on generic Ubuntu systems.

my $wifi_if = shift @ARGV // '';
my $requested_ssid = shift @ARGV // '';
my $addr = shift @ARGV // '192.168.50.1/24';

sub sh {
    my ($cmd) = @_;
    print "+ $cmd\n";
    system($cmd) == 0 or die "Command failed: $cmd\n";
}

sub valid_short_name {
    my ($name) = @_;
    return $name =~ /^[A-Za-z0-9][A-Za-z0-9_-]{0,15}$/;
}

sub ipv4_24_details {
    my ($cidr) = @_;
    die "Hotspot address must currently be an IPv4 /24 (for example 192.168.50.1/24).\n"
        unless $cidr =~ /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/24$/;

    my @oct = ($1, $2, $3, $4);
    for my $n (@oct) {
        die "Invalid IPv4 address '$cidr'.\n" if $n > 255;
    }

    my $gateway = join('.', @oct);
    my $base = join('.', @oct[0..2]);
    return ($gateway, "$base.0/24", "$base.50", "$base.200");
}

sub install_hotspot_packages {
    my $need_update = 0;
    $need_update = 1 unless system('command -v dnsmasq >/dev/null 2>&1') == 0;
    $need_update = 1 unless system('command -v ndsctl >/dev/null 2>&1') == 0;

    sh('apt-get update') if $need_update;
    sh('DEBIAN_FRONTEND=noninteractive apt-get install -y dnsmasq')
        unless system('dpkg-query -W -f=\${Status} dnsmasq 2>/dev/null | grep -q "install ok installed"') == 0;
    sh('DEBIAN_FRONTEND=noninteractive apt-get install -y opennds')
        unless system('command -v ndsctl >/dev/null 2>&1') == 0;

    die "dnsmasq installation completed but dnsmasq is unavailable.\n"
        unless system('command -v dnsmasq >/dev/null 2>&1') == 0;
    die "openNDS installation completed but ndsctl is unavailable.\n"
        unless system('command -v ndsctl >/dev/null 2>&1') == 0;
}

sub clear_stale_hotspot_dns_listener {
    my ($gateway) = @_;
    my $ss = `ss -H -lntup 2>/dev/null`;
    my %pids;

    for my $line (split /\n/, $ss) {
        next unless $line =~ /\Q$gateway\E:53\b/;
        while ($line =~ /pid=(\d+)/g) {
            $pids{$1} = 1;
        }
    }

    for my $pid (sort { $a <=> $b } keys %pids) {
        my $cmdline = '';
        if (open my $fh, '<', "/proc/$pid/cmdline") {
            local $/;
            $cmdline = <$fh> // '';
            close $fh;
            $cmdline =~ s/\0/ /g;
        }

        # A previous NetworkManager "shared" hotspot can leave its private
        # dnsmasq alive briefly while the profile is being replaced. It is safe
        # to terminate only that very specific helper. Never touch NetBird,
        # libvirt, systemd-resolved, or an unknown DNS process.
        my $is_nm_dnsmasq = $cmdline =~ /dnsmasq/i &&
            ($cmdline =~ /NetworkManager/i ||
             $cmdline =~ /nm-dnsmasq/i ||
             $cmdline =~ m{--conf-file=/dev/null});

        if (!$is_nm_dnsmasq) {
            die "Port 53 on hotspot gateway $gateway is already used by PID $pid: $cmdline\n"
              . "Refusing to stop an unknown DNS service.\n";
        }

        print "Stopping stale NetworkManager dnsmasq on $gateway:53 (PID $pid)...\n";
        kill 'TERM', $pid or die "Unable to stop stale NetworkManager dnsmasq PID $pid: $!\n";
        for (1..5) {
            last unless kill 0, $pid;
            sleep 1;
        }
        die "Stale NetworkManager dnsmasq PID $pid did not stop.\n" if kill 0, $pid;
    }

    my $remaining = `ss -H -lntup 2>/dev/null | grep -F '$gateway:53 ' 2>/dev/null`;
    if ($remaining ne '') {
        die "Port 53 on hotspot gateway $gateway is still occupied:\n$remaining";
    }
}

sub configure_dnsmasq {
    my ($ifname, $gateway, $dhcp_start, $dhcp_end) = @_;
    my $conf = '/etc/dnsmasq.d/tarasec-hotspot.conf';
    my $leases = '/var/lib/misc/dnsmasq.leases';

    sh('mkdir -p /etc/dnsmasq.d /var/lib/misc');

    open my $out, '>', $conf or die "Unable to write $conf: $!\n";
    print {$out} <<"DNSMASQ";
# Managed by TaraSec misc/setupWifiNicAsHotspot.pl
interface=$ifname
# Do not force bind-interfaces or bind-dynamic here. Ubuntu releases may set
# one of these globally, and dnsmasq refuses to start if both are enabled.
dhcp-authoritative
dhcp-range=$dhcp_start,$dhcp_end,255.255.255.0,12h
dhcp-option=3,$gateway
dhcp-option=6,$gateway
dhcp-leasefile=$leases
domain-needed
bogus-priv
address=/status.client/$gateway
DNSMASQ
    close $out;

    my $test = `dnsmasq --test 2>&1`;
    if ($? != 0) {
        print STDERR $test;
        print STDERR "\nEffective dnsmasq bind directives:\n";
        system(q{grep -RniE '^[[:space:]]*(bind-interfaces|bind-dynamic)([[:space:]]|$)' /etc/dnsmasq.conf /etc/dnsmasq.d 2>/dev/null});
        die "TaraSec hotspot NOT complete: dnsmasq configuration test failed.\n";
    }

    clear_stale_hotspot_dns_listener($gateway);

    sh('systemctl enable dnsmasq');
    sh('systemctl restart dnsmasq');

    die "TaraSec hotspot NOT complete: dnsmasq is not running.\n"
        unless system('systemctl is-active --quiet dnsmasq') == 0;

    print "\ndnsmasq DHCP/DNS verified on $ifname.\n";
}

sub configure_nat {
    my ($ifname, $wan_if, $subnet) = @_;
    my $script = '/usr/local/sbin/tarasec-hotspot-nat.sh';
    my $service = '/etc/systemd/system/tarasec-hotspot-nat.service';

    open my $sysctl, '>', '/etc/sysctl.d/99-tarasec-hotspot.conf'
        or die "Unable to write TaraSec sysctl config: $!\n";
    print {$sysctl} "net.ipv4.ip_forward=1\n";
    close $sysctl;
    sh('sysctl -w net.ipv4.ip_forward=1');

    open my $nat, '>', $script or die "Unable to write $script: $!\n";
    print {$nat} <<"NAT";
#!/bin/sh
set -e
iptables -t nat -C POSTROUTING -s '$subnet' -o '$wan_if' -j MASQUERADE 2>/dev/null || \\
    iptables -t nat -A POSTROUTING -s '$subnet' -o '$wan_if' -j MASQUERADE
iptables -C FORWARD -i '$ifname' -o '$wan_if' -s '$subnet' -j ACCEPT 2>/dev/null || \\
    iptables -A FORWARD -i '$ifname' -o '$wan_if' -s '$subnet' -j ACCEPT
iptables -C FORWARD -i '$wan_if' -o '$ifname' -d '$subnet' -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT 2>/dev/null || \\
    iptables -A FORWARD -i '$wan_if' -o '$ifname' -d '$subnet' -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
NAT
    close $nat;
    sh("chmod 0755 '$script'");

    open my $unit, '>', $service or die "Unable to write $service: $!\n";
    print {$unit} <<"UNIT";
[Unit]
Description=TaraSec hotspot forwarding and NAT
After=NetworkManager.service network-online.target
Wants=network-online.target
Before=opennds.service

[Service]
Type=oneshot
ExecStart=$script
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
UNIT
    close $unit;

    sh('systemctl daemon-reload');
    sh('systemctl enable tarasec-hotspot-nat.service');
    sh('systemctl restart tarasec-hotspot-nat.service');
}

sub configure_opennds {
    my ($ifname, $gateway_name) = @_;
    my $legacy_conf = '/etc/opennds/opennds.conf';
    my $conf = '/etc/config/opennds';
    my $leases = '/var/lib/misc/dnsmasq.leases';

    system('systemctl stop opennds >/dev/null 2>&1');

    sh('mkdir -p /etc/config');

    if (-f $conf && !-e "$conf.tarasec-before") {
        sh("cp '$conf' '$conf.tarasec-before'");
    } elsif (!-f $conf && -f $legacy_conf && !-e "$legacy_conf.tarasec-before") {
        sh("cp '$legacy_conf' '$legacy_conf.tarasec-before'");
    }

    my $cfg = '';
    if (-f $conf) {
        open my $fh, '<', $conf or die "Unable to read $conf: $!\n";
        local $/;
        $cfg = <$fh>;
        close $fh;
    }

    if ($cfg !~ /^\s*config\s+opennds\b/m && $cfg !~ /^\s*option\s+gatewayinterface\b/m) {
        $cfg = "config opennds 'opennds'\n";
        $cfg .= "\toption enabled '1'\n";
    } elsif ($cfg !~ /^\s*config\s+opennds\b/m) {
        $cfg = "config opennds 'opennds'\n" . $cfg;
    }

    $cfg =~ s/^\s*GatewayInterface\s+.*\n//mig;
    $cfg =~ s/^\s*GatewayName\s+.*\n//mig;

    my %opts = (
        gatewayinterface => $ifname,
        gatewayname => $gateway_name,
        dhcp_leases_file => $leases,
    );

    for my $name (keys %opts) {
        my $value = $opts{$name};
        if ($cfg =~ /^\s*option\s+\Q$name\E\s+.*$/mi) {
            $cfg =~ s/^\s*option\s+\Q$name\E\s+.*$/\toption $name '$value'/mi;
        } else {
            $cfg .= "\toption $name '$value'\n";
        }
    }

    open my $out, '>', $conf or die "Unable to write $conf: $!\n";
    print {$out} $cfg;
    close $out;

    my $parsed_if = `/usr/lib/opennds/libopennds.sh get_option_from_config gatewayinterface 2>/dev/null`;
    chomp $parsed_if;
    die "openNDS config parser did not read gatewayinterface '$ifname' (got '$parsed_if').\n"
        unless $parsed_if eq $ifname;

    my $parsed_name = `/usr/lib/opennds/libopennds.sh get_option_from_config gatewayname 2>/dev/null`;
    chomp $parsed_name;
    die "openNDS config parser did not read gatewayname '$gateway_name' (got '$parsed_name').\n"
        unless $parsed_name eq $gateway_name;

    my $parsed_leases = `/usr/lib/opennds/libopennds.sh get_option_from_config dhcp_leases_file 2>/dev/null`;
    chomp $parsed_leases;
    die "openNDS config parser did not read DHCP lease file '$leases' (got '$parsed_leases').\n"
        unless $parsed_leases eq $leases;

    sh('systemctl enable opennds');
    sh('systemctl restart opennds');

    my $status = '';
    my $nds_ok = 0;
    for (1..30) {
        $status = `ndsctl status 2>&1`;
        if ($? == 0) {
            $nds_ok = 1;
            last;
        }
        sleep 1;
    }

    my $active = system('systemctl is-active --quiet opennds') == 0;
    if (!$active || !$nds_ok) {
        print STDERR "\nopenNDS verification failed.\n";
        print STDERR $status if $status ne '';
        print STDERR "Check: journalctl -u opennds --no-pager -n 100\n";
        die "TaraSec hotspot NOT complete: captive portal is not running.\n";
    }

    if ($status =~ /(?:Managed interface|Interface)\s*:\s*(\S+)/i) {
        my $managed_if = $1;
        if ($managed_if ne $ifname) {
            die "TaraSec hotspot NOT complete: openNDS is bound to $managed_if instead of $ifname.\n";
        }
    }

    print "\nopenNDS captive portal verified on $ifname.\n";
}

if ($> != 0) {
    die "Run as root, for example: sudo perl setupWifiNicAsHotspot.pl wlp5s0\n";
}

if (!$wifi_if) {
    chomp($wifi_if = `nmcli -t -f DEVICE,TYPE device status | awk -F: '$2=="wifi" {print $1; exit}'`);
}

die "No Wi-Fi interface found. Specify it as the first argument.\n" unless $wifi_if;

die "NetworkManager is not running. TaraSec hotspot setup will not switch this host to systemd-networkd.\n"
    unless system('systemctl is-active --quiet NetworkManager') == 0;

chomp(my $wan_if = `ip -4 route show default | awk 'NR==1 {print \$5}'`);
if ($wan_if && $wan_if eq $wifi_if) {
    die "Refusing to convert $wifi_if to hotspot because it is the active Internet uplink. Connect another uplink first.\n";
}
die "No IPv4 default-route interface found. Keep an Internet uplink connected before enabling the hotspot.\n"
    unless $wan_if;

my ($gateway_ip, $hotspot_subnet, $dhcp_start, $dhcp_end) = ipv4_24_details($addr);

my $iw = `iw list 2>/dev/null`;
die "Wi-Fi hardware does not advertise AP mode support.\n"
    unless $iw =~ /Supported interface modes:.*?\*\s+AP\b/s;

system('rfkill unblock wifi >/dev/null 2>&1');
system('nmcli radio wifi on >/dev/null 2>&1');
sleep 1;
my $wifi_state = `nmcli -t -f DEVICE,TYPE,STATE device status | grep '^\Q$wifi_if\E:wifi:' 2>/dev/null`;
if ($wifi_state =~ /:unavailable\s*$/) {
    die "Wi-Fi interface $wifi_if is still unavailable after enabling the radio. Check rfkill/hardware Wi-Fi switch.\n";
}

print "\nScanning nearby Wi-Fi networks on $wifi_if...\n";
my @nearby;
my $scan = `nmcli -t -f SSID device wifi list ifname '$wifi_if' --rescan yes 2>/dev/null`;
for my $s (split /\n/, $scan) {
    $s =~ s/\\:/:/g;
    next if $s eq '';
    push @nearby, $s unless grep { $_ eq $s } @nearby;
    last if @nearby >= 8;
}

print "\nChoose the Wi-Fi name for this TaraSec hotspot.\n";
print "People nearby will see it when they open the Wi-Fi list on their phone.\n";
if (@nearby) {
    print "\nNearby Wi-Fi names currently include:\n";
    print "    $_\n" for @nearby;
} else {
    print "\n(No nearby Wi-Fi names could be read; you can still choose the hotspot name.)\n";
}

chomp(my $hostname = `hostname -s 2>/dev/null`);
$hostname ||= 'hotspot';
$hostname =~ s/[^A-Za-z0-9_-]//g;
$hostname = substr($hostname, 0, 16);
$hostname = 'hotspot' unless valid_short_name($hostname);

my $ssid;
if ($requested_ssid ne '') {
    my $short = $requested_ssid;
    $short =~ s/^TaraSec_//;
    die "Invalid hotspot name '$short'. Use 1-16 characters: letters, digits, '-' or '_', starting with a letter or digit.\n"
        unless valid_short_name($short);
    $ssid = "TaraSec_$short";
} else {
    while (1) {
        print "\nHotspot name [$hostname]: ";
        my $short = <STDIN>;
        defined $short or die "No hotspot name received.\n";
        chomp $short;
        $short = $hostname if $short eq '';

        if (!valid_short_name($short)) {
            print "Please use 1-16 characters: letters, digits, '-' or '_'. No spaces.\n";
            next;
        }

        my $candidate = "TaraSec_$short";
        if (grep { lc($_) eq lc($candidate) } @nearby) {
            print "\nNote: '$candidate' is already visible nearby. Duplicate Wi-Fi names are allowed, but may be confusing locally.\n";
        }

        print "\nYour hotspot will appear in people's Wi-Fi list as:\n\n";
        print "        $candidate\n\n";
        print "Is this what you want? [Y/n]: ";
        my $answer = <STDIN>;
        defined $answer or die "No confirmation received.\n";
        chomp $answer;
        if ($answer eq '' || $answer =~ /^y(?:es)?$/i) {
            $ssid = $candidate;
            last;
        }
    }
}

system('systemctl stop opennds >/dev/null 2>&1');

my $profile = "tarasec-hotspot-$wifi_if";
# Bring the previous profile down before deleting it. This is important when a
# previous version used ipv4.method shared, because NetworkManager may otherwise
# leave its private dnsmasq alive on the hotspot gateway for a short time.
system("nmcli connection down '$profile' >/dev/null 2>&1");
system("nmcli connection delete '$profile' >/dev/null 2>&1");
sleep 1;
sh("nmcli connection add type wifi ifname '$wifi_if' con-name '$profile' autoconnect yes ssid '$ssid'");
sh("nmcli connection modify '$profile' 802-11-wireless.mode ap ipv4.method manual ipv4.addresses '$addr' ipv4.never-default yes ipv6.method disabled connection.autoconnect-priority 100");
sh("nmcli connection up '$profile'");

install_hotspot_packages();
configure_dnsmasq($wifi_if, $gateway_ip, $dhcp_start, $dhcp_end);
configure_nat($wifi_if, $wan_if, $hotspot_subnet);
configure_opennds($wifi_if, $ssid);

print "\nTaraSec hotspot interface configured and captive portal enforced.\n";
print "WAN:     $wan_if (left unchanged)\n";
print "Hotspot: $wifi_if\n";
print "SSID:    $ssid\n";
print "Address: $addr\n";
print "DHCP:    $dhcp_start - $dhcp_end (system dnsmasq)\n";
print "Profile: $profile\n";
print "Portal:  openNDS active and verified\n";