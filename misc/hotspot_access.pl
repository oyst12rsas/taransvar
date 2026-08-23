#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use func;

my $stop = 0;
$SIG{TERM} = sub { $stop = 1; };
$SIG{INT}  = sub { $stop = 1; };

my $IPTABLES = -x '/usr/sbin/iptables' ? '/usr/sbin/iptables' : '/sbin/iptables';
my $IPSET    = -x '/usr/sbin/ipset'    ? '/usr/sbin/ipset'    : '/sbin/ipset';

sub cmd_ok {
    return system(@_) == 0;
}

sub table_cmd {
    my ($table, @args) = @_;
    return ($IPTABLES, @args) if $table eq 'filter';
    return ($IPTABLES, '-t', $table, @args);
}

sub ensure_chain {
    my ($table, $chain) = @_;
    my @list = table_cmd($table, '-nL', $chain);
    return if cmd_ok(@list);
    my @create = table_cmd($table, '-N', $chain);
    system(@create) == 0 or die "Unable to create chain $chain\n";
}

sub flush_chain {
    my ($table, $chain) = @_;
    my @cmd = table_cmd($table, '-F', $chain);
    system(@cmd) == 0 or die "Unable to flush chain $chain\n";
}

sub ensure_jump {
    my ($table, $parent, $chain) = @_;
    my @check = table_cmd($table, '-C', $parent, '-j', $chain);
    return if cmd_ok(@check);
    my @insert = table_cmd($table, '-I', $parent, '1', '-j', $chain);
    system(@insert) == 0 or die "Unable to hook $chain into $parent\n";
}

sub remove_jump {
    my ($table, $parent, $chain) = @_;
    while (1) {
        my @check = table_cmd($table, '-C', $parent, '-j', $chain);
        last unless cmd_ok(@check);
        my @delete = table_cmd($table, '-D', $parent, '-j', $chain);
        system(@delete);
    }
}

sub remove_chain {
    my ($table, $chain) = @_;
    my @list = table_cmd($table, '-nL', $chain);
    return unless cmd_ok(@list);
    my @flush = table_cmd($table, '-F', $chain);
    my @delete = table_cmd($table, '-X', $chain);
    system(@flush);
    system(@delete);
}

sub cleanup_hotspot_firewall {
    return unless -x $IPTABLES;

    remove_jump('filter', 'INPUT',       'TARASEC_HOTSPOT_IN');
    remove_jump('filter', 'FORWARD',     'TARASEC_HOTSPOT_FWD');
    remove_jump('nat',    'PREROUTING',  'TARASEC_HOTSPOT_PRE');
    remove_jump('nat',    'POSTROUTING', 'TARASEC_HOTSPOT_POST');

    remove_chain('filter', 'TARASEC_HOTSPOT_IN');
    remove_chain('filter', 'TARASEC_HOTSPOT_FWD');
    remove_chain('nat',    'TARASEC_HOTSPOT_PRE');
    remove_chain('nat',    'TARASEC_HOTSPOT_POST');

    system($IPSET, 'destroy', 'allowed_lan') if -x $IPSET;
}

sub refresh_access_table {
    my ($dbh) = @_;

    my $sql = q{
        INSERT INTO access (ip, hasaccess)
        SELECT DISTINCT ip, 1
          FROM session s
          JOIN radcheck r ON s.username = r.username
         WHERE active = 1
           AND logouttime IS NULL
           AND lastrequest > DATE_SUB(NOW(), INTERVAL 1 HOUR)
           AND IF(subscriptionType = 'quota',
                  COALESCE(mbusage,0) < mbquota,
                  IF(subscriptionType = 'expiry',
                     expirytime > NOW(),
                     COALESCE(mbusage,0) < mbquota AND expirytime > NOW()))
        ON DUPLICATE KEY UPDATE hasAccess = 1, updated = NOW()
    };

    $dbh->do($sql);
    $dbh->do("DELETE FROM access WHERE updated < DATE_SUB(NOW(), INTERVAL 2 SECOND)");
}

