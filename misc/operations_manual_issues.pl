#!/usr/bin/perl
# Root-local issue persistence and manual-report collection. Never executes commands.
use strict;
use warnings;
use DBI;
use JSON::PP;
use Digest::SHA qw(sha256_hex);

sub sync_local_issues {
    my ($db, $reports) = @_;
    die "Expected bounded issue list\n" unless ref($reports) eq 'ARRAY' && @$reports <= 64;
    my ($ip, $version) = $db->selectrow_array('SELECT adminIP,dbVersion FROM setup LIMIT 1');
    die "Local issue schema unavailable\n" unless $ip && $version >= 100;
    my %ack;
    $db->begin_work;
    eval {
        for my $item (@$reports) {
            next unless ref($item) eq 'HASH' && ($item->{incarnation} // '') =~ /^[a-f0-9]{32}$/
                && length($item->{key} // '') <= 255;
            my $manual = ($item->{key} // '') =~ /^manual:[a-f0-9]{32}$/;
            next unless $manual || ($item->{key} // '') =~ /^(service|resource|deployment|reboot):/;
            my $status = $item->{status} // '';
            next unless $status =~ /^(open|pending|blocked|verification_pending|solved)$/;
            my $row = $db->selectrow_hashref('SELECT issueId,status,checkedAt FROM issue WHERE nodeIp=? AND issueKey=? FOR UPDATE', undef, $ip, $item->{key});
            # Only the admin form creates manual reports and verifies their closure.
            next if $manual && !$row;
            my $commands = encode_json({executable=>$item->{command_executable},arguments=>'retained locally'});
            my @values = ($item->{last_seen_at}, $item->{checked_at}, $item->{description}, $status,
                $commands, $item->{verification}, $item->{blocker}, $item->{next_action_at}, $item->{solved_at});
            if (!$row) {
                $db->do('INSERT INTO issue (nodeIp,issueKey,discoveredAt,lastSeenAt,checkedAt,description,status,commands,verification,blocker,nextActionAt,solvedAt) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', undef,
                    $ip, $item->{key}, $item->{discovered_at}, @values);
                $row = {issueId=>$db->{mysql_insertid}};
            } elsif ($item->{checked_at} >= $row->{checkedAt} && !($manual && ($row->{status} eq 'solved' || $status eq 'solved'))) {
                $db->do('UPDATE issue SET lastSeenAt=?,checkedAt=?,description=?,status=?,commands=?,verification=?,blocker=?,nextActionAt=?,solvedAt=? WHERE issueId=?', undef,
                    @values, $row->{issueId});
            }
            my $events = $item->{history} // [];
            die "Unbounded event batch\n" unless ref($events) eq 'ARRAY' && @$events <= 16;
            for my $event (@$events) {
                next unless ref($event) eq 'HASH' && ($event->{event_id} // '') =~ /^\d+$/ && $event->{event_id} > 0
                    && ($event->{kind} // '') =~ /^[a-z_]{1,32}$/;
                my $detail = encode_json($event->{detail});
                die "Oversized event\n" if length($detail) > 8000;
                $db->do('INSERT IGNORE INTO issueReport (issueId,eventId,reportedAt,kind,detail) VALUES (?,?,?,?,?)', undef,
                    $row->{issueId}, sha256_hex($item->{incarnation}.':'.$event->{event_id}), $event->{at}, $event->{kind}, $detail);
                $ack{$item->{incarnation}} = $event->{event_id} if $event->{event_id} > ($ack{$item->{incarnation}} // 0);
            }
        }
        $db->commit;
        1;
    } or do { my $error=$@; $db->rollback; die $error; };
    my $rows = $db->selectall_arrayref('SELECT issueKey AS issue_key,description,discoveredAt AS discovered_at,status,solvedAt AS solved_at FROM issue WHERE nodeIp=? AND verification="manual" ORDER BY discoveredAt', {Slice=>{}}, $ip);
    return {issues=>$rows, acknowledgements=>\%ack};
}

unless (caller) {
    die "Root required\n" unless $> == 0;
    local $/;
    my $input = <STDIN>;
    die "Input too large\n" if length($input // '') > 2 * 1024 * 1024;
    my $reports = decode_json($input || '[]');
    my $db = DBI->connect('DBI:mysql:database=taransvar;host=localhost', 'root', '',
        {RaiseError=>1, PrintError=>0, AutoCommit=>1});
    print encode_json(sync_local_issues($db, $reports));
}
1;
