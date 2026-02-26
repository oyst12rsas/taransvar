use strict;
use warnings;
use autodie;
use File::Fetch;

#Download files
my $url = 'http://cyberrehab.org/tmp3k5kfs9fkbsde23/LEAVETHIS_453DRTYttweree.txt';
my $ff = File::Fetch->new(uri => $url);
my $szTarget = "/root/tmp";	#mkdir not working with "~/tmp"
if ( -e $szTarget){
	print "\nTempdir exists.. Skipping mkdir\n";
} else {
	mkdir($szTarget);
}

my $file = $ff->fetch( to => $szTarget ) or die $ff->error;