sub refresh_firewall {
    my ($dbh, $internal, $external) = @_;

    die "ipset is required for hotspot access control\n" unless -x $IPSET;
    die "iptables is required for hotspot access control\n" unless -x $IPTABLES;

    system($IPSET, 'create', 'allowed_lan', 'hash:ip', '-exist') == 0
        or die "Unable to create allowed_lan ipset\n";
    system($IPSET, 'flush', 'allowed_lan') == 0
        or die "Unable to flush allowed_lan ipset\n";

    my $sth = $dbh->prepare("SELECT ip FROM access WHERE hasaccess = 1");
    $sth->execute();
    while (my $row = $sth->fetchrow_hashref()) {
        next unless defined $row->{ip} && $row->{ip} =~ /^\d{1,3}(?:\.\d{1,3}){3}$/;
        system($IPSET, 'add', 'allowed_lan', $row->{ip}, '-exist');
    }
    $sth->finish();

    ensure_chain('filter', 'TARASEC_HOTSPOT_IN');
    ensure_chain('filter', 'TARASEC_HOTSPOT_FWD');
    ensure_chain('nat',    'TARASEC_HOTSPOT_PRE');
    ensure_chain('nat',    'TARASEC_HOTSPOT_POST');

    flush_chain('filter', 'TARASEC_HOTSPOT_IN');
    flush_chain('filter', 'TARASEC_HOTSPOT_FWD');
    flush_chain('nat',    'TARASEC_HOTSPOT_PRE');
    flush_chain('nat',    'TARASEC_HOTSPOT_POST');

    ensure_jump('filter', 'INPUT',       'TARASEC_HOTSPOT_IN');
    ensure_jump('filter', 'FORWARD',     'TARASEC_HOTSPOT_FWD');
    ensure_jump('nat',    'PREROUTING',  'TARASEC_HOTSPOT_PRE');
    ensure_jump('nat',    'POSTROUTING', 'TARASEC_HOTSPOT_POST');

    # Local hotspot services only. Never flush or change global TaraSec policy.
    system($IPTABLES, '-A', 'TARASEC_HOTSPOT_IN', '-i', $internal,
           '-p', 'udp', '--sport', '68', '--dport', '67', '-j', 'ACCEPT');
    system($IPTABLES, '-A', 'TARASEC_HOTSPOT_IN', '-i', $internal,
           '-p', 'udp', '--dport', '53', '-j', 'ACCEPT');
    system($IPTABLES, '-A', 'TARASEC_HOTSPOT_IN', '-i', $internal,
           '-p', 'tcp', '--dport', '53', '-j', 'ACCEPT');
    system($IPTABLES, '-A', 'TARASEC_HOTSPOT_IN', '-i', $internal,
           '-p', 'tcp', '--dport', '80', '-j', 'ACCEPT');
    system($IPTABLES, '-A', 'TARASEC_HOTSPOT_IN', '-j', 'RETURN');

    # Logged-in clients may be routed to the upstream interface.
    system($IPTABLES, '-A', 'TARASEC_HOTSPOT_FWD', '-i', $internal,
           '-o', $external, '-m', 'set', '--match-set', 'allowed_lan', 'src',
           '-m', 'conntrack', '--ctstate', 'NEW,ESTABLISHED,RELATED', '-j', 'ACCEPT');
    system($IPTABLES, '-A', 'TARASEC_HOTSPOT_FWD', '-i', $external,
           '-o', $internal, '-m', 'set', '--match-set', 'allowed_lan', 'dst',
           '-m', 'conntrack', '--ctstate', 'ESTABLISHED,RELATED', '-j', 'ACCEPT');
    system($IPTABLES, '-A', 'TARASEC_HOTSPOT_FWD', '-j', 'RETURN');

    # HTTP from unauthenticated clients goes to the local captive portal.
    system($IPTABLES, '-t', 'nat', '-A', 'TARASEC_HOTSPOT_PRE', '-i', $internal,
           '-p', 'tcp', '--dport', '80', '-m', 'set', '!', '--match-set',
           'allowed_lan', 'src', '-j', 'REDIRECT', '--to-ports', '80');
    system($IPTABLES, '-t', 'nat', '-A', 'TARASEC_HOTSPOT_PRE', '-j', 'RETURN');

    # NAT only authorised hotspot clients; unrelated traffic is untouched.
    system($IPTABLES, '-t', 'nat', '-A', 'TARASEC_HOTSPOT_POST', '-o', $external,
           '-m', 'set', '--match-set', 'allowed_lan', 'src', '-j', 'MASQUERADE');
    system($IPTABLES, '-t', 'nat', '-A', 'TARASEC_HOTSPOT_POST', '-j', 'RETURN');
}

sub read_setup {
    my ($dbh) = @_;
    my $sth = $dbh->prepare(q{
        SELECT CAST(hotspot AS UNSIGNED) AS hotspot,
               COALESCE(internalNic,'') AS internalNic,
               COALESCE(externalNic,'') AS externalNic
          FROM setup
         LIMIT 1
    });
    $sth->execute();
    my $row = $sth->fetchrow_hashref() || {};
    $sth->finish();
    return $row;
}

my $last_hotspot_state = -1;
print "TaraSec hotspot access service started\n";

while (!$stop) {
    eval {
        my $dbh = getConnection();
        my $setup = read_setup($dbh);
        my $enabled = $setup->{hotspot} ? 1 : 0;

        if (!$enabled) {
            if ($last_hotspot_state != 0) {
                print "Hotspot disabled in setup; removing hotspot-owned firewall hooks.\n";
                cleanup_hotspot_firewall();
            }
            $last_hotspot_state = 0;
        } else {
            my $internal = $setup->{internalNic} // '';
            my $external = $setup->{externalNic} // '';
            die "Hotspot enabled but internalNic/externalNic is not configured\n"
                if $internal eq '' || $external eq '';

            refresh_access_table($dbh);
            refresh_firewall($dbh, $internal, $external);
            $last_hotspot_state = 1;
        }

        $dbh->disconnect();
    };

    if ($@) {
        warn "Hotspot access refresh failed: $@";
    }

    for (1..5) {
        last if $stop;
        sleep 1;
    }
}

print "TaraSec hotspot access service stopping\n";
