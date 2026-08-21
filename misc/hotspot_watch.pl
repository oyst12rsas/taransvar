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

sub default_route_iface {
    my $route = `ip route show default 2>/dev/null | head -1`;
    return $1 if $route =~ /\bdev\s+(\S+)/;
    return '';
}

sub ensure_iptables_rule {
    my ($chain, @rule) = @_;
    my $iptables = -x '/usr/sbin/iptables' ? '/usr/sbin/iptables' : '/sbin/iptables';
    return unless -x $iptables;

    return if system($iptables, '-C', $chain, @rule) == 0;
    my $rc = system($iptables, '-I', $chain, '1', @rule);
    warn "Unable to add iptables rule to $chain: @rule\n" if $rc != 0;
}

sub ensure_hotspot_firewall {
    my ($iface) = @_;
    return unless interface_exists($iface);

    # Cigar exposed a long-standing direction bug: a DHCP server receives
    # client 68 -> server 67 and sends server 67 -> client 68.
    ensure_iptables_rule('INPUT',  '-i', $iface, '-p', 'udp', '--sport', '68', '--dport', '67', '-j', 'ACCEPT');
    ensure_iptables_rule('OUTPUT', '-o', $iface, '-p', 'udp', '--sport', '67', '--dport', '68', '-j', 'ACCEPT');

    # dnsmasq is advertised as resolver to hotspot clients.
    ensure_iptables_rule('INPUT', '-i', $iface, '-p', 'udp', '--dport', '53', '-j', 'ACCEPT');
    ensure_iptables_rule('INPUT', '-i', $iface, '-p', 'tcp', '--dport', '53', '-j', 'ACCEPT');
}

sub ensure_capture_service {
    my ($iface) = @_;
    return unless interface_exists($iface);
    return unless -x '/usr/bin/tshark';

    my $unit = "tarasec-dhcp-capture\@$iface.service";
    if (service_active($unit)) {
        print "DHCP capture already active on $iface.\n";
        return;
    }

    print "Starting DHCP capture service on $iface.\n";
    my $rc = system('/bin/systemctl', 'start', $unit);
    warn "Unable to start $unit\n" if $rc != 0;
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
    my $wifi = ($internal ne '' && -d "/sys/class/net/$internal/wireless") ? $internal : 'wlan0';
    if (!interface_exists($wifi)) {
        warn "SSID '$ssid' configured but Wi-Fi interface $wifi does not exist.\n";
    } else {
        system('/usr/sbin/rfkill', 'unblock', 'wifi') if -x '/usr/sbin/rfkill';
        system('/sbin/ip', 'link', 'set', $wifi, 'up');

        ensure_hotspot_firewall($wifi);

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

my $upstream = default_route_iface();
opendir(my $dh, '/sys/class/net') or die "Unable to inspect interfaces: $!\n";
while (my $iface = readdir($dh)) {
    next if $iface =~ /^\./;
    next if $iface eq $upstream || $iface eq 'lo';
    next if $iface =~ /^(?:wg|wt|tun|tap|docker|br-|veth)/;
    next if -d "/sys/class/net/$iface/wireless";
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
        ensure_capture_service($iface);
    }
}

print "Hotspot/DHCP self-check complete. Capture interfaces: ".join(', ', sort keys %capture)."\n";
