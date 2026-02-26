use strict;
use warnings;
use autodie;
use DBI;

use lib ('/root/taransvar/perl');
use func;	#NOTE! See comment above regarding lib..

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";

my $szTempFile = $szSysRoot."temp/temp.txt";
my $szLogFile = $szSysRoot."log/sleeping.txt";

my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst) = localtime(time);

open(my $fLogH, '>', $szLogFile) or die "Could not open log file '$szLogFile' $!";
print $fLogH "sleepingbeauty started $hour:$min:$sec\n";

system("ps -aux | grep sleepingbeauty > $szTempFile");

open my $info, $szTempFile or die "Could not open $szTempFile: $!";
my $nBeautiesFound = 0;

while( my $line = <$info>)  {   
	print $line;    

    #if (index($line, "sleepingbeauty.pl") != -1) 
    if ($line =~ /\d:\d\d \S*perl \S*perl\/sleepingbeauty/)
    {
		$nBeautiesFound++;
	}
}

close $info;

if ($nBeautiesFound > 1)
{
    my $szTxt = "$nBeautiesFound beauties found (including this one)... exiting...\n";
	print $szTxt;
    print $fLogH $szTxt;

	exit;
} else {
    my $szTxt = "$nBeautiesFound beauty found (this one)... continuing...\n";
	print $szTxt;
    print $fLogH $szTxt;
}

close $fLogH;

while (1)
{
	print "Calling getConnection()\n";
	my $dbh = getConnection();
	print "After called\n";

	#Read setup...

	my $sth = $dbh->prepare("select CAST(requiresAccessUpdate AS UNSIGNED) as requiresAccessUpdate, if(now() > date_add(lastAccessUpdate, INTERVAL 2 MINUTE),1,0) as TimeToUpdate, coalesce(maintenanceRequest,'') as maintenanceRequest from hotspotSetup limit 1")
		or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $dbh->errstr()";
	if (my $cSetup = $sth->fetchrow_hashref()) 
	{
		my $bRequiresUpdate  = $cSetup->{'requiresAccessUpdate'};
		my $bTimeToUpdate = $cSetup->{'TimeToUpdate'};
        	my $szSQL;
		
		if ($bRequiresUpdate + $bTimeToUpdate) {
			system("perl ".$szSysRoot."perl/readfwrules.pl");
			system("perl ".$szSysRoot."perl/db.pl >> ".$szSysRoot."log/cronlog.txt");

			$szSQL = "update hotspotSetup set requiresAccessUpdate = b'0', lastAccessUpdate = now(), lastAccessUpdatePoll = now()";


        		my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst) = localtime(time);

			open(my $fLogH, '>>', $szLogFile) or die "Could not open log file '$szLogFile' $!";
			print $fLogH "Access updated $hour:$min:$sec\n";
			close $fLogH;

		} else {
			$szSQL = "update hotspotSetup set lastAccessUpdatePoll = now()";
		}

		my $hUpdate = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$hUpdate->execute() or die "execution failed: $dbh->errstr()";
		$hUpdate->finish();

		
		if ($cSetup->{'maintenanceRequest'} ne '')
		{
			my $szRequest = $cSetup->{'maintenanceRequest'};
			print "**** Maintenance request found: $szRequest Processing....\n";

			if ($szRequest eq "setup_network")
			{
				system("perl ".$szSysRoot."perl/setup_network.pl > /var/www/html/temp/maintenanceRequest.txt");
			} else {
				if ($szRequest eq "debugserver")
				{
					system("perl ".$szSysRoot."perl/syssnapshot.pl > /var/www/html/temp/maintenanceRequest.txt");
				}
			}

			my $hUpdate = $dbh->prepare("update setup set maintenanceRequest = null")
				or die "prepare statement failed: $dbh->errstr()";

			$hUpdate->execute() or die "execution failed: $dbh->errstr()";
			$hUpdate->finish();
			print "**** Finished processing maintenance request....\n";

			my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst) = localtime(time);
			open(my $fLogH, '>>', $szLogFile) or die "Could not open log file '$szLogFile' $!";
			print $fLogH "Maintenance request handled $hour:$min:$sec: ".$cSetup->{'maintenanceRequest'}."\n";
			close $fLogH;
		}
	}

	$sth->finish();
	$dbh->disconnect;

	sleep(5);
}
