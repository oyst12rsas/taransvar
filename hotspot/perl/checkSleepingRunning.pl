use strict;
use warnings;
use autodie;
use DBI;

sub sleepingBeautyCount {
	#NOTE! This function is in both checkSleepingRunning.pl and sleepingBeauty.pl (put in lib file)
        my @pids = `pgrep -f 'perl .*sleepingbeauty\\.pl'`;
        chomp @pids;
        my $nBeautiesFound = scalar @pids;

        print "Running sleepingbeauty instances: $nBeautiesFound\n";
        return $nBeautiesFound;
}


#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";

my $szTempFile = $szSysRoot."temp/temp.txt";

print "Checking if system is running properly... please wait..\n"; 
my $nCounts = 0;


while (1)
{

    my $nBeautiesFound = sleepingBeautyCount();

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
