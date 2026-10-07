use strict;
use warnings;
use FindBin;
use lib "$FindBin::Bin/../misc";
use DBI;
use lib_retention qw(read_policy run_retention);
use Test::More;

# Disposable CI database only. Never point this at a deployed TaraSec database.
my $dbh = DBI->connect('DBI:mysql:host=127.0.0.1;port=' . ($ENV{RETENTION_TEST_PORT} // 3307),
    'root', $ENV{RETENTION_TEST_PASSWORD} // 'retention-test-only',
    {RaiseError=>1,PrintError=>0,AutoCommit=>1});
$dbh->do('CREATE DATABASE IF NOT EXISTS retention_test');
$dbh->do('USE retention_test');
for my $t (qw(customEvidence partnerObservationLocalEvidence demoSshEvent demoSshSession syslogThreat syslog traffic dhcpEvent partnerRouterStatusLog managerRequest)) {
    $dbh->do("DROP TABLE IF EXISTS $t");
}
my @ddl = (
    'CREATE TABLE traffic(trafficId INT PRIMARY KEY, created DATETIME,lastSeen DATETIME)',
    'CREATE TABLE customEvidence(id INT PRIMARY KEY,flow INT,FOREIGN KEY(flow) REFERENCES traffic(trafficId) ON DELETE CASCADE)',
    'CREATE TABLE syslog(syslogId INT PRIMARY KEY,created DATETIME,lastSeen DATETIME,handled BIT)',
    'CREATE TABLE syslogThreat(syslogThreatId INT PRIMARY KEY,syslogId INT,created DATETIME,lastSeen DATETIME,handled BIT,demoSshSessionId INT)',
    'CREATE TABLE demoSshSession(id INT PRIMARY KEY,nodeAEvidenceId INT,nodeBEvidenceId INT)',
    'CREATE TABLE demoSshEvent(id INT PRIMARY KEY,syslogThreatId INT)',
    'CREATE TABLE partnerObservationLocalEvidence(id INT PRIMARY KEY,syslogId INT)',
    'CREATE TABLE dhcpEvent(dhcpEventId INT PRIMARY KEY,seenAt DATETIME,handled BIT)',
    'CREATE TABLE partnerRouterStatusLog(ip INT UNSIGNED,created DATETIME,PRIMARY KEY(ip,created))',
    'CREATE TABLE managerRequest(id INT PRIMARY KEY,created DATETIME)',
);
$dbh->do($_) for @ddl;
my $old = '2000-01-01 00:00:00';
my ($now) = $dbh->selectrow_array('SELECT NOW()');
for my $r ([1,$old,undef],[2,$old,$now],[3,$now,undef],[4,$old,undef],[5,$old,undef]) {
    $dbh->do('INSERT INTO traffic VALUES(?,?,?)',undef,@$r);
}
$dbh->do('INSERT INTO customEvidence VALUES(1,4)');
for my $i (1..8) {
    $dbh->do("INSERT INTO syslog VALUES(?,?,NULL,b'0')",undef,$i,$old);
}
# 1: deletable; 2: pending null; 3: demo; 4/5: session evidence;
# 6: event evidence; 7: pending zero; 8: recently observed.
for my $i (1..8) {
    $dbh->do('INSERT INTO syslogThreat VALUES(?,?,?,?,?,?)',undef,
        $i,$i,$old,($i==8 ? $now : undef),($i==2 ? undef : $i==7 ? 0 : 1),($i==3 ? 7 : undef));
}
$dbh->do('INSERT INTO demoSshSession VALUES(1,4,5)');
$dbh->do('INSERT INTO demoSshEvent VALUES(1,6)');
$dbh->do('INSERT INTO partnerObservationLocalEvidence VALUES(1,1)');
$dbh->do('INSERT INTO syslog VALUES(9,?,NULL,0)',undef,$old);
$dbh->do('INSERT INTO dhcpEvent VALUES(1,?,1),(2,?,0)',undef,$old,$old);
$dbh->do('INSERT INTO partnerRouterStatusLog VALUES(1,?),(1,?),(2,?)',undef,$old,$now,$old);
$dbh->do('INSERT INTO managerRequest VALUES(1,?)',undef,$old);
my $cfg = read_policy(undef);
$cfg->{BATCH_ROWS}=2;
$cfg->{BATCHES_PER_TABLE}=1;
my @log;
run_retention($dbh,$cfg,{},0,sub{push @log,shift});
is_deeply($dbh->selectcol_arrayref('SELECT trafficId FROM traffic ORDER BY trafficId'),[1..5], 'preview makes no deletion');
my $state={};
run_retention($dbh,$cfg,$state,1,sub{});
is_deeply($dbh->selectcol_arrayref('SELECT trafficId FROM traffic ORDER BY trafficId'),[2,3,4,5], 'bounded first batch, active row retained');
is($state->{cursors}{traffic},2,'persistent scan progress');
run_retention($dbh,$cfg,$state,1,sub{}) for 1..12;
is_deeply($dbh->selectcol_arrayref('SELECT trafficId FROM traffic ORDER BY trafficId'),[2,3,4], 'wraparound preserves active and referenced flows');
is_deeply($dbh->selectcol_arrayref('SELECT syslogThreatId FROM syslogThreat ORDER BY syslogThreatId'),[2..8], 'pending, active and demo evidence preserved');
is_deeply($dbh->selectcol_arrayref('SELECT syslogId FROM syslog ORDER BY syslogId'),[1..8], 'raw pending and observation evidence preserved');
is_deeply($dbh->selectcol_arrayref('SELECT dhcpEventId FROM dhcpEvent'),[2], 'unhandled DHCP preserved');
is($dbh->selectrow_array('SELECT COUNT(*) FROM partnerRouterStatusLog'),1,'composite key scanning deletes old status history');
is($dbh->selectrow_array('SELECT COUNT(*) FROM managerRequest'),1,'identity tables untouched');
is($dbh->selectrow_array('SELECT COUNT(*) FROM customEvidence'),1,'foreign-key cascade not triggered');
$dbh->do('UPDATE traffic SET lastSeen=? WHERE trafficId=2',undef,$old);
run_retention($dbh,$cfg,$state,1,sub{}) for 1..5;
is_deeply($dbh->selectcol_arrayref('SELECT trafficId FROM traffic ORDER BY trafficId'),[3,4],'retained rows revisited when they become stale');
$dbh->disconnect;
done_testing;
