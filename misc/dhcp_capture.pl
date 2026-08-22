#dhcp_capture.pl

#Check if records are being added:
#select * from dhcpEvent order by dhcpEventId desc limit 5;

use lib ('/root/taransvar/perl');

use strict;
use warnings;
use autodie;
use DBI;
use func;
use Socket qw(inet_aton);
use POSIX qw(strftime);

my $iface;

if ($ARGV[0]) {
    $iface = $ARGV[0];
} else {
    print "*********** ERROR interface has to be specified as parameter. Aborting.\n";
    exit 1;
}

sub tshark_args {
    my ($iface) = @_;

    my $tshark = -x '/usr/bin/tshark' ? '/usr/bin/tshark' : 'tshark';
    my $fields = `$tshark -G fields 2>/dev/null`;

    my ($filter, $prefix);
    if ($fields =~ /(?:^|\t)dhcp\.hw\.mac_addr(?:\t|$)/m) {
        $filter = 'dhcp';
        $prefix = 'dhcp';
    } elsif ($fields =~ /(?:^|\t)bootp\.hw\.mac_addr(?:\t|$)/m) {
        $filter = 'bootp';
        $prefix = 'bootp';
    } else {
        die "This tshark version exposes neither dhcp.hw.mac_addr nor bootp.hw.mac_addr.\n";
    }

    my @wanted = (
        'hw.mac_addr',
        'ip.your',
        'option.hostname',
        'option.vendor_class_id',
        'option.dhcp',
    );

    for my $suffix (@wanted) {
        my $field = "$prefix.$suffix";
        die "Required tshark field '$field' is unavailable.\n"
            unless $fields =~ /(?:^|\t)\Q$field\E(?:\t|$)/m;
    }

    print "Using tshark DHCP field namespace: $prefix\n";

    return (
        $tshark,
        '-i', $iface,
        '-l',
        '-nn',
        '-Y', $filter,
        '-T', 'fields',
        '-e', 'frame.time_epoch',
        '-e', 'ip.src',
        '-e', 'ip.dst',
        '-e', "$prefix.hw.mac_addr",
        '-e', "$prefix.ip.your",
        '-e', "$prefix.option.hostname",
        '-e', "$prefix.option.vendor_class_id",
        '-e', "$prefix.option.dhcp",
    );
}

sub ip_to_int_or_undef {
    my ($ip) = @_;
    return undef if !defined $ip || $ip eq '';

    my $packed = inet_aton($ip);
    return undef if !defined $packed;

    return unpack('N', $packed);
}

sub normalize_mac {
    my ($mac) = @_;
    return undef if !defined $mac || $mac eq '';
    $mac =~ s/^\s+|\s+$//g;
    return lc $mac;
}

sub first_csv_value {
    my ($v) = @_;
    return undef if !defined $v || $v eq '';
    $v =~ s/^\s+|\s+$//g;
    my ($first) = split(/\s*,\s*/, $v, 2);
    return $first;
}

sub epoch_to_mysql_datetime {
    my ($epoch) = @_;
    return undef if !defined $epoch || $epoch eq '' || $epoch !~ /^\d+(?:\.\d+)?$/;

    my $sec = int($epoch);
    my $frac = $epoch - $sec;
    my $micro = int($frac * 1_000_000);

    return strftime('%Y-%m-%d %H:%M:%S', localtime($sec)) . sprintf('.%06d', $micro);
}

