use strict;
use warnings;
use autodie;
use DBI;

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";

my $szTempFile = $szSysRoot."temp/temp.txt";

print "Checking if system is running properly... please wait..\n"; 
my $nCounts = 0;


while (1)
{
    system("ps -aux | grep sleepingbeauty > $szTempFile");

    open my $info, $szTempFile or die "Could not open $szTempFile: $!";
    my $nBeautiesFound = 0;

    while( my $line = <$info>)  {   
        #if (index($line, "sleepingbeauty.pl") != -1) 
        #if ($line =~ /\d:\d\d perl \S*perl\/sleepingbeauty/)
        if ($line =~ /perl \S*perl\/sleepingbeauty/)
        {
	        print "Found: $line";    
		    $nBeautiesFound++;
	    } else {
            print "Not..: $line";    
        }
    }

    close $info;
    my $szDatestring = gmtime();

    if ($nBeautiesFound)
    {
	    print "System is running properly exiting $szDatestring...\n";
	    exit;
    } else {
        $nCounts++;

        if ($nCounts == 3) {
            system('service cron restart');
    	    print "$szDatestring: Checked $nCounts time(s) and not yet running properly... Restarting task scheduler. Please wait 60 seconds more...\n";
        } else {
    	    print "$szDatestring: Checked $nCounts time(s) and not yet running properly... please wait 60 seconds more...\n";
        }
    }
	sleep(60);
}
