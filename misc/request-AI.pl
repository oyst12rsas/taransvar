#!/usr/bin/perl
use lib ('/root/taransvar/perl');
#use lib ('.');
		
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..
use LWP::UserAgent;
use JSON;

my $nDaysChecked = 30;
my $nMinimumIpsVisited = 5;
our $chatflowId = "1ae066cd-055b-4f53-b53f-778453daec78";
our $szAiUrl = "http://100.68.163.145:3000/api/v1/prediction/$chatflowId";

sub sendRequest {
	my ($logs) = @_;

	my %request = (
    	question => $logs,
	);

	open(my $out, ">", "/tmp/flowise-request.json") or die;
	print $out encode_json(\%request);
	close($out);

	my $ua = LWP::UserAgent->new;


	my $response = $ua->post(
    	$szAiUrl,
    	"Content-Type" => "application/json",
    	Content => encode_json(\%request),
	);

	die $response->status_line unless $response->is_success;

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

sub requestAssessment {

	#my $bIncoming = 1;
	#my $nPort = 0;
	#my $nUnitId = 5;


	my $threat_events = "";
	my $infection_data = "";
	my $dbh = getConnection();

	#***************** Number of IPs targeted per source IP ******************
	# select inet_ntoa(src_ip) as src_ip, count(syslogThreatId) as counted from syslogThreat group by dst_ip;

#select inet_ntoa(src_ip) as src_ip, unix_timestamp(lastSeen) as seen, unix_timestamp(now()) - ($nDaysChecked * 60 * 60 * 24 as monthAgo

	my $sql = "select inet_ntoa(src_ip) as src_ip, count(syslogThreatId) as counted from syslogThreat where unix_timestamp(coalesce(lastSeen, created)) > unix_timestamp(now()) - ($nDaysChecked * 60 * 60 * 24) group by dst_ip";
	my $sth = $dbh->prepare($sql) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $sth->errstr()";

	my $rows = "";

	my $logs = "RECORDS: number of different target IPs per sender IP last month(source, count)\n";

	while (my $row = $sth->fetchrow_hashref()) {
	    $logs .= join(",",
    	    csv_value($row->{src_ip}),
        	csv_value($row->{counted})
    	) . "\n";
	}

	$sth->finish();

	my %request = (
    	question => $logs,
	);

# How many src_ip have checked each target IP
# select dst_ip, inet_ntoa(dst_ip), count(distinct src_ip) from syslogThreat group by src_ip;

	#sendMessage($bIncoming, $nPort, $nUnitId);
	my $response = sendRequest($logs);
	print "\nDecoded response:\n".$response->decoded_content."\n\n";

	return $response;
}

sub interpret {
	my ($logs) = @_;

}

#my $szAI_response = '{"text":"```json\n{\n  \"event_severity\": 4,\n  \"category\": \"port_scan\",\n  \"confidence\": 90,\n  \"summary\": \"High number of distinct target IPs detected from multiple sources in recent timeframe.\",\n  \"signals\": [\n    \"High volume of different target IPs from sender 10.10.10.254\",\n    \"High volume of different target IPs from sender 100.68.10.7\",\n    \"Zero source address reported\"\n  ],\n  \"reasoning\": \"The event indicates possible reconnaissance activity based on the unusually high number of unique target IPs being accessed from specific senders, suggesting an organization may be scanning for vulnerabilities.\",\n  \"recommended_presumed_infected\": 2,\n  \"owners_id_action\": \"none\",\n  \"recommended_action\": \"watch\",\n  \"block_ssh\": false,\n  \"signals\": \"\"\n}\n```","question":"RECORDS: number of different target IPs per sender IP last month(source, count)\n10.10.10.11,28\n10.10.10.254,147\n100.68.25.154,6\n100.68.10.7,292\n100.68.163.145,3\n100.68.25.154,6\n100.68.10.7,97\n100.68.25.154,8\n100.68.194.129,12\n192.168.122.143,23\n192.168.122.42,4\n0.0.0.0,1';
my $response = requestAssessment();

my $outer = decode_json($response->decoded_content);

my $json = $outer->{text};

$json =~ s/^\s*```json\s*//i;
$json =~ s/\s*```\s*$//;

$json =~ tr/“”/""/;

my $flowise_response = decode_json($json);

print "\nSummary: ".$flowise_response->{summary}."\n";
print "Reasoning: ".$flowise_response->{reasoning}."\n";

my $szAI_response =
    "Summary: ".$flowise_response->{summary}."\n".
    "Reasoning: ".$flowise_response->{reasoning};

my $dbh = getConnection();

#my $sql = "update setup set aiAssessment = ?, aiAssessmentTime = now()";
my $sql = "insert into aiResponse (seconds, response) values (?,?)";
my $sth = $dbh->prepare($sql)  or die "prepare statement failed: ".$dbh->errstr;

my $nSeconds = 0;

#$sth->execute($szAI_response)
$sth->execute($nSeconds, $response->decoded_content)
    or die "execution failed: ".$sth->errstr;

$sth->finish();
$dbh->disconnect();