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

sub read_tarasec_config {
    my %cfg;
    my $file = '/etc/tarasec.conf';
    return \%cfg unless -r $file;
    open(my $fh, '<', $file) or return \%cfg;
    while (my $line = <$fh>) {
        chomp $line;
        $line =~ s/^\s+|\s+$//g;
        next if $line eq '' || $line =~ /^#/;
        my ($key,$value) = split(/\s*=\s*/, $line, 2);
        next unless defined $key && defined $value;
        $value =~ s/^(["'])(.*)\1$/$2/;
        $cfg{$key} = $value;
    }
    close($fh);
    return \%cfg;
}

my $aiCfg = read_tarasec_config();
our $szAiUrl = $aiCfg->{TARASEC_FLOWISE_URL} // $aiCfg->{FLOWISE_URL} // '';
our $szAiKey = $aiCfg->{TARASEC_FLOWISE_API_KEY} // $aiCfg->{FLOWISE_API_KEY} // '';
die "TARASEC_FLOWISE_URL is not configured in /etc/tarasec.conf\n" unless length $szAiUrl;
die "TARASEC_FLOWISE_API_KEY is not configured in /etc/tarasec.conf\n" unless length $szAiKey;

sub sendRequest {
    my ($logs) = @_;
    my $json = encode_json({ question => $logs });
    open(my $out, '>', '/tmp/flowise-request.json');
    print $out $json;
    close($out);
    my $ua = LWP::UserAgent->new(timeout => 60, agent => 'TaraSec-AI/1.3');
    my $response = $ua->post(
        $szAiUrl,
        'Content-Type' => 'application/json',
        'Authorization' => "Bearer $szAiKey",
        Content => $json
    );
    die "Flowise request failed: ".$response->status_line."\n" unless $response->is_success;
    return $response;
}

sub csv_value {
    my ($value) = @_;
    return '' unless defined $value;
    $value =~ s/\r?\n/ /g;
    if ($value =~ /[",]/) { $value =~ s/"/""/g; $value = qq{"$value"}; }
    return $value;
}

sub append_rows {
    my ($logs_ref, $sth, @columns) = @_;
    my $rows = 0;
    while (my $row = $sth->fetchrow_hashref()) {
        $$logs_ref .= join(',', map { csv_value($row->{$_}) } @columns)."\n";
        last if ++$rows >= $nMaxRowsPerSection;
    }
    return $rows;
}

sub decodeAiResponse {
    my ($raw) = @_;
    my $outer = eval { decode_json($raw) };
    die "Invalid Flowise response JSON: $@\n" if $@ || ref($outer) ne 'HASH';
    my $json = $outer->{text};
    die "Flowise response did not contain a text field.\n" unless defined $json && length $json;
    $json =~ s/^\s*```(?:json)?\s*//i;
    $json =~ s/\s*```\s*$//;
    my $decoded = eval { decode_json($json) };
    die "Invalid AI assessment JSON: $@\nAI text was:\n$json\n" if $@ || ref($decoded) ne 'HASH';
    return $decoded;
}

sub add_query_section {
    my ($dbh, $logs_ref, $title, $header, $sql, $bind, @cols) = @_;
    my $sth = $dbh->prepare($sql) or die "prepare $title failed: ".$dbh->errstr."\n";
    $sth->execute(@$bind) or die "$title failed: ".$sth->errstr."\n";
    $$logs_ref .= "\nRECORDS: $title\n$header\n";
    append_rows($logs_ref, $sth, @cols);
    $sth->finish();
}

sub requestAssessment {
    my $dbh = getConnection();
    my $cutoff = $nDaysChecked * 60 * 60 * 24;

    my $logs = "TARASEC SECURITY TELEMETRY FOR INFECTION AND BOTNET ANALYSIS\nPeriod: last $nDaysChecked days.\n\n".
        "IDENTITY CLASSES:\n".
        "1. CUSTOMER/LAN UNIT: identified only by owner_id + unit_id. confirmed_unit_id overrides unit_id.\n".
        "2. TARASEC/NETWORK NODE: an infrastructure address registered in partnerRouter. A node is identified by node_ip and NEVER receives a LAN unit_id.\n".
        "3. UNKNOWN IP OBSERVATION: an address with neither stable unit identity nor registered network-node identity.\n\n".
        "STRICT IDENTITY RULES:\n".
        "- Never put an IP address in unit_id.\n".
        "- Never call a registered network node a unit/device merely because it has no owner_id/unit_id.\n".
        "- Never call an unknown IP a unit.\n".
        "- One customer unit may use several source IPs; several units may share one public IP through NAT.\n".
        "- ownerConfirmedTime plus remoteUnitId is stronger exact-unit evidence.\n\n".
        "ANALYSIS GOALS:\n".
        "1. Identify customer units whose behavior is consistent with infection.\n".
        "2. Identify suspicious or compromised TaraSec/network nodes separately.\n".
        "3. Correlate stable units and, where relevant, nodes into possible botnet/C2 relationships without conflating identity classes.\n".
        "4. Prefer shared unusual endpoints, ports/services, timing, recurrence, independent owners/reports and stable identities.\n".
        "5. State uncertainty and plausible benign explanations.\n";

    add_query_section($dbh, \$logs, 'stable unit behavior summary',
        'owner_id,unit_id,observed_source_ips,distinct_targets,distinct_target_ports,distinct_protocols,distinct_services,threat_records,occurrences,max_severity,first_seen,last_seen',
        "select owner_id,coalesce(confirmed_unit_id,unit_id) effective_unit_id,count(distinct src_ip) observed_source_ips,count(distinct dst_ip) distinct_targets,count(distinct dst_port) distinct_target_ports,count(distinct protocol) distinct_protocols,count(distinct service) distinct_services,count(*) threat_records,sum(`count`) occurrences,max(coalesce(severity,0)) max_severity,min(created) first_seen,max(coalesce(lastSeen,created)) last_seen from syslogThreat where unix_timestamp(coalesce(lastSeen,created)) > unix_timestamp(now())-? and owner_id is not null and coalesce(confirmed_unit_id,unit_id) is not null group by owner_id,coalesce(confirmed_unit_id,unit_id) order by occurrences desc limit $nMaxRowsPerSection",
        [$cutoff], qw(owner_id effective_unit_id observed_source_ips distinct_targets distinct_target_ports distinct_protocols distinct_services threat_records occurrences max_severity first_seen last_seen));

    add_query_section($dbh, \$logs, 'stable unit destination fingerprints',
        'owner_id,unit_id,dst_ip,dst_port,protocol,service,occurrences,max_severity,first_seen,last_seen',
        "select owner_id,coalesce(confirmed_unit_id,unit_id) effective_unit_id,inet_ntoa(dst_ip) dst_ip,dst_port,coalesce(protocol,'') protocol,coalesce(service,'') service,sum(`count`) occurrences,max(coalesce(severity,0)) max_severity,min(created) first_seen,max(coalesce(lastSeen,created)) last_seen from syslogThreat where unix_timestamp(coalesce(lastSeen,created)) > unix_timestamp(now())-? and owner_id is not null and coalesce(confirmed_unit_id,unit_id) is not null group by owner_id,coalesce(confirmed_unit_id,unit_id),dst_ip,dst_port,protocol,service order by occurrences desc limit $nMaxRowsPerSection",
        [$cutoff], qw(owner_id effective_unit_id dst_ip dst_port protocol service occurrences max_severity first_seen last_seen));

    add_query_section($dbh, \$logs, 'registered TaraSec/network node behavior',
        'node_ip,distinct_targets,distinct_target_ports,distinct_protocols,distinct_services,threat_records,occurrences,max_severity,first_seen,last_seen',
        "select inet_ntoa(st.src_ip) node_ip,count(distinct st.dst_ip) distinct_targets,count(distinct st.dst_port) distinct_target_ports,count(distinct st.protocol) distinct_protocols,count(distinct st.service) distinct_services,count(*) threat_records,sum(st.`count`) occurrences,max(coalesce(st.severity,0)) max_severity,min(st.created) first_seen,max(coalesce(st.lastSeen,st.created)) last_seen from syslogThreat st join partnerRouter pr on pr.ip=st.src_ip where unix_timestamp(coalesce(st.lastSeen,st.created)) > unix_timestamp(now())-? and (st.owner_id is null or coalesce(st.confirmed_unit_id,st.unit_id) is null) group by st.src_ip order by occurrences desc limit $nMaxRowsPerSection",
        [$cutoff], qw(node_ip distinct_targets distinct_target_ports distinct_protocols distinct_services threat_records occurrences max_severity first_seen last_seen));

    add_query_section($dbh, \$logs, 'destinations independently contacted by multiple known units',
        'dst_ip,dst_port,protocol,service,distinct_units,distinct_owners,occurrences,max_severity,unit_keys,first_seen,last_seen',
        "select inet_ntoa(dst_ip) dst_ip,dst_port,coalesce(protocol,'') protocol,coalesce(service,'') service,count(distinct concat(owner_id,':',coalesce(confirmed_unit_id,unit_id))) distinct_units,count(distinct owner_id) distinct_owners,sum(`count`) occurrences,max(coalesce(severity,0)) max_severity,group_concat(distinct concat(owner_id,':',coalesce(confirmed_unit_id,unit_id)) order by owner_id separator '|') unit_keys,min(created) first_seen,max(coalesce(lastSeen,created)) last_seen from syslogThreat where unix_timestamp(coalesce(lastSeen,created)) > unix_timestamp(now())-? and owner_id is not null and coalesce(confirmed_unit_id,unit_id) is not null group by dst_ip,dst_port,protocol,service having count(distinct concat(owner_id,':',coalesce(confirmed_unit_id,unit_id)))>=2 order by distinct_owners desc,distinct_units desc,occurrences desc limit $nMaxRowsPerSection",
        [$cutoff], qw(dst_ip dst_port protocol service distinct_units distinct_owners occurrences max_severity unit_keys first_seen last_seen));

    add_query_section($dbh, \$logs, 'owner-confirmed exact-unit hack report corroboration',
        'owner_id,unit_id,reports,reporting_networks,distinct_source_ports,max_severity,categories,first_seen,last_seen',
        "select ipOwnerId owner_id,remoteUnitId unit_id,sum(coalesce(`count`,1)) reports,count(distinct partnerIp) reporting_networks,count(distinct port) distinct_source_ports,max(coalesce(severity,0)) max_severity,group_concat(distinct coalesce(hrCategory,'other') order by hrCategory separator '|') categories,min(created) first_seen,max(coalesce(lastSeen,created)) last_seen from hackReport where unix_timestamp(coalesce(lastSeen,created)) > unix_timestamp(now())-? and ownerConfirmedTime is not null and ipOwnerId is not null and remoteUnitId is not null group by ipOwnerId,remoteUnitId order by reporting_networks desc,reports desc limit $nMaxRowsPerSection",
        [$cutoff], qw(owner_id unit_id reports reporting_networks distinct_source_ports max_severity categories first_seen last_seen));

    add_query_section($dbh, \$logs, 'unknown IP-only observations (lower-confidence identity evidence)',
        'source_ip,distinct_targets,threat_records,occurrences,max_severity',
        "select inet_ntoa(st.src_ip) source_ip,count(distinct st.dst_ip) distinct_targets,count(*) threat_records,sum(st.`count`) occurrences,max(coalesce(st.severity,0)) max_severity from syslogThreat st left join partnerRouter pr on pr.ip=st.src_ip where unix_timestamp(coalesce(st.lastSeen,st.created)) > unix_timestamp(now())-? and (st.owner_id is null or coalesce(st.confirmed_unit_id,st.unit_id) is null) and pr.ip is null group by st.src_ip having count(distinct st.dst_ip)>=? order by distinct_targets desc limit $nMaxRowsPerSection",
        [$cutoff,$nMinimumIpsVisited], qw(source_ip distinct_targets threat_records occurrences max_severity));

    $dbh->disconnect();

    $logs .= "\nREQUIRED OUTPUT CONTRACT:\n".
        "Return JSON only. Keep the existing top-level event_severity, category, confidence, summary, signals, reasoning, recommended_presumed_infected, owners_id_action, recommended_action and block_ssh fields.\n".
        "ALWAYS also return these arrays, even when empty: unit_assessments, node_assessments, ip_observations, botnet_clusters.\n".
        "unit_assessments items: owner_id, unit_id, confidence, severity, category, summary, evidence. Only create these from stable owner_id+unit_id evidence.\n".
        "node_assessments items: node_ip, confidence, severity, category, summary, evidence. Only create these for addresses listed in registered TaraSec/network node behavior.\n".
        "ip_observations items: source_ip, confidence, severity, category, summary, evidence. These are NOT units.\n".
        "botnet_clusters items: candidate_key, confidence, summary, members, evidence. Prefix member identities with unit:owner_id:unit_id or node:node_ip so identity classes are explicit.\n".
        "An IP address may NEVER appear in unit_id. A registered node may NEVER be represented in unit_assessments.\n";

    my $started = time();
    my $response = sendRequest($logs);
    return ($response, time()-$started);
}

my ($response,$elapsed)=requestAssessment();
my $rawResponse=$response->decoded_content;
my $decoded=decodeAiResponse($rawResponse);
print "\nDecoded response:\n$rawResponse\n\n";
print "Summary: ".($decoded->{summary}//'(none)')."\n";
print "Reasoning: ".($decoded->{reasoning}//'(none)')."\n";

my $dbh=getConnection();
my $sth=$dbh->prepare('insert into aiResponse (seconds,response) values (?,?)') or die $dbh->errstr."\n";
$sth->execute(int($elapsed+0.5),$rawResponse) or die $sth->errstr."\n";
$sth->finish();
$dbh->disconnect();
