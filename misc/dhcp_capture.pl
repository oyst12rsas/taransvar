#dhcp_capture.pl

#Check if records are being added: 
#select * from dhcpEvent order by dhcpEventId desc limit 5;

use lib ('/root/taransvar/perl');
#use lib ('.');
		
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..
use lib_dhcp;

my $iface;

if ($ARGV[0]) {
    $iface = $ARGV[0];
} else {
    print "*********** ERROR interface has to be specified as parameter. Aborting.\n";
    return;
}


use strict;
use warnings;
use DBI;
use Socket qw(inet_aton);
use POSIX qw(strftime);

my $pidfile = "/tmp/dhcp_capture.pid";

sub write_pidfile {
    open my $pf, ">", $pidfile or die "Cannot write $pidfile: $!";
    print $pf "$$\n";
    close $pf;
}

END {
    unlink $pidfile if -f $pidfile;
}

sub ip_to_int_or_undef {
    my ($ip) = @_;
    return undef if !defined $ip || $ip eq '';

    my $packed = inet_aton($ip);
    return undef if !defined $packed;

    return unpack("N", $packed);
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

    return strftime("%Y-%m-%d %H:%M:%S", localtime($sec)) . sprintf(".%06d", $micro);
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

    #my $client_mac = normalize_mac($mac);
    my $client_mac = normalize_mac(first_csv_value($mac));    
    return if !defined $client_mac;   # skip unusable rows

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
        or die "Prepare failed: " . $conn->errstr;

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
    ) or die "Execute failed: " . $sth->errstr;

    $sth->finish;
    $conn->disconnect;
}


sub process_dhcp_stream {
    my ($iface) = @_;

    print "Starting DHCP stream processor on $iface...\n";

    my @args = get_tshark_args($iface);

    print join(" ", @args) . "\n";

    open(my $fh, "-|", @args)
        or die "Cannot start tshark: $!";

    while (my $line = <$fh>) {
        chomp $line;
        next if $line eq '';

        my ($time, $src, $dst, $mac, $ip, $hostname, $vendor, $dhcp_type) =
            split(/\t/, $line, 8);

        next if !defined $mac || $mac eq '';

        print "DHCP: $mac -> " . ($ip // '') . " (" . ($hostname // '') . ")\n";

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

    close($fh);
}


process_dhcp_stream($iface);

