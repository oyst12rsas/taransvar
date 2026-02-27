#Called every time system boots by @reboot /path/to/script in: sudo crontab -u root -e 
#use lib ('.');

#use lib ('/root/taransvar/perl');
use lib ('.');
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..
use lib_net;
use lib_cron;
use lib_dhcp;

my $filename = '/root/setup/log/startup.txt';

sub add {
	my ($szTxt) = @_;
	open(my $fh, '>>', $filename) or die "Could not open file '$filename' $!";
	print $fh getNiceTimestamp().": $szTxt\n";
	close $fh;
	print $szTxt."\n";
}


open(my $fh, '>', $filename) or die "Could not open file '$filename' $!";
print $fh getNiceTimestamp().": startup.pl started by cron\n";
close $fh;
print "Started..\n";

sleep(15);
add("after sleep");
addWarningRecord(0,"startup starting up..");
#Changes here.. Start hostapd---- Checks if running first.
my $cSetup = getSetup();
my $szNic = $cSetup->{"internalNic"}; 

if (defined($szNic) && uc(substr($szNic, 0, 1)) eq "W") {
    add("Checking if hostapd is already running...");
    
    #put unblocking here........ 
    
    # Check if hostapd is running
    my $running = `pgrep -x hostapd`;

    if (!$running) {
        add("Starting hostapd...");
        my $status = system("/usr/sbin/hostapd -B /etc/hostapd/hostapd.conf");

        if ($status == 0) {
            add("hostapd started successfully.");
        } else {
            add("Failed to start hostapd. Exit code: $status");
        }
    } else {
        add("hostapd is already running.");
    }
} else {
    add("Internal NIC is cabled.");
}

add("After warning");
networkSetupOk();
add("Network setup done");
$cSetup = getSetup();	#Get again in case network setup is changed
my $szNetworkStatus = $cSetup->{"networkStatus"};
print "$szNetworkStatus\n";
add("Network status: $szNetworkStatus\n");

startTaraSystemsOk();	#Defined in lib_cron.pm

add("Started taransvar systems");
checkStartHotspotSystem();	#see lib_net.pm
add("Started hotspot system");
my $dbh = getConnection();
add("Opened db connection");
checkDbVersion($dbh);
add("DB version checked");
	
#Checking if there's dhcp log files from last month.. Better put this elsewhere? Server may run for long?
checkArchiveDhcpFiles();	#see lib_dhcp.pm
add("Checked archiving");

#addWarningRecord(0,"System booth: startup.pl running here.");

add("Finished startup tasks.");

system("cat $filename");

$dbh->disconnect;
