use strict;
use warnings;
use DBI;
use FindBin;
use JSON::PP;
require "$FindBin::Bin/../misc/operations_manual_issues.pl";
my $port=$ENV{ISSUE_TEST_PORT} || 3307;
my $db=DBI->connect("DBI:mysql:database=tarasec_issue_test;host=127.0.0.1;port=$port",'root',$ENV{ISSUE_TEST_PASSWORD} || '',{RaiseError=>1,PrintError=>0,AutoCommit=>1});
my $item={key=>'service:local-test.service',incarnation=>'e'x32,discovered_at=>100,last_seen_at=>100,checked_at=>100,
    description=>'Failed local service',status=>'open',verification=>'service',next_action_at=>100,
    command_executable=>'/bin/tool',commands=>['/bin/tool','PRIVATE_ARGUMENT'],
    history=>[{event_id=>1,at=>100,kind=>'discovered',detail=>'Failure observed'}]};
my $result=sync_local_issues($db,[$item]);
die 'Missing local receipt' unless $result->{acknowledgements}{$item->{incarnation}} == 1;
sync_local_issues($db,[$item]);
my $row=$db->selectrow_hashref('SELECT * FROM issue WHERE issueKey=?',undef,$item->{key});
my ($count)=$db->selectrow_array('SELECT COUNT(*) FROM issueReport WHERE issueId=?',undef,$row->{issueId});
die 'Duplicate discovery' unless $count == 1;
die 'Arguments leaked' if $row->{commands} =~ /PRIVATE_ARGUMENT/;
$item->{status}='solved'; $item->{checked_at}=200; $item->{solved_at}=200;
$item->{history}=[{event_id=>2,at=>200,kind=>'verified_solved',detail=>'Fresh service check passed'}];
sync_local_issues($db,[$item]);
$item->{status}='open'; $item->{checked_at}=100; $item->{solved_at}=undef;
sync_local_issues($db,[$item]);
($count)=$db->selectrow_array('SELECT COUNT(*) FROM issue WHERE issueKey=? AND status="solved"',undef,$item->{key});
die 'Stale snapshot reopened solved issue' unless $count == 1;
my $manual=$db->selectrow_hashref('SELECT * FROM issue WHERE verification="manual" AND status="solved" LIMIT 1');
my $stale={%$item,key=>$manual->{issueKey},verification=>'manual',incarnation=>substr($manual->{issueKey},7),checked_at=>time()+100,history=>[]};
sync_local_issues($db,[$stale]);
($count)=$db->selectrow_array('SELECT COUNT(*) FROM issue WHERE issueId=? AND status="solved"',undef,$manual->{issueId});
die 'Admin closure overwritten' unless $count == 1;
$item->{checked_at}=300; $item->{history}=[{event_id=>3,at=>300,kind=>'progress',detail=>'x'x8001}];
eval { sync_local_issues($db,[$item]); };
die 'Expected oversized event rollback' unless $@;
($count)=$db->selectrow_array('SELECT COUNT(*) FROM issue WHERE issueKey=? AND status="solved"',undef,$item->{key});
die 'Failed batch did not roll back' unless $count == 1;
print "Local automated/manual issue storage, receipts, replay and rollback checks passed\n";