sub register_unit_for_event {
    my ($conn, $event_id, $client_mac, $your_ip_int, $hostname) = @_;

    # DHCP discovery/request packets legitimately carry 0.0.0.0. They are useful
    # telemetry but must not create/update a unit until an actual address exists.
    if (!defined($your_ip_int) || $your_ip_int == 0) {
        my $mark = $conn->prepare("UPDATE dhcpEvent SET handled=b'1' WHERE dhcpEventId=?");
        $mark->execute($event_id);
        $mark->finish();
        return;
    }

    my $mac_hex = $client_mac;
    $mac_hex =~ s/://g;
    $mac_hex = uc($mac_hex);

    my $lookup = $conn->prepare(q{
        SELECT unitId, hostname
          FROM unit
         WHERE LEFT(mac,6)=UNHEX(?)
         ORDER BY unitId DESC
         LIMIT 1
    });
    $lookup->execute($mac_hex);
    my $unit = $lookup->fetchrow_hashref();
    $lookup->finish();

    my $unit_id;
    if ($unit) {
        $unit_id = int($unit->{unitId});
        my $update = $conn->prepare(q{
            UPDATE unit
               SET ipAddress=?,
                   hostname=CASE
                       WHEN (hostname IS NULL OR hostname='') AND ?<>'' THEN ?
                       ELSE hostname
                   END,
                   lastSeen=NOW()
             WHERE unitId=?
        });
        my $host = defined($hostname) ? $hostname : '';
        $update->execute($your_ip_int, $host, $host, $unit_id);
        $update->finish();
    } else {
        my $insert = $conn->prepare(q{
            INSERT INTO unit (mac,ipAddress,hostname,lastSeen,dhcpClientId)
            VALUES (UNHEX(?),?,?,NOW(),b'0000000')
        });
        $insert->execute(
            $mac_hex,
            $your_ip_int,
            (defined($hostname) && $hostname ne '' ? $hostname : undef)
        );
        $unit_id = int($conn->{mysql_insertid} || 0);
        $insert->finish();
        die "Unable to obtain unitId for newly inserted DHCP unit\n" unless $unit_id > 0;
        print "Registered new TaraSec unit $unit_id for $client_mac\n";
    }

    my $mark = $conn->prepare("UPDATE dhcpEvent SET unitId=?,handled=b'1' WHERE dhcpEventId=?");
    $mark->execute($unit_id, $event_id);
    $mark->finish();
}

sub do_save_tshark_record {
    my ($raw_line, $time, $src, $dst, $mac, $ip, $hostname, $vendor, $iface, $dhcp_type) = @_;

    my $conn = getConnection();
    die "Could not connect to DB\n" unless defined $conn;

    my $src_ip_int  = ip_to_int_or_undef($src);
    my $dst_ip_int  = ip_to_int_or_undef($dst);
    my $your_ip_int = ip_to_int_or_undef($ip);

    $dhcp_type =~ s/^\s+|\s+$//g if defined $dhcp_type;
    $dhcp_type = undef if !defined $dhcp_type || $dhcp_type eq '';
    $dhcp_type = int($dhcp_type) if defined $dhcp_type;

    my $client_mac = normalize_mac(first_csv_value($mac));
    if (!defined $client_mac) {
        $conn->disconnect;
        return;
    }

    my $seen_at = epoch_to_mysql_datetime($time);

    my $sql = q{
        INSERT INTO dhcpEvent
        (
            seenAt,
            interfaceName,
            srcIp,
            dstIp,
            clientMac,
            yourIp,
            hostname,
            vendorClass,
            dhcpMessageType,
            rawLine
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    };

    my $sth = $conn->prepare($sql)
        or die 'Prepare failed: ' . $conn->errstr;

    $sth->execute(
        $seen_at,
        $iface,
        $src_ip_int,
        $dst_ip_int,
        $client_mac,
        $your_ip_int,
        (defined $hostname && $hostname ne '' ? $hostname : undef),
        (defined $vendor   && $vendor   ne '' ? $vendor   : undef),
        $dhcp_type,
        $raw_line,
    ) or die 'Execute failed: ' . $sth->errstr;

    my $event_id = int($conn->{mysql_insertid} || 0);
    $sth->finish;
    register_unit_for_event($conn, $event_id, $client_mac, $your_ip_int, $hostname)
        if $event_id > 0;
    $conn->disconnect;
}

sub process_dhcp_stream {
    my ($iface) = @_;

    print "Starting DHCP stream processor on $iface...\n";

    my @args = tshark_args($iface);
    print join(' ', @args) . "\n";

    open(my $fh, '-|', @args)
        or die "Cannot start tshark: $!";

    while (my $line = <$fh>) {
        chomp $line;
        next if $line eq '';

        my ($time, $src, $dst, $mac, $ip, $hostname, $vendor, $dhcp_type) =
            split(/\t/, $line, 8);

        next if !defined $mac || $mac eq '';

        print "DHCP: $mac -> " . ($ip // '') . ' (' . ($hostname // '') . ")\n";

        eval {
            do_save_tshark_record(
                $line,
                $time,
                $src,
                $dst,
                $mac,
                $ip,
                $hostname,
                $vendor,
                $iface,
                $dhcp_type
            );
        };
        if ($@) {
            print "DB error while saving DHCP record: $@\n";
        }
    }

    if (!close($fh)) {
        my $exit = $? >> 8;
        die "tshark exited with code $exit\n";
    }
}

process_dhcp_stream($iface);
