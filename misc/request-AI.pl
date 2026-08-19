#!/usr/bin/perl
use lib ('/root/taransvar/perl');

use strict;
use warnings;
use autodie;
use DBI;
use func;
use LWP::UserAgent;
use JSON;
use Time::HiRes qw(time);

my $nDaysChecked       = 30;
my $nMinimumIpsVisited = 5;
my $nMaxRowsPerSection = 500;
our $chatflowId = "1ae066cd-055b-4f53-b53f-778453daec78";
our $szAiUrl = "http://100.68.163.145:3000/api/v1/prediction/$chatflowId";

sub sendRequest
{
    my ($logs) = @_;

    my %request = ( question => $logs );
    my $json = encode_json(\%request);

    open(my $out, ">", "/tmp/flowise-request.json");
    print $out $json;
    close($out);

    my $ua = LWP::UserAgent->new(
        timeout => 60,
        agent   => 'TaraSec-AI/1.1',
    );

    my $response = $ua->post(
        $szAiUrl,
        "Content-Type" => "application/json",
        Content        => $json,
    );

    die "Flowise request failed: ".$response->status_line."\n"
        unless $response->is_success;

    return $response;
}

sub csv_value
{
    my ($value) = @_;
    return "" unless defined $value;
    $value =~ s/\r?\n/ /g;
    if ($value =~ /[",]/) {
        $value =~ s/"/""/g;
        $value = qq{"$value"};
    }
    return $value;
}

sub append_rows
{
    my ($logs_ref, $sth, @columns) = @_;
    my $rows = 0;
    while (my $row = $sth->fetchrow_hashref()) {
        $$logs_ref .= join(",", map { csv_value($row->{$_}) } @columns) . "\n";
        last if ++$rows >= $nMaxRowsPerSection;
    }
    return $rows;
}

sub decodeAiResponse
{
    my ($raw) = @_;

    my $outer = eval { decode_json($raw) };
    die "Invalid Flowise response JSON: $@\n" if $@ || ref($outer) ne 'HASH';

    my $json = $outer->{text};
    die "Flowise response did not contain a text field.\n"
        unless defined $json && length $json;

    $json =~ s/^\s*```(?:json)?\s*//i;
    $json =~ s/\s*```\s*$//;

    my $decoded = eval { decode_json($json) };
    die "Invalid AI assessment JSON: $@\nAI text was:\n$json\n"
        if $@ || ref($decoded) ne 'HASH';

    return $decoded;
}

sub requestAssessment
{
    my $dbh = getConnection();
    my $cutoff = $nDaysChecked * 60 * 60 * 24;

    my $logs =
        "TARASEC SECURITY TELEMETRY FOR INFECTION AND BOTNET ANALYSIS\n".
        "Period: last $nDaysChecked days.\n\n".
        "IDENTITY RULES:\n".
        "- owner_id + unit_id is the preferred stable device identity.\n".
        "- confirmed_unit_id overrides unit_id when both exist.\n".
        "- A source IP is only an observed network location and MUST NOT be treated as a permanent device identity.\n".
        "- One unit may use several source IPs over time; several units may also share one public IP through NAT.\n".
        "- ownerConfirmedTime in hackReport is stronger evidence that the source network accepted responsibility.\n".
        "- remoteUnitId identifies the owner-generated unit when the owner has resolved the exact unit.\n\n".
        "ANALYSIS GOALS:\n".
        "1. Identify units whose behavior is consistent with infection.\n".
        "2. Correlate multiple stable unit identities into possible botnet clusters.\n".
        "3. Prefer behavioral similarity across independent owner/unit identities: shared destinations, destination ports, protocols/services, timing and repeated reports.\n".
        "4. Do not declare a botnet merely because units scan widely or share a common destination. Require multiple supporting signals and state uncertainty.\n".
        "5. Distinguish confirmed identity evidence from IP-only observations.\n".
        "6. Recommend what additional evidence would most improve confidence.\n\n";

    my $sql =
        "select owner_id, coalesce(confirmed_unit_id, unit_id) as effective_unit_id, ".
        "count(distinct src_ip) as observed_source_ips, ".
        "count(distinct dst_ip) as distinct_targets, ".
        "count(distinct dst_port) as distinct_target_ports, ".
        "count(distinct protocol) as distinct_protocols, ".
        "count(distinct service) as distinct_services, ".
        "count(*) as threat_records, sum(`count`) as occurrences, ".
        "max(coalesce(severity,0)) as max_severity, ".
        "min(created) as first_seen, max(coalesce(lastSeen,created)) as last_seen ".
        "from syslogThreat ".
        "where unix_timestamp(coalesce(lastSeen,created)) > unix_timestamp(now()) - ? ".
        "and owner_id is not null and coalesce(confirmed_unit_id,unit_id) is not null ".
        "group by owner_id, coalesce(confirmed_unit_id,unit_id) ".
        "order by occurrences desc limit $nMaxRowsPerSection";

    my $sth = $dbh->prepare($sql) or die "prepare unit summary failed: ".$dbh->errstr."\n";
    $sth->execute($cutoff) or die "unit summary failed: ".$sth->errstr."\n";
    $logs .= "RECORDS: stable unit behavior summary\n".
             "owner_id,unit_id,observed_source_ips,distinct_targets,distinct_target_ports,distinct_protocols,distinct_services,threat_records,occurrences,max_severity,first_seen,last_seen\n";
    append_rows(\$logs, $sth, qw(owner_id effective_unit_id observed_source_ips distinct_targets distinct_target_ports distinct_protocols distinct_services threat_records occurrences max_severity first_seen last_seen));
    $sth->finish();

    $sql =
        "select owner_id, coalesce(confirmed_unit_id,unit_id) as effective_unit_id, ".
        "inet_ntoa(dst_ip) as dst_ip, dst_port, coalesce(protocol,'') as protocol, coalesce(service,'') as service, ".
        "sum(`count`) as occurrences, max(coalesce(severity,0)) as max_severity, ".
        "min(created) as first_seen, max(coalesce(lastSeen,created)) as last_seen ".
        "from syslogThreat ".
        "where unix_timestamp(coalesce(lastSeen,created)) > unix_timestamp(now()) - ? ".
        "and owner_id is not null and coalesce(confirmed_unit_id,unit_id) is not null ".
        "group by owner_id, coalesce(confirmed_unit_id,unit_id), dst_ip, dst_port, protocol, service ".
        "order by occurrences desc limit $nMaxRowsPerSection";

    $sth = $dbh->prepare($sql) or die "prepare unit destination fingerprints failed: ".$dbh->errstr."\n";
    $sth->execute($cutoff) or die "unit destination fingerprints failed: ".$sth->errstr."\n";
    $logs .= "\nRECORDS: stable unit destination fingerprints\n".
             "owner_id,unit_id,dst_ip,dst_port,protocol,service,occurrences,max_severity,first_seen,last_seen\n";
    append_rows(\$logs, $sth, qw(owner_id effective_unit_id dst_ip dst_port protocol service occurrences max_severity first_seen last_seen));
    $sth->finish();

    $sql =
        "select inet_ntoa(dst_ip) as dst_ip, dst_port, coalesce(protocol,'') as protocol, coalesce(service,'') as service, ".
        "count(distinct concat(owner_id,':',coalesce(confirmed_unit_id,unit_id))) as distinct_units, ".
        "count(distinct owner_id) as distinct_owners, sum(`count`) as occurrences, ".
        "max(coalesce(severity,0)) as max_severity, ".
        "group_concat(distinct concat(owner_id,':',coalesce(confirmed_unit_id,unit_id)) order by owner_id separator '|') as unit_keys, ".
        "min(created) as first_seen, max(coalesce(lastSeen,created)) as last_seen ".
        "from syslogThreat ".
        "where unix_timestamp(coalesce(lastSeen,created)) > unix_timestamp(now()) - ? ".
        "and owner_id is not null and coalesce(confirmed_unit_id,unit_id) is not null ".
        "group by dst_ip,dst_port,protocol,service ".
        "having count(distinct concat(owner_id,':',coalesce(confirmed_unit_id,unit_id))) >= 2 ".
        "order by distinct_owners desc, distinct_units desc, occurrences desc limit $nMaxRowsPerSection";

    $sth = $dbh->prepare($sql) or die "prepare shared destinations failed: ".$dbh->errstr."\n";
    $sth->execute($cutoff) or die "shared destinations failed: ".$sth->errstr."\n";
    $logs .= "\nRECORDS: destinations independently contacted by multiple known units\n".
             "dst_ip,dst_port,protocol,service,distinct_units,distinct_owners,occurrences,max_severity,unit_keys,first_seen,last_seen\n";
    append_rows(\$logs, $sth, qw(dst_ip dst_port protocol service distinct_units distinct_owners occurrences max_severity unit_keys first_seen last_seen));
    $sth->finish();

    $sql =
        "select ipOwnerId as owner_id, remoteUnitId as unit_id, ".
        "sum(coalesce(`count`,1)) as reports, count(distinct partnerIp) as reporting_networks, ".
        "count(distinct port) as distinct_source_ports, max(coalesce(severity,0)) as max_severity, ".
        "group_concat(distinct coalesce(hrCategory,'other') order by hrCategory separator '|') as categories, ".
        "min(created) as first_seen, max(coalesce(lastSeen,created)) as last_seen ".
        "from hackReport ".
        "where unix_timestamp(coalesce(lastSeen,created)) > unix_timestamp(now()) - ? ".
        "and ownerConfirmedTime is not null and ipOwnerId is not null and remoteUnitId is not null ".
        "group by ipOwnerId, remoteUnitId ".
        "order by reporting_networks desc, reports desc limit $nMaxRowsPerSection";

    $sth = $dbh->prepare($sql) or die "prepare confirmed hack reports failed: ".$dbh->errstr."\n";
    $sth->execute($cutoff) or die "confirmed hack reports failed: ".$sth->errstr."\n";
    $logs .= "\nRECORDS: owner-confirmed exact-unit hack report corroboration\n".
             "owner_id,unit_id,reports,reporting_networks,distinct_source_ports,max_severity,categories,first_seen,last_seen\n";
    append_rows(\$logs, $sth, qw(owner_id unit_id reports reporting_networks distinct_source_ports max_severity categories first_seen last_seen));
    $sth->finish();

    $sql =
        "select inet_ntoa(src_ip) as src_ip, count(distinct dst_ip) as distinct_targets, ".
        "count(*) as threat_records, sum(`count`) as occurrences, max(coalesce(severity,0)) as max_severity ".
        "from syslogThreat ".
        "where unix_timestamp(coalesce(lastSeen,created)) > unix_timestamp(now()) - ? ".
        "and (owner_id is null or coalesce(confirmed_unit_id,unit_id) is null) ".
        "group by src_ip having count(distinct dst_ip) >= ? ".
        "order by distinct_targets desc limit $nMaxRowsPerSection";

    $sth = $dbh->prepare($sql) or die "prepare unresolved IP observations failed: ".$dbh->errstr."\n";
    $sth->execute($cutoff, $nMinimumIpsVisited) or die "unresolved IP observations failed: ".$sth->errstr."\n";
    $logs .= "\nRECORDS: unresolved IP-only observations (lower-confidence identity evidence)\n".
             "source_ip,distinct_targets,threat_records,occurrences,max_severity\n";
    append_rows(\$logs, $sth, qw(src_ip distinct_targets threat_records occurrences max_severity));
    $sth->finish();

    $dbh->disconnect();

    $logs .= "\nOUTPUT GUIDANCE:\n".
             "Return the configured TaraSec assessment JSON. In summary/reasoning/signals, explicitly name owner_id:unit_id keys when evidence identifies a stable unit. " .
             "For possible botnets, describe the candidate cluster members, shared behavioral evidence, confidence and alternative explanations. " .
             "Never convert IP-only evidence into a stable unit identity unless TaraSec identity fields support it.\n";

    my $started = time();
    my $response = sendRequest($logs);
    my $elapsed = time() - $started;

    print "\nDecoded response:\n".$response->decoded_content."\n\n";
    return ($response, $elapsed);
}

my ($response, $elapsed) = requestAssessment();
my $rawResponse = $response->decoded_content;
my $flowise_response = decodeAiResponse($rawResponse);

print "\nSummary: ".($flowise_response->{summary} // "(none)")."\n";
print "Reasoning: ".($flowise_response->{reasoning} // "(none)")."\n";

my $dbh = getConnection();
my $sql = "insert into aiResponse (seconds, response) values (?,?)";
my $sth = $dbh->prepare($sql)
    or die "prepare statement failed: ".$dbh->errstr."\n";

my $nSeconds = int($elapsed + 0.5);
$sth->execute($nSeconds, $rawResponse)
    or die "execution failed: ".$sth->errstr."\n";

$sth->finish();
$dbh->disconnect();
