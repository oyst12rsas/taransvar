#!/usr/bin/perl
# Root-local DB bridge. Descriptions are evidence, never commands.
use strict;
use warnings;
use DBI;
use JSON::PP;
use Digest::SHA qw(sha256_hex);
die "Root required\n" unless $> == 0;
local $/;
my $input = <STDIN>;
die "Input too large\n" if length($input // '') > 2 * 1024 * 1024;
my $reports = decode_json($input || '[]');
die "Expected issue list\n" unless ref($reports) eq 'ARRAY';
my $db = DBI->connect('DBI:mysql:database=taransvar;host=localhost', 'root', '',
    {RaiseError=>1, PrintError=>0, AutoCommit=>1});
my ($ip, $version) = $db->selectrow_array('SELECT adminIP,dbVersion FROM setup LIMIT 1');
if (!$ip || $version < 100) { print '[]'; exit; }
$db->begin_work;
eval {
    for my $item (@$reports) {
        next unless ref($item) eq 'HASH' && ($item->{key} // '') =~ /^manual:([a-f0-9]{32})$/;
        my $incarnation = $1;
        my ($id, $status) = $db->selectrow_array('SELECT issueId,status FROM issue WHERE nodeIp=? AND issueKey=? AND verification="manual" FOR UPDATE', undef, $ip, $item->{key});
        next unless $id;
        # Browser verification is authoritative; stale worker snapshots cannot reopen it.
        if ($status ne 'solved' && ($item->{status} // '') =~ /^(open|pending|blocked|verification_pending)$/) {
            $db->do('UPDATE issue SET status=?,checkedAt=?,lastSeenAt=?,blocker=?,nextActionAt=? WHERE issueId=?', undef,
                $item->{status}, $item->{checked_at}, $item->{last_seen_at}, $item->{blocker}, $item->{next_action_at}, $id);
        }
        for my $event (@{$item->{history} // []}) {
            next unless ref($event) eq 'HASH' && ($event->{event_id} // '') =~ /^\d+$/ && $event->{event_id} > 0;
            next unless ($event->{kind} // '') eq 'progress';
            $db->do('INSERT IGNORE INTO issueReport (issueId,eventId,reportedAt,kind,detail) VALUES (?,?,?,?,?)', undef,
                $id, sha256_hex($incarnation.':'.$event->{event_id}), $event->{at}, 'progress', encode_json($event->{detail}));
        }
    }
    $db->commit;
    1;
} or do { my $error=$@; $db->rollback; die $error; };
# Load all unresolved manual issues and verified ones (for local convergence).
my $rows = $db->selectall_arrayref('SELECT issueKey AS issue_key,description,discoveredAt AS discovered_at,status,solvedAt AS solved_at FROM issue WHERE nodeIp=? AND verification="manual" ORDER BY discoveredAt', {Slice=>{}}, $ip);
print encode_json($rows);
