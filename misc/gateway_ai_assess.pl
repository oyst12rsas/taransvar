#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use HTTP::Tiny;
use JSON::PP qw(encode_json decode_json);
use Time::HiRes qw(time);
use func;

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

sub response_detail {
    my ($res) = @_;
    my $body = $res->{content} // '';
    $body =~ s/\s+/ /g;
    $body = substr($body, 0, 1000) if length($body) > 1000;
    return 'HTTP '.($res->{status}//0).' '.($res->{reason}//'').($body ne '' ? " response=$body" : '');
}

sub post_json {
    my ($http,$url,$data) = @_;
    return $http->post($url, {
        headers => {'content-type'=>'application/json'},
        content => encode_json($data),
    });
}

sub get_json {
    my ($http,$url) = @_;
    my $res = $http->get($url);
    return ($res, undef) unless $res->{success};
    my $json = eval { decode_json($res->{content} // '') };
    return ($res, $@ ? undef : $json);
}

sub append_query {
    my ($dbh,$text_ref,$title,$sql,@bind) = @_;
    my $sth = $dbh->prepare($sql);
    $sth->execute(@bind);
    $$text_ref .= "\nRECORDS: $title\n";
    my $count = 0;
    while (my $row = $sth->fetchrow_hashref()) {
        $$text_ref .= encode_json($row)."\n";
        last if ++$count >= 150;
    }
    $sth->finish();
    return $count;
}

my $cfg = read_tarasec_config();
my $dbh = getConnection();

my $sth = $dbh->prepare(q{
    SELECT isGlobalDbServer+0 isGlobalDbServer,
           isDbServer+0 isDbServer,
           INET_NTOA(globalDb1ip) globalDb1ip,
           nickname
      FROM setup LIMIT 1
});
$sth->execute();
my $setup = $sth->fetchrow_hashref() || {};
$sth->finish();

if (($setup->{isGlobalDbServer} // 0) || ($setup->{isDbServer} // 0)) {
    print "Gateway AI skipped on DB server.\n";
    $dbh->disconnect();
    exit 0;
}

my $dbIp = $setup->{globalDb1ip} // '';
my $base = $cfg->{TARASEC_GLOBAL_AI_BASE_URL} // '';
$base =~ s{/+$}{};
$base = "http://$dbIp/script" if $base eq '' && $dbIp ne '';
die "No global DB AI endpoint configured. Set TARASEC_GLOBAL_AI_BASE_URL or globalDb1ip.\n" if $base eq '';

my $http = HTTP::Tiny->new(timeout=>100, agent=>'TaraSec-Gateway-AI/1.1');
my ($policyRes,$policy) = get_json($http, "$base/gatewayAiPolicy.php");
die "AI policy request failed: ".response_detail($policyRes)."\n"
    unless $policyRes->{success} && ref($policy) eq 'HASH' && $policy->{ok};

my $mode = $policy->{mode} // 'owner_funded';
if ($mode ne 'tarasec_test') {
    print "Gateway AI policy is $mode; centrally funded test call skipped.\n";
    $dbh->disconnect();
    exit 0;
}

my ($contextRes,$globalContext) = get_json($http, "$base/gatewayAiContext.php");
$globalContext = {ok=>0,note=>'Global context unavailable for this run'}
    unless $contextRes->{success} && ref($globalContext) eq 'HASH';

my $question = "TARASEC GATEWAY-LOCAL SECURITY ASSESSMENT\n".
               "Gateway: ".($setup->{nickname}//'unnamed')."\n".
               "This assessment concerns this gateway and its local units.\n\n".
               "IDENTITY AND DIRECTION RULES:\n".
               "- A customer/LAN unit exists only when owner_id + unit_id are both known.\n".
               "- Every row under 'local stable unit summaries' and 'local unit destination fingerprints' describes activity ORIGINATING FROM a unit managed by THIS installation.\n".
               "- Therefore repeated scanning, probing, brute force, exploit attempts or unusual fan-out in those local-unit sections means one of THIS OWNER'S managed units is generating the suspicious traffic; it is not merely an arbitrary external source.\n".
               "- When that happens, the summary MUST say this explicitly, for example: 'ALERT: a managed local unit on this installation is originating repeated SSH probes and may be compromised.' Do not reduce this to vague wording such as 'the same source'.\n".
               "- Treat suspicious outbound behaviour from a stable local unit as materially more important to this gateway owner than the same pattern from an unidentified external IP. Reflect that distinction in event_severity, summary, reasoning and recommended_action.\n".
               "- Never put an IP address in unit_id.\n".
               "- IP-only observations are observations, not units.\n".
               "- Central context is supporting evidence and may describe activity outside this gateway.\n\n";

my %evidence = (gateway=>($setup->{nickname}//''), window_days=>7);
$evidence{unit_summary_rows} = append_query($dbh, \$question, 'local stable unit summaries', q{
    SELECT owner_id,COALESCE(confirmed_unit_id,unit_id) unit_id,
           COUNT(DISTINCT src_ip) source_ips,COUNT(DISTINCT dst_ip) targets,
           COUNT(DISTINCT dst_port) ports,SUM(`count`) occurrences,
           MAX(COALESCE(severity,0)) max_severity,
           MIN(created) first_seen,MAX(COALESCE(lastSeen,created)) last_seen
      FROM syslogThreat
     WHERE COALESCE(lastSeen,created)>=NOW()-INTERVAL 7 DAY
       AND owner_id IS NOT NULL AND COALESCE(confirmed_unit_id,unit_id) IS NOT NULL
     GROUP BY owner_id,COALESCE(confirmed_unit_id,unit_id)
     ORDER BY occurrences DESC LIMIT 150
});

$evidence{unit_destination_rows} = append_query($dbh, \$question, 'local unit destination fingerprints', q{
    SELECT owner_id,COALESCE(confirmed_unit_id,unit_id) unit_id,
           INET_NTOA(dst_ip) dst_ip,dst_port,COALESCE(protocol,'') protocol,
           COALESCE(service,'') service,SUM(`count`) occurrences,
           MAX(COALESCE(severity,0)) max_severity
      FROM syslogThreat
     WHERE COALESCE(lastSeen,created)>=NOW()-INTERVAL 7 DAY
       AND owner_id IS NOT NULL AND COALESCE(confirmed_unit_id,unit_id) IS NOT NULL
     GROUP BY owner_id,COALESCE(confirmed_unit_id,unit_id),dst_ip,dst_port,protocol,service
     ORDER BY occurrences DESC LIMIT 150
});

$evidence{unknown_ip_rows} = append_query($dbh, \$question, 'local IP-only observations', q{
    SELECT INET_NTOA(src_ip) source_ip,COUNT(DISTINCT dst_ip) targets,
           COUNT(*) records,SUM(`count`) occurrences,MAX(COALESCE(severity,0)) max_severity
      FROM syslogThreat
     WHERE COALESCE(lastSeen,created)>=NOW()-INTERVAL 7 DAY
       AND (owner_id IS NULL OR COALESCE(confirmed_unit_id,unit_id) IS NULL)
     GROUP BY src_ip ORDER BY occurrences DESC LIMIT 100
});

$evidence{confirmed_report_rows} = append_query($dbh, \$question, 'owner-confirmed exact-unit reports', q{
    SELECT ipOwnerId owner_id,remoteUnitId unit_id,SUM(COALESCE(`count`,1)) reports,
           COUNT(DISTINCT partnerIp) reporting_networks,MAX(COALESCE(severity,0)) max_severity,
           GROUP_CONCAT(DISTINCT COALESCE(hrCategory,'other') ORDER BY hrCategory SEPARATOR '|') categories
      FROM hackReport
     WHERE COALESCE(lastSeen,created)>=NOW()-INTERVAL 7 DAY
       AND ownerConfirmedTime IS NOT NULL AND ipOwnerId IS NOT NULL AND remoteUnitId IS NOT NULL
     GROUP BY ipOwnerId,remoteUnitId ORDER BY reports DESC LIMIT 100
});

$question .= "\nGLOBAL TARASEC CONTEXT (aggregated; do not reinterpret as local identity):\n".
             encode_json($globalContext)."\n";
$question .= "\nREQUIRED OUTPUT CONTRACT:\n".
             "Return JSON only. Include event_severity, category, confidence, summary, signals, reasoning, recommended_action.\n".
             "Always include arrays unit_assessments, node_assessments, ip_observations, botnet_clusters even when empty.\n".
             "unit_assessments items require owner_id and unit_id and may include confidence,severity,category,summary,evidence.\n".
             "For suspicious local-unit-originated activity, explicitly identify owner_id:unit_id in the relevant unit_assessment and make clear it is a managed unit on THIS installation.\n".
             "ip_observations are never units. node_assessments are for known TaraSec/network infrastructure only.\n".
             "botnet_clusters must distinguish member identity classes explicitly.\n";

my $started = time();
my $assessRes = post_json($http, "$base/aiGatewayAssess.php", {question=>$question});
die "Sponsored gateway AI call failed: ".response_detail($assessRes)."\n"
    unless $assessRes->{success};
my $reply = eval { decode_json($assessRes->{content}//'') };
die "Invalid sponsored AI response JSON: ".response_detail($assessRes)."\n"
    if $@ || ref($reply) ne 'HASH' || !$reply->{ok};
my $assessment = $reply->{assessment};
die "Sponsored AI response contained no assessment.\n" unless ref($assessment) eq 'HASH';
my $elapsed = int(time() - $started + 0.5);

my $localEnvelope = {
    source => 'gateway_local',
    fundingMode => 'tarasec_test',
    gatewayAssessmentId => ($reply->{gatewayAssessmentId}//''),
    quota => $reply->{quota},
    assessment => $assessment,
};
my $localJson = encode_json($localEnvelope);

# aiResponse is the authoritative local history. setup.aiAssessment is retained
# only as a compatibility mirror for older Gatekeeper/App code.
$sth = $dbh->prepare('INSERT INTO aiResponse (seconds,response) VALUES (?,?)');
$sth->execute($elapsed,$localJson);
my $localResponseId = $dbh->{mysql_insertid};
$sth->finish();

$sth = $dbh->prepare('UPDATE setup SET aiAssessment=?, aiAssessmentTime=NOW()');
$sth->execute($localJson);
$sth->finish();
$dbh->disconnect();

my $reportRes = post_json($http, "$base/aiGatewayReport.php", {
    fundingMode => 'tarasec_test',
    gatewayAssessmentId => ($reply->{gatewayAssessmentId}//''),
    assessment => $assessment,
    evidenceSummary => \%evidence,
});
if (!$reportRes->{success}) {
    warn "AI assessment saved as local aiResponse #$localResponseId, but report-back failed: ".response_detail($reportRes)."\n";
    exit 2;
}

print "Gateway AI assessment complete; saved as aiResponse #$localResponseId and reported to DB server.\n";
print "Quota: ".encode_json($reply->{quota}//{})."\n";
