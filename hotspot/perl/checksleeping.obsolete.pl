use strict;
use warnings;
use autodie;

my $szTempFile = "/home/setup/temp/temp.txt";

system("ps -aux | grep sleepingbeauty > $szTempFile");

open my $info, $szTempFile or die "Could not open $szTempFile: $!";
my $nBeautiesFound = 0;

while( my $line = <$info>)  {   
	print $line;    

	if (index($line, "sleepingbeauty.pl") != -1) {
		$nBeautiesFound++;
	}
}

close $info;

if ($nBeautiesFound > 1)
{
	print "$nBeautiesFound beauties found... exiting...\n";
	exit;
}







