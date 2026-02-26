#crontasks
#cron is the linux system for scheduled tasks. To schedule tasks, issue:  
#sudo crontab -u root -e
#To run it every 5 minutes, add this:
#* * * * * sudo perl <insert correct path>/crontasks.pl
#NOTE! This script needs to load programming/misc/func.pm. For that, use lib statement below should be set properly.. 
#Great if you test the generic ones.. If they're not working, you may have to hard code (like I had to).  
#NOTE! Since this script is run as cron task, and from other dir, the path has to be hardcoded. diagnose.pl will change <developer> to /<your user name>/

#use lib ('.');
use lib ('/root/taransvar/perl');
		
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..
use lib_dhcp;
use lib_cron;
use lib_net;

my $nSecondsToSleepBetweenIterations = 5;
my $nNumberOfWhoIsLookupsPerIteration = 5;	#Increase if too few have owner name in traffic list in http://localhost/index.php?f=traffic

my $dbh = getConnection();
setCronLibDbh($dbh);

#if (!$ARGV[0]) {
if (!runningAsCron() && !runningBootCheck())	#Run "sudo perl crontasks.pl whatever_except_cron_and_boot" to run this section. 
{
        #To debug crontasks.pl, best way is to put your code here.... 
	saveWarning("Debugging crontasks.pl or crontab is not set to run crontasks.pl with cron as parameter.");
	#TO DEBUG crontasks.pl, do as follows:
	#- Remove the "#" in front of the saveWarning() and the exit call below (this line + 5?)
	#  That will make crontasks.pl run as cron job exit at this point and place a warning in your dashboard so you don't forget to enable it again
	# - Run crontasks.pl manually with: sudo crontasks.pl sometext
	#  That way you can check any debug code without the cron job distrubing the process.
	#Displays a warning in dashboard so don't forget to disable this code...

	#print (networkSetupOk()?"Network set up properly":"Failed to set up network!");

checkRequests();
	#startTaraLinkOk();
        #handleConntrack($dbh);
	#process_dhcpdump($dbh);	#NOTE! Maybe it's risky to run it this often?
	#checkDbVersion($dbh);
	
	#workshopSetup();
	#dhcpServerStatusOk();
	#doKill("taralink");
	#logDmesg();
	#checkWhoIs($dbh, $nNumberOfWhoIsLookupsPerIteration);
	#sendPendingWgets();
	#checkNetworkSetup();
	#startFirewall();
	#dhcpServerStatusOk();
        #handleConntrack($dbh);
	#setupPortForwarding();
	#print "***** System ".(systemBooted()?"booted since last run.":"did NOT boot since last run.")."\n";
	
	#if (!networkSetupOk()) {
	#	print "************ NETWORK SETUP NOT OK. ABORTING ************\n";
	#} else {
	#	print "******* Network setup ok *************\n";
	#}
	
	#startTaraSystemsOk();	
	print "Finishing debugging code.. To run as crontasks.pl would, add \"cron\" as parameter\n";
	exit;
}

my $nTimeStarted = time();
#my $szSysRoot = "/root/setup";
#my $szLogRoot = $szSysRoot."/log/";
#my $szLogFile = $szSysRoot."/log/log.txt"; 

my $nWaitNMinutesBeforeDoingAnything = 1;

if (uptime() < $nWaitNMinutesBeforeDoingAnything * 60) {
	exit;
}

if (0) #NO LONGER DO THIS HERE... Handled by by startup.pl 
#if (systemBootedMinutesAgo(1) || runningBootCheck())	#Run "sudo perl crontasks.pl boot" to run this section.
{
	addWarningRecord($dbh, "System boot discovered. Running diagnostics.");

	if (!networkSetupOk()) {	#lib_net.pl
		my $szMsg = "************ NETWORK SETUP NOT OK ************"; 
		saveWarning($szMsg);
		print "$szMsg\n";
	} else {
		print "******* Network setup ok *************\n";
	}
	
	print "Finished boot taks.... Exiting to wait for next run to do other tasks.\n";
	exit;
}

my $nice_timestamp = getNiceTimestamp();
 #my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
 #   my $nice_timestamp = sprintf ( "%04d%02d%02d-%02d:%02d:%02d", $year+1900,$mon+1,$mday,$hour,$min,$sec);

checkRequests();	#See lib_cron.pm 	check setup.requestReboot  (Set from hotspot setup menu choice)	

createDirectories();
fixDevicesOldWay();
updateGlobalDemo(); #NOTE! Not reflecting the new code where each user may have individual demo setup (not yet working properly)
workshopSetup();	#If workshopId is set in dashboard setup, it will register other computers with same workshopId as partners.

#handleRequestsForDmsg();


#NOTE! Bots need log from targetHost relayed by botHost because it's the one that keeps track on port assignments....
#else {
#	if ($szIam && $szIam eq "bot") {
#		#Should request my ip and port from targetHost and maybe request status from botHost but already getting that...... what else?
#	} else {
#		print "***** WARNING **** iAm field is not set... should be fixed in localhost...\n";
#	}
#}

$| = 1; # Disable output buffering

#Uncomment to debug checkWhoIs()
#checkWhoIs();
#exit;

#Now check if gets here after running approximately 10 seconds..
my $nCount = 0;

if ($ARGV[0]) {
	#asdfasdf
#	exit;
	
}

while (time() - $nTimeStarted < 52)
{
	#Call script with some parameter do do debugging
	#Enable some warnings here so you remember to enable again...
	#saveWarning("handleConntrack() removed from cron job");

	process_dhcpdump($dbh);	#NOTE! Maybe it's risky to run it this often?
        handleConntrack($dbh);	#NOTE! Import port assignments. Import dhcp leases before this..
	logDmesg();
	checkWhoIs($dbh, $nNumberOfWhoIsLookupsPerIteration);
	sendPendingWgets();

        print "\nWaiting to do repetitive tasks (dmesg capture, whois lookups, ++?). Ctrl-C to break\n";
	sleep $nSecondsToSleepBetweenIterations;
	my $nSecondsSinceStart = time() - $nTimeStarted;
	print "$nSecondsSinceStart seconds.\n";
	$nCount++;
}

if ($nCount < 5 && $nSecondsToSleepBetweenIterations > 0) {
	print "****** WARNING crontasks.pl only managed to make $nCount iterations.\nYou may consider to reduce \$nSecondsToSleepBetweenIterations from ".$nSecondsToSleepBetweenIterations."\n";  
} else {
	print "\nFinished! Managed $nCount iterations.\n";
}

