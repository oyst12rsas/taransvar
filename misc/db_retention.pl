#!/usr/bin/perl
use strict;
use warnings;
use FindBin;
use lib $FindBin::Bin;
use Getopt::Long qw(GetOptions);
use JSON::PP;
use lib_retention qw(read_policy run_retention);

my ($apply, $help);
my $config = '/etc/tarasec-retention.conf';
my $state_path = '/var/lib/tarasec-retention/progress.json';
GetOptions('apply' => \$apply, 'config=s' => \$config, 'state=s' => \$state_path, 'help' => \$help)
    or die "Use --help for usage\n";
die "Unexpected arguments\n" if @ARGV;
if ($help) {
    print "db_retention.pl [--apply] [--config FILE] [--state FILE]\nDefault: bounded preview; --apply deletes eligible old telemetry.\n";
    exit;
}
my $cfg = read_policy($config);
if (!$cfg->{ENABLED}) { print "Retention disabled by local policy\n"; exit; }
require DBI;
require func;
my $dbh = func::getConnection();
$dbh->{RaiseError} = 1;
$dbh->{PrintError} = 0;
$dbh->{AutoCommit} = 1;
my ($lock) = $dbh->selectrow_array("SELECT GET_LOCK('taransvar:telemetry-retention', 0)");
if (!$lock) { print "Retention already running\n"; exit; }
my $ok = eval {
    $dbh->do('SET SESSION innodb_lock_wait_timeout=3');
    $dbh->do('SET SESSION lock_wait_timeout=3');
    my ($version) = $dbh->selectrow_array('SELECT VERSION()');
    $dbh->do('SET SESSION max_statement_time=5') if $version =~ /MariaDB/i;
    my $state = {};
    if ($apply && -e $state_path) {
        open my $fh, '<', $state_path or die "Cannot read progress: $!\n";
        local $/;
        $state = decode_json(<$fh>);
        close $fh;
        die "Invalid progress file\n" unless ref($state) eq 'HASH';
    }
    print $apply ? "Applying telemetry retention\n" : "Preview only; no rows will be deleted\n";
    run_retention($dbh, $cfg, $state, $apply);
    if ($apply) {
        my $temp = "$state_path.$$";
        open my $fh, '>', $temp or die "Cannot save progress: $!\n";
        print $fh encode_json($state);
        close $fh or die "Cannot close progress: $!\n";
        rename $temp, $state_path or die "Cannot replace progress: $!\n";
    }
    1;
};
my $error = $@;
$dbh->selectrow_array("SELECT RELEASE_LOCK('taransvar:telemetry-retention')");
$dbh->disconnect;
die "Retention stopped: $error" unless $ok;
