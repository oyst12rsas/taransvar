#!/usr/bin/perl
use strict;
use warnings;

# Configure a Wi-Fi interface as a TaraSec hotspot without disturbing
# the currently active Internet uplink. NetworkManager is the supported
# backend on ordinary Ubuntu systems. openNDS is installed on top of the
# working NetworkManager shared connection to enforce captive-portal access.

my $wifi_if = shift @ARGV // '';
my $requested_ssid = shift @ARGV // '';
my $addr    = shift @ARGV // '192.168.50.1/24';

sub sh {
    my ($cmd) = @_;
    print "+ $cmd\n";
    system($cmd) == 0 or die "Command failed: $cmd\n";
}

sub valid_short_name {
    my ($name) = @_;
    return $name =~ /^[A-Za-z0-9][A-Za-z0-9_-]{0,15}$/;
}

sub install_opennds {
    return if system('command -v ndsctl >/dev/null 2>&1') == 0;

    print "\nInstalling openNDS captive portal...\n";
    sh('apt-get update');
    sh('DEBIAN_FRONTEND=noninteractive apt-get install -y opennds');

    die "openNDS package installation completed but ndsctl is still unavailable.\n"
        unless system('command -v ndsctl >/dev/null 2>&1') == 0;
}

sub configure_opennds {
    my ($ifname, $gateway_name) = @_;
    my $legacy_conf = '/etc/opennds/opennds.conf';
    my $conf = '/etc/config/opennds';

    system('systemctl stop opennds >/dev/null 2>&1');

    # openNDS 10.1+ reads /etc/config/opennds on all operating systems and the
    # helper scripts expect UCI-style "option name 'value'" syntax. Ubuntu's
    # package still ships the deprecated generic-Linux template at
    # /etc/opennds/opennds.conf, so do not copy that legacy syntax into the
    # active file. Create/repair a minimal UCI-style configuration instead.
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

    # If the current file came from Ubuntu's legacy template, replace it with a
    # small valid UCI-style config. Otherwise preserve existing UCI options and
    # only update the TaraSec interface/name.
    if ($cfg !~ /^\s*config\s+opennds\b/m && $cfg !~ /^\s*option\s+gatewayinterface\b/m) {
        $cfg = "config opennds 'opennds'\n";
        $cfg .= "\toption enabled '1'\n";
    } elsif ($cfg !~ /^\s*config\s+opennds\b/m) {
        $cfg = "config opennds 'opennds'\n" . $cfg;
    }

    # Remove legacy directives if a prior failed install copied the old file.
    $cfg =~ s/^\s*GatewayInterface\s+.*\n//mig;
    $cfg =~ s/^\s*GatewayName\s+.*\n//mig;

    if ($cfg =~ /^\s*option\s+gatewayinterface\s+.*$/mi) {
        $cfg =~ s/^\s*option\s+gatewayinterface\s+.*$/\toption gatewayinterface '$ifname'/mi;
    } else {
        $cfg .= "\toption gatewayinterface '$ifname'\n";
    }

    if ($cfg =~ /^\s*option\s+gatewayname\s+.*$/mi) {
        $cfg =~ s/^\s*option\s+gatewayname\s+.*$/\toption gatewayname '$gateway_name'/mi;
    } else {
        $cfg .= "\toption gatewayname '$gateway_name'\n";
    }

    open my $out, '>', $conf or die "Unable to write $conf: $!\n";
    print {$out} $cfg;
    close $out;

    # Verify the helper sees the values before starting the daemon. This catches
    # config-format errors immediately instead of waiting for a br-lan timeout.
    my $parsed_if = `/usr/lib/opennds/libopennds.sh get_option_from_config gatewayinterface 2>/dev/null`;
    chomp $parsed_if;
    die "openNDS config parser did not read gatewayinterface '$ifname' (got '$parsed_if').\n"
        unless $parsed_if eq $ifname;

    my $parsed_name = `/usr/lib/opennds/libopennds.sh get_option_from_config gatewayname 2>/dev/null`;
    chomp $parsed_name;
    die "openNDS config parser did not read gatewayname '$gateway_name' (got '$parsed_name').\n"
        unless $parsed_name eq $gateway_name;

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

# Never select the current default-route interface as the client-side hotspot.
chomp(my $wan_if = `ip -4 route show default | awk 'NR==1 {print \$5}'`);
if ($wan_if && $wan_if eq $wifi_if) {
    die "Refusing to convert $wifi_if to hotspot because it is the active Internet uplink. Connect another uplink first.\n";
}

# Check AP support before modifying the interface.
my $iw = `iw list 2>/dev/null`;
die "Wi-Fi hardware does not advertise AP mode support.\n"
    unless $iw =~ /Supported interface modes:.*?\*\s+AP\b/s;

# A fresh/previously wired-only host may have Wi-Fi software-disabled. Enable
# it before scanning. A hardware rfkill remains an explicit error.
system('rfkill unblock wifi >/dev/null 2>&1');
system('nmcli radio wifi on >/dev/null 2>&1');
sleep 1;
my $wifi_state = `nmcli -t -f DEVICE,TYPE,STATE device status | grep '^\Q$wifi_if\E:wifi:' 2>/dev/null`;
if ($wifi_state =~ /:unavailable\s*$/) {
    die "Wi-Fi interface $wifi_if is still unavailable after enabling the radio. Check rfkill/hardware Wi-Fi switch.\n";
}

# Scan before AP mode so the installer can show the owner what nearby phone
# users currently see. A failed/blocked scan is informative, not fatal.
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

my $profile = "tarasec-hotspot-$wifi_if";
system("nmcli connection delete '$profile' >/dev/null 2>&1");
sh("nmcli connection add type wifi ifname '$wifi_if' con-name '$profile' autoconnect yes ssid '$ssid'");
sh("nmcli connection modify '$profile' 802-11-wireless.mode ap ipv4.method shared ipv4.addresses '$addr' ipv6.method disabled connection.autoconnect-priority 100");
sh("nmcli connection up '$profile'");

# NetworkManager now provides the AP, DHCP, DNS forwarding and NAT. Install and
# bind openNDS only after that router path is known to be up.
install_opennds();
configure_opennds($wifi_if, $ssid);

print "\nTaraSec hotspot interface configured and captive portal enforced.\n";
print "WAN:     ".($wan_if || '<unknown>')." (left unchanged)\n";
print "Hotspot: $wifi_if\n";
print "SSID:    $ssid\n";
print "Address: $addr\n";
print "Profile: $profile\n";
print "Portal:  openNDS active and verified\n";
