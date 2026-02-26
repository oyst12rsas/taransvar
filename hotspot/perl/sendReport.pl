#Called as cronjob - first part is same as callCheckCert.pl... also make relevant changes there...
#perl /roow/wifi/perl/sendReport.pl

use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper;
use LWP::UserAgent;
use LWP::Simple;
use File::Fetch;

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";

print "Checking dir..\n";

my $dir = '/home';
opendir( DIR, $dir ) or die $!;
my $szUser = "";

while ( my $file = readdir(DIR) ) {	# && $szUser eq ""

# We only want dirs
   next unless ( -d "$dir/$file" );

# Use a regular expression to find files ending in .txt
#next unless ( $file =~ /\.pdf$/ );
	if ($file ne "." && $file ne ".." && $file ne "setup")
	{
		#Check if there's downloaded file...
		if ( -f "$dir/$file/Downloads/Taransvar.tar.gz" || -f "/home/$file/system.txt" )
		{
			print "$file\n";
			$szUser = $file;
		}
	}
}

closedir(DIR);

if ($szUser eq "") {
	print "\n*********** UNABLE TO FIND USER (downloaded file not found!)*******************\n";
	exit;
	return;
} 


my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
	my $nice_timestamp = sprintf ( "%04d%02d%02d_%02d%02d%02d",
                                   $year+1900,$mon+1,$mday,$hour,$min,$sec);
print "Time: $nice_timestamp\n";

#my $szTempdir = "/home/".$szUser."/_send23dfERW564";
my $szTempdir = "/root/_send23dfERW564";

if  (! -d $szTempdir ) {
8	mkdir $szTempdir;
}
my $database = "taransvar";
my $hostname = "localhost";
my $port = "3306";
my $user = "perl";
my $password = "RevSjoko731";

my $szSQL = "select user, sum(mb) from userusage group by user;";
my $szCmd = 'mysql -u '.$user.' --password='.$password.' '.$database.' --execute="'.$szSQL.'" > '.$szTempdir.'/usage.txt';

print "$szCmd\n";
system($szCmd);

my $szLast = $szSysRoot."log/cgitempfile.last";
system("cp $szLast $szTempdir");

open(my $fDivsetupH, '>>', $szTempdir.'/divsetup.txt') or die ...

my $ip = get "http://tnx.nl/ip";
say $fDivsetupH "$nice_timestamp: IP: $ip\n";

close $fDivsetupH;

system ("tar zcvf $szTempdir/report.tar.gz $szTempdir/");

#Find private key... 
my $szKeysList = $szSysRoot."temp/keyslist.txt";
#system ('sudo -i -u '.$szUser.' gpg --list-keys > '.$szKeysList);
system ('gpg --list-keys > '.$szKeysList);

open my $pKeysHandle, '<', $szKeysList;
chomp(my @cKeys = <$pKeysHandle>);
close $pKeysHandle;

my $szKeyname = "";

foreach (@cKeys) {
	my $szLine = $_;

	#if ($szLine =~ /\<(S*)\>/)
	if ($szLine =~ /(\w*)@(\w*)/)
	{
		$szKeyname = $1;
		#my $szFound = substr $2, 1;
		print "Key found: $szKeyname\n";
	} #else {
	#	print "No match: $szLine \n";
	#}
}

#NOTE! Should read the system hash.....
my $szSysSetupFile = $szSysRoot."setup.txt";
#Format: "sysdata|$string|$nice_timestamp\n";
open my $pSetupFile, '<', $szSysSetupFile;
chomp(my @sysLines = <$pSetupFile>);
close $pSetupFile;
#read the first line...
my $szLine = $sysLines[0];
my $szHash = "hashnotfound";
my $szTimestamp = "timenotfoune";
print "The line: $szLine\n";
if ($szLine =~ /sysdata\|(\S*)\|(\S*)/) {
	$szHash = $1;
	$szTimestamp = $2;
	print "***Found it... \n";
}

#my $szGpgFile = "/home/$szUser/report.$szHash.$szTimestamp.$nice_timestamp.gpg";
my $szGpgFile = "/root/report.$szHash.$szTimestamp.$nice_timestamp.gpg";

if  ( -e $szGpgFile ) {
	unlink $szGpgFile;
}

print "System Hash: $szHash\nTimestamp: $szTimestamp\n";

print "NOT! Calling sendfile as $szUser...(note: Not anymore, now root\n\n";
$szCmd = "gpg --output $szGpgFile --encrypt --trust-model always --recipient $szKeyname $szTempdir/report.tar.gz";
print $szCmd;

#system ('sudo -i -u '.$szUser.' '.$szCmd);
system ($szCmd);


my $ua = LWP::UserAgent->new;
#my $url = 'http://cyberrehab.org/tmp3k5kfs9fkbsde23';
my $url = "http://cyberrehab.org/tmp3k5kfs9fkbsde23/receiveupload.php";

# The name of the file input field on the HTML form/
# You'll need to change this to whatever the correct name is.
my $file_input = 'file-input'; #"userfile";#

# Path to the local file that you want to upload

my $req = $ua->post($url,
  Content_Type => 'form-data',
  Content => [
     $file_input => [ $szGpgFile ],
  ],
);

#print "Reply: $req\n";

print Dumper(\$req);

#Download files
$url = 'http://cyberrehab.org/tmp3k5kfs9fkbsde23/LEAVETHIS_453DRTYttweree.txt';
my $ff = File::Fetch->new(uri => $url);
my $file = $ff->fetch() or die $ff->error;
