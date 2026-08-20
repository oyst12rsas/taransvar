#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use JSON::PP qw(decode_json);
use func;

# Safe end-to-end acceptance test for TaraSec gateway AI.
#
# This script does NOT generate network attack traffic and does NOT mark a real
# unit infected.  It inserts clearly labelled synthetic rows into syslogThreat,
# runs the existing gateway AI systemd service, prints the newest gateway-local
# assessment, and can remove the synthetic rows again.
#
# Usage:
#   sudo perl test_gateway_ai.pl all       # seed -> run AI -> print -> cleanup
#   sudo perl test_gateway_ai.pl seed      # only add synthetic telemetry
#   sudo perl test_gateway_ai.pl run       # run AI and print newest assessment
#   sudo perl test_gateway_ai.pl cleanup   # remove all synthetic telemetry
#
# The default action is "all".

my $ACTION = lc($ARGV[0] // 'all');
my $MARKER = 'TARASEC_AI_TEST:';
my $OWNER_ID = 900001;
my $UNIT_ID  = 900001;
my $SRC_IP   = '192.0.2.250';       # RFC 5737 documentation address
my @TARGETS  = map { "198.51.100.$_" } (10 .. 17); # RFC 5737 documentation addresses

sub cleanup_test_rows {
    my $dbh = getConnection();
    my $sth = $dbh->prepare('DELETE FROM syslogThreat WHERE description LIKE ?');
    $sth->execute($MARKER . '%');
    my $deleted = $sth->rows;
    $sth->finish();
    $dbh->disconnect();
    print "Removed $deleted synthetic TaraSec AI test row(s).\n";
}

sub seed_test_rows {
    # Avoid accumulating an old interrupted test run.
    cleanup_test_rows();

    my $dbh = getConnection();
    my $sql = q{
        INSERT INTO syslogThreat
            (created,lastSeen,owner_id,unit_id,confirmed_unit_id,
             is_attack,action,src_ip,src_port,dst_ip,dst_port,
             protocol,service,description,`count`,severity,handled)
        VALUES
            (NOW(),NOW(),?,?,?,1,'deny',INET_ATON(?),?,INET_ATON(?),22,
             'TCP','other',?,1,6,b'1')
    };
    my $sth = $dbh->prepare($sql);

    my $n = 0;
    for my $target (@TARGETS) {
        $n++;
        my $source_port = 41000 + $n;
        my $description = $MARKER . " synthetic repeated SSH reconnaissance; target=$target; test-only";
        $sth->execute($OWNER_ID, $UNIT_ID, $UNIT_ID, $SRC_IP, $source_port, $target, $description);
    }

    $sth->finish();
    $dbh->disconnect();
    print "Inserted $n synthetic telemetry row(s) for test owner/unit $OWNER_ID:$UNIT_ID.\n";
    print "No real traffic was generated and no internalInfections/hackReport state was changed.\n";
}

sub newest_gateway_assessment {
    my $dbh = getConnection();
    my $sth = $dbh->prepare('SELECT aiResponseId,created,response FROM aiResponse ORDER BY aiResponseId DESC LIMIT 30');
    $sth->execute();

    my $found;
    while (my $row = $sth->fetchrow_hashref()) {
        my $decoded = eval { decode_json($row->{response} // '') };
        next unless $decoded && ref($decoded) eq 'HASH';

        if (($decoded->{source} // '') eq 'gateway_local') {
            $found = {
                id         => $row->{aiResponseId},
                created    => $row->{created},
                envelope   => $decoded,
                assessment => (ref($decoded->{assessment}) eq 'HASH' ? $decoded->{assessment} : undef),
            };
            last;
        }
    }

    $sth->finish();
    $dbh->disconnect();
    return $found;
}

sub run_gateway_ai {
    print "Starting tarasec-gateway-ai.service...\n";
    my $rc = system('/usr/bin/systemctl', 'start', 'tarasec-gateway-ai.service');
    die "tarasec-gateway-ai.service failed (system()=$rc). Check: journalctl -u tarasec-gateway-ai.service -n 100 --no-pager\n"
        if $rc != 0;

    my $latest = newest_gateway_assessment();
    die "AI service completed but no gateway_local aiResponse was found.\n" unless $latest;

    print "\nNewest gateway AI assessment: aiResponse #$latest->{id} ($latest->{created})\n";
    my $pretty = JSON::PP->new->canonical(1)->pretty(1);
    if ($latest->{assessment}) {
        print $pretty->encode($latest->{assessment});
    } else {
        print $pretty->encode($latest->{envelope});
    }

    print "\nExpected test signal: repeated SSH reconnaissance associated with stable test identity $OWNER_ID:$UNIT_ID.\n";
    print "The AI may choose different wording/severity, but it should not reinterpret the documentation IP as the unit identity.\n";
}

if ($ACTION eq 'seed') {
    seed_test_rows();
} elsif ($ACTION eq 'run') {
    run_gateway_ai();
} elsif ($ACTION eq 'cleanup') {
    cleanup_test_rows();
} elsif ($ACTION eq 'all') {
    seed_test_rows();
    my $error = '';
    eval {
        run_gateway_ai();
        1;
    } or do {
        $error = $@ || 'Unknown gateway AI test failure';
    };
    cleanup_test_rows();
    die $error if $error ne '';
    print "\nGateway AI synthetic acceptance test finished and test telemetry was cleaned up.\n";
} else {
    die "Unknown action '$ACTION'. Use: all, seed, run, cleanup\n";
}
