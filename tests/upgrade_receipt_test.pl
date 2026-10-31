use strict;
use warnings;
use FindBin;
use lib "$FindBin::Bin/../misc";
use upgrade_status qw(softwareForReport upgradeReceipt);
use JSON qw(encode_json decode_json);
use MIME::Base64 qw(encode_base64);
use File::Temp qw(tempdir);
{
    package Receipt;
    sub new { bless {raw=>$_[1]},$_[0] }
    sub is_success { 1 }
    sub header { $_[0]->{raw} }
}
my $dir=tempdir(CLEANUP=>1);
my $raw=encode_base64(encode_json({catalog_status=>'ok',pending=>[{id=>'fix',minimum_version=>2,severity=>'security',note=>'fix'}]}),'');
my $response=Receipt->new($raw);
upgradeReceipt($response,0,$dir);
die 'Secondary DB must not write receipt' if -e "$dir/notice.json";
upgradeReceipt($response,1,$dir);
open(my $fh,'<',"$dir/notice.json") or die 'Primary DB receipt missing';
my $receipt=decode_json(do {local $/; <$fh>});close($fh);
die 'Missing local freshness' unless $receipt->{checked_at} > time()-10;
die 'Lost advisory' unless $receipt->{pending}->[0]->{id} eq 'fix';
die 'Missing versions must be unknown' if defined softwareForReport("$dir/missing")->{version};
print "Upgrade heartbeat checks passed\n";

# Optional automatic-maintenance evidence must never break the main report.
my $software="$dir/software.json";
open(my $out,'>',$software) or die $!;
print $out encode_json({version=>7}); close($out);
my $snapshot="$dir/automatic.json";
for my $raw ('', '{broken', '[]', 'x' x 8193) {
    open($out,'>',$snapshot) or die $!; print $out $raw; close($out);
    my $value=softwareForReport($software,$snapshot);
    die 'Optional bad snapshot lost software report' unless $value->{version}==7;
    die 'Bad snapshot accepted' if exists $value->{automatic_upgrade};
}
open($out,'>',$snapshot) or die $!;
print $out encode_json({enrolled=>JSON::true,checked_at=>time()});close($out);
my $value=softwareForReport($software,$snapshot);
die 'Valid independent snapshot not attached' unless $value->{automatic_upgrade}{enrolled};
unlink($snapshot);
die 'Missing snapshot lost software report' unless softwareForReport($software,$snapshot)->{version}==7;
print "Optional snapshot isolation checks passed\n";
