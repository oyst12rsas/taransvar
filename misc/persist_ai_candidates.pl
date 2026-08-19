#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use JSON::PP qw(decode_json encode_json);
use Scalar::Util qw(looks_like_number);
use func;

sub first_defined {
    my ($h, @keys) = @_;
    for my $k (@keys) {
        return $h->{$k} if exists $h->{$k} && defined $h->{$k};
    }
    return undef;
}

sub normalize_confidence {
    my ($v) = @_;
    return undef unless defined $v && looks_like_number($v);
    $v += 0;
    $v /= 100 if $v > 1 && $v <= 100;
    $v = 0 if $v < 0;
    $v = 1 if $v > 1;
    return $v;
}

sub decode_assessment {
    my ($raw) = @_;
    my $outer = eval { decode_json($raw // '') };
    return undef if $@ || ref($outer) ne 'HASH';
    my $text = $outer->{text};
    return undef unless defined $text && length $text;
    $text =~ s/^\s*```(?:json)?\s*//i;
    $text =~ s/\s*```\s*$//;
    my $data = eval { decode_json($text) };
    return undef if $@ || ref($data) ne 'HASH';
    return $data;
}

sub array_values_for {
    my ($data, @keys) = @_;
    my @items;
    for my $key (@keys) {
        next unless ref($data->{$key}) eq 'ARRAY';
        push @items, @{$data->{$key}};
    }
    return @items;
}

sub require_table {
    my ($dbh, $name) = @_;
    my $sth = $dbh->prepare(q{
        SELECT COUNT(*) AS n
          FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?
    });
    $sth->execute($name);
    my ($n) = $sth->fetchrow_array();
    $sth->finish();
    die "Required table '$name' is missing. Apply TaraSec DB schema version 83 before running AI candidate persistence.\n"
        unless $n;
}

my $dbh = getConnection();
require_table($dbh, 'aiUnitAssessment');
require_table($dbh, 'aiBotnetCandidate');

my $latest = $dbh->prepare('SELECT aiResponseId,response FROM aiResponse ORDER BY aiResponseId DESC LIMIT 1');
$latest->execute();
my $row = $latest->fetchrow_hashref();
$latest->finish();
if (!$row) {
    print "No AI response available to normalize.\n";
    $dbh->disconnect();
    exit 0;
}

my $data = decode_assessment($row->{response});
if (!$data) {
    warn "Latest AI response #$row->{aiResponseId} could not be decoded; raw response remains stored.\n";
    $dbh->disconnect();
    exit 0;
}

my $unit_insert = $dbh->prepare(q{
    INSERT INTO aiUnitAssessment
        (aiResponseId, ownerId, unitId, confidence, severity, category, summary, evidenceJson, rawJson)
    VALUES (?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        confidence=VALUES(confidence), severity=VALUES(severity), category=VALUES(category),
        summary=VALUES(summary), evidenceJson=VALUES(evidenceJson), rawJson=VALUES(rawJson)
});

my $unit_count = 0;
for my $item (array_values_for($data,
        qw(unit_assessments suspected_units infected_units candidate_infected_units unit_candidates))) {
    next unless ref($item) eq 'HASH';
    my $owner = first_defined($item, qw(owner_id ownerId owners_id owner));
    my $unit  = first_defined($item, qw(unit_id unitId remoteUnitId unit));
    next unless defined $owner && defined $unit && $owner =~ /^\d+$/ && $unit =~ /^\d+$/;
    my $confidence = normalize_confidence(first_defined($item, qw(confidence probability score)));
    my $severity = first_defined($item, qw(severity event_severity));
    $severity = undef unless defined($severity) && $severity =~ /^\d+$/;
    my $category = first_defined($item, qw(category classification status));
    my $summary = first_defined($item, qw(summary reason reasoning description));
    my $evidence = first_defined($item, qw(evidence signals indicators));
    my $evidence_json = defined($evidence) ? encode_json($evidence) : undef;
    $unit_insert->execute($row->{aiResponseId}, int($owner), int($unit), $confidence,
        $severity, $category, $summary, $evidence_json, encode_json($item));
    $unit_count++;
}
$unit_insert->finish();

my $bot_insert = $dbh->prepare(q{
    INSERT INTO aiBotnetCandidate
        (aiResponseId, candidateKey, confidence, summary, membersJson, evidenceJson, rawJson)
    VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
        confidence=VALUES(confidence), summary=VALUES(summary), membersJson=VALUES(membersJson),
        evidenceJson=VALUES(evidenceJson), rawJson=VALUES(rawJson)
});

my $bot_count = 0;
my $seq = 0;
for my $item (array_values_for($data,
        qw(botnet_clusters candidate_botnets botnet_candidates botnets))) {
    next unless ref($item) eq 'HASH';
    $seq++;
    my $key = first_defined($item, qw(candidate_key cluster_id botnet_id id name label));
    $key = "response-$row->{aiResponseId}-cluster-$seq" unless defined $key && length $key;
    $key = substr("$key", 0, 128);
    my $confidence = normalize_confidence(first_defined($item, qw(confidence probability score)));
    my $summary = first_defined($item, qw(summary reason reasoning description));
    my $members = first_defined($item, qw(members units unit_keys candidate_members));
    my $evidence = first_defined($item, qw(evidence signals indicators shared_behavior));
    $bot_insert->execute($row->{aiResponseId}, $key, $confidence, $summary,
        defined($members) ? encode_json($members) : undef,
        defined($evidence) ? encode_json($evidence) : undef,
        encode_json($item));
    $bot_count++;
}
$bot_insert->finish();
$dbh->disconnect();

print "Normalized AI response #$row->{aiResponseId}: $unit_count unit candidate(s), $bot_count botnet candidate(s).\n";
