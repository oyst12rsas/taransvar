#!/usr/bin/perl
use lib ('/root/taransvar/perl');
#use lib ('.');

use strict;
use warnings;
use autodie;
use DBI;
use func;    # NOTE! See comment above regarding lib..
use LWP::UserAgent;
use JSON;
use Time::HiRes qw(time);

my $nDaysChecked       = 30;
my $nMinimumIpsVisited = 5;
our $chatflowId = "1ae066cd-055b-4f53-b53f-778453daec78";
our $szAiUrl = "http://100.68.163.145:3000/api/v1/prediction/$chatflowId";

sub sendRequest
{
    my ($logs) = @_;

    my %request = (
        question => $logs,
    );

    my $json = encode_json(\%request);

    # Useful when debugging exactly what was sent to Flowise.
    open(my $out, ">", "/tmp/flowise-request.json");
    print $out $json;
    close($out);

    my $ua = LWP::UserAgent->new(
        timeout => 60,
        agent   => 'TaraSec-AI/1.0',
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

sub decodeAiResponse
{
    my ($raw) = @_;

    my $outer = eval { decode_json($raw) };
    die "Invalid Flowise response JSON: $@\n" if $@ || ref($outer) ne 'HASH';

    my $json = $outer->{text};
    die "Flowise response did not contain a text field.\n"
        unless defined $json && length $json;

    # Flowise commonly returns the model JSON inside a Markdown code fence.
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
        "TARASEC SECURITY TELEMETRY\n".
        "Period: last $nDaysChecked days.\n".
        "Interpret IP addresses as observations, not permanent device identities.\n\n";

    # ******** Number of distinct destination IPs targeted per source IP ********
    my $sql =
        "select inet_ntoa(src_ip) as src_ip, ".
        "count(distinct dst_ip) as distinct_targets, ".
        "count(*) as threat_records, ".
        "sum(`count`) as occurrences, ".
        "max(coalesce(severity, 0)) as max_severity ".
        "from syslogThreat ".
        "where unix_timestamp(coalesce(lastSeen, created)) > unix_timestamp(now()) - ? ".
        "group by src_ip ".
        "having count(distinct dst_ip) >= ? ".
        "order by distinct_targets desc";

    my $sth = $dbh->prepare($sql)
        or die "prepare statement failed: ".$dbh->errstr."\n";
    $sth->execute($cutoff, $nMinimumIpsVisited)
        or die "execution failed: ".$sth->errstr."\n";

    $logs .=
        "RECORDS: source IPs that contacted at least $nMinimumIpsVisited different target IPs\n".
        "source_ip,distinct_targets,threat_records,occurrences,max_severity\n";

    while (my $row = $sth->fetchrow_hashref()) {
        $logs .= join(",",
            csv_value($row->{src_ip}),
            csv_value($row->{distinct_targets}),
            csv_value($row->{threat_records}),
            csv_value($row->{occurrences}),
            csv_value($row->{max_severity})
        ) . "\n";
    }
    $sth->finish();

    # ******** Activity grouped by TaraSec's existing owner/unit identity ********
    # confirmed_unit_id is preferred when available; otherwise unit_id is used.
    $sql =
        "select owner_id, coalesce(confirmed_unit_id, unit_id) as effective_unit_id, ".
        "count(distinct src_ip) as distinct_source_ips, ".
        "count(distinct dst_ip) as distinct_targets, ".
        "count(*) as threat_records, ".
        "sum(`count`) as occurrences, ".
        "max(coalesce(severity, 0)) as max_severity ".
        "from syslogThreat ".
        "where unix_timestamp(coalesce(lastSeen, created)) > unix_timestamp(now()) - ? ".
        "and owner_id is not null ".
        "and coalesce(confirmed_unit_id, unit_id) is not null ".
        "group by owner_id, coalesce(confirmed_unit_id, unit_id) ".
        "order by occurrences desc";

    $sth = $dbh->prepare($sql)
        or die "prepare statement failed: ".$dbh->errstr."\n";
    $sth->execute($cutoff)
        or die "execution failed: ".$sth->errstr."\n";

    $logs .=
        "\nRECORDS: activity grouped by known TaraSec owner/unit identity\n".
        "The effective unit ID is confirmed_unit_id when present, otherwise unit_id.\n".
        "owner_id,unit_id,distinct_source_ips,distinct_targets,threat_records,occurrences,max_severity\n";

    while (my $row = $sth->fetchrow_hashref()) {
        $logs .= join(",",
            csv_value($row->{owner_id}),
            csv_value($row->{effective_unit_id}),
            csv_value($row->{distinct_source_ips}),
            csv_value($row->{distinct_targets}),
            csv_value($row->{threat_records}),
            csv_value($row->{occurrences}),
            csv_value($row->{max_severity})
        ) . "\n";
    }
    $sth->finish();
    $dbh->disconnect();

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

# aiResponse.seconds is currently an integer column, so store rounded seconds.
my $nSeconds = int($elapsed + 0.5);
$sth->execute($nSeconds, $rawResponse)
    or die "execution failed: ".$sth->errstr."\n";

$sth->finish();
$dbh->disconnect();
