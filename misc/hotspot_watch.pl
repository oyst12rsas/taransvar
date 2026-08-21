#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use func;

sub service_active {
    my ($name) = @_;
    return system('/bin/systemctl', 'is-active', '--quiet', $name) == 0;
}

sub interface_exists {
    my ($iface) = @_;
    return defined($iface) && $iface =~ /^[A-Za-z0-9_.:-]+$/ && -d "/sys/class/net/$iface";
}

sub interface_has_carrier {
    my ($iface) = @_;
    return 0 unless interface_exists($iface);
    my $file = "/sys/class/net/$iface/carrier";
    return 0 unless -r $file;
    open(my $fh, '<', $file) or return 0;
    my $v = <$fh> // '0';
    close($fh);
    return $v =~ /^1/ ? 1 : 0;
}

sub capture_running {
    my ($iface) = @_;
    my $quoted = quotemeta($iface);
    my $out = `ps -eo args= 2>/dev/null`;
    return $out =~ m{/root/taransvar/perl/dhcp_capture\.pl\s+$quoted(?:\s|$)}m ? 1 : 0;
}

sub start_capture {
    my ($iface) = @_;
    return unless interface_exists($iface);
    return if capture_running($iface);
    return unless -x '/usr/bin/tshark';

    my $pid = fork();
    if (!defined $pid) {
        warn "Unable to fork DHCP capture for $iface: $!\n";
        return;
    }
    if ($pid == 0) {
        chdir('/') or exit 1;
        open(STDIN, '<', '/dev/null');
        open(STDOUT, '>>', "/root/setup/log/dhcp_capture-$iface.log");
        open(STDERR, '>>', "/root/setup/log/dhcp_capture-$iface.log");
        exec('/usr/bin/perl', '/root/taransvar/perl/dhcp_capture.pl', $iface);
        exit 1;
    }
    print "Started DHCP capture on $iface (PID $pid).\n";
}

sub default_route_iface {
    my $route = `ip route show default 2>/dev/null | head -1`;
    return $1 if $route =~ /\bdev\s+(\S+)/;
    return '';
}

my $dbh = getConnection();
my $sth = $dbh->prepare(q{
    SELECT COALESCE(ssid,'') ssid, COALESCE(internalNic,'') internalNic
      FROM setup LIMIT 1
});
$sth->execute();
my $setup = $sth->fetchrow_hashref() || {};
$sth->finish();
$dbh->disconnect();

my $ssid = $setup->{ssid} // '';
my $internal = $setup->{internalNic} // '';
my %capture;

if ($ssid ne '') {
    # An SSID in setup is a declaration that this node is expected to maintain
    # a Wi-Fi AP. Prefer the configured internal NIC when it is wireless; wlan0
    # remains the normal Raspberry Pi default.
    my $wifi = ($internal ne '' && -d "/sys/class/net/$internal/wireless") ? $internal : 'wlan0';
    if (!interface_exists($wifi)) {
        warn "SSID '$ssid' configured but Wi-Fi interface $wifi does not exist.\n";
    } else {
        system('/usr/sbin/rfkill', 'unblock', 'wifi') if -x '/usr/sbin/rfkill';
        system('/sbin/ip', 'link', 'set', $wifi, 'up');

        if (!service_active('hostapd.service')) {
            print "hostapd is not active; restarting for configured SSID '$ssid'.\n";
            system('/bin/systemctl', 'restart', 'hostapd.service');
        }
        if (!service_active('dnsmasq.service')) {
            print "dnsmasq is not active; restarting DHCP/DNS on hotspot.\n";
            system('/bin/systemctl', 'restart', 'dnsmasq.service');
        }
        $capture{$wifi} = 1;
    }
}

$capture{$internal} = 1 if $internal ne '' && interface_exists($internal);

# Also watch additional connected physical LAN NICs. Exclude loopback, VPNs,
# the upstream/default-route interface and obvious virtual interfaces. Merely
# having an unused adapter is not enough: carrier must be present.
my $upstream = default_route_iface();
opendir(my $dh, '/sys/class/net') or die "Unable to inspect interfaces: $!\n";
while (my $iface = readdir($dh)) {
    next if $iface =~ /^\./;
    next if $iface eq $upstream || $iface eq 'lo';
    next if $iface =~ /^(?:wg|wt|tun|tap|docker|br-|veth)/;
    next if -d "/sys/class/net/$iface/wireless"; # Wi-Fi is governed by setup.ssid.
    next unless interface_has_carrier($iface);
    $capture{$iface} = 1;
}
closedir($dh);

if (!-x '/usr/bin/tshark') {
    warn "tshark is not installed; DHCP capture cannot run. Install package 'tshark'.\n";
} else {
    mkdir '/root/setup' unless -d '/root/setup';
    mkdir '/root/setup/log' unless -d '/root/setup/log';
    for my $iface (sort keys %capture) {
        start_capture($iface);
    }
}

print "Hotspot/DHCP self-check complete. Capture interfaces: ".join(', ', sort keys %capture)."\n";
