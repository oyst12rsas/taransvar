#!/usr/bin/perl
use strict;
use warnings;
use autodie;
use DBI;

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";

my $database = "taransvar";
my $hostname = "localhost";
my $port = "3306";
my $user = "perl";
my $password = "RevSjoko731";


my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst) = localtime(time);
$year += 1900;
$mday += 1;
my $szTempFile = $szSysRoot."log/cgitemfile-$year-$mon-$mday-$hour-$min.txt";
my $szLastTaskTemp = $szSysRoot."log/tasktemp.txt";

sub label {
        my ($bStart, $szLabel) = @_;

	open(my $fh, '>>', $szTempFile) or die "Could not open file '$szTempFile' $!";
	
	if ($bStart) {
		say $fh "<gatheredstart:$szLabel>";
	} else {
		say $fh "<gatheredend>";
	}
	close $fh;
}

sub get {
        my ($szLabel, $szCmdLine) = @_;
	label(1, $szLabel);

    my $bSkip = 0;
    #print "Checking command: $szCmdLine\n";

    if ($szCmdLine =~ /tail\s(\S*)$/)
    {
        #print "tail found: $szCmdLine, file=$1\n";

        if (! -e $1) {
            print "$1 is empty.. skipping (tail would abort)\n";
            $bSkip = 1;
        } else {
        }
    }
     
    if (!$bSkip) {       
    	system("$szCmdLine > $szLastTaskTemp");
    	system("cat $szLastTaskTemp >> $szTempFile");
    }
	label(0, $szLabel);
}

my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
my $dbh = DBI->connect($dsn, $user, $password);

#Read setup...

sub logIf {
        my ($szSection, $szSearchFor, $szWarning, $bContains) = @_;

	open my $fh, '<', $szLastTaskTemp or die "error opening $szLastTaskTemp: $!";
	my $szWholeFile = do { local $/; <$fh> };
	close $fh;
	
	my $bDoLog;
	
	if ($bContains) {
		$bDoLog = index($szWholeFile, $szSearchFor) != -1;
	} else {
		$bDoLog = index($szWholeFile, $szSearchFor) == -1;
	}

	if ($bDoLog) {
		#Check if this warning is already registered...
		my $sth = $dbh->prepare(
			"select systemMessageId from systemMessage where seen = b'0' and message = '$szWarning' order by systemMessageId desc limit 1")
			or die "prepare statement failed: $dbh->errstr()";
		$sth->execute() or die "execution failed: $dbh->errstr()";
		if (my $cMsg = $sth->fetchrow_hashref()) 
		{
			my $nMessageId = $cMsg->{'systemMessageId'};

			my $sth = $dbh->prepare(
				"update systemMessage set count = least(255,count + 1), lastDiscovered = now() where systemMessageId = $nMessageId")
				or die "prepare statement failed: $dbh->errstr()";
			$sth->execute() or die "execution failed: $dbh->errstr()";
			$sth->finish();
		}
		else {
			my $sth = $dbh->prepare(
				"insert into systemMessage (message, sysSnapshotSection) values ('$szWarning', '$szSection')")
				or die "prepare statement failed: $dbh->errstr()";
			$sth->execute() or die "execution failed: $dbh->errstr()";
			$sth->finish();
		}

	} 
	
	return $bDoLog;
}

sub logIfContains {
        my ($szSection, $szSearchFor, $szWarning) = @_;
	return logIf($szSection, $szSearchFor, $szWarning, 1);
}

sub logIfNotContains {
        my ($szSection, $szSearchFor, $szWarning) = @_;
	return logIf($szSection, $szSearchFor, $szWarning, 0);
}

get("iproute", "ip route");
get("ifconfig", "/sbin/ifconfig");
get("iptables", "/sbin/iptables -L");
get("iptables_nat", "/sbin/iptables -t nat -L");
get("ip_forward", "/sbin/sysctl net.ipv4.ip_forward");
get("resolv", "cat /etc/resolv.conf");
get("DNS", "nmcli dev show | grep DNS");
get("ping_dns", "ping -c 1 google.com");
get("ping", "ping -c 1 8.8.8.8");
get("nmcli", "/usr/bin/nmcli dev show");
get("syslog", "tail /var/log/syslog");
get("interfaces", "cat /etc/network/interfaces");
get("cron_log", "tail ".$szSysRoot."log/cronlog.txt");
get("dhcp", "cat /var/log/syslog | grep DHCP");
#get("dhcp_lease", "tail /var/log/dhcpd.log");
get("log_files", "ls ".$szSysRoot."log -l");
get("df", "df");
get("lspci", "lspci");
get("lsusb", "lsusb");
get("ps", "ps aux");
get("ipfm", "systemctl status ipfm");
logIfContains("ipfm", "Error", "Some error with ipfm(error meg in syslog). Datausage will probably not be logged. Check status");
#if (
logIfNotContains("ipfm", "Active: active (running)", "ipfm is not running. Datausage will probably not be logged. Check status");
#) 
#{
#**** NO Need to do this... seems like it happens because there's no connected clients.... *******
#	system ('/etc/init.d/ipfm stop >> '.$szSysRoot.'log/install.log');	
#	system ('/etc/init.d/ipfm start >> '.$szSysRoot.'log/install.log');
#}

#Prints error if log rotated: logIfNotContains("ipfm", "Started ipfm", "ipfm not started. Datausage will probably not be logged. Check status");

#Try also:  dhcpdump -i enp5s0

#my $szLast = $szSysRoot."log/cgitempfile.last";
my $szLast = "/usr/lib/cgi-bin/cgitempfile.last";

if ( -e $szLast) {
	unlink $szLast;
}

system("mv $szTempFile $szLast");

#Check server load
open my $fh, '<', "/proc/loadavg" or die "Can't open file $!";
my $szLoad = do { local $/; <$fh> };
close $fh;
#print "Load read: $szLoad\n";

if ($szLoad =~ /(\S*) (\S*) (\S*) (\S*) (\S*)/) {
	my $sth = $dbh->prepare(
		"insert into loadavg (min1, min5, min10, processes) values ($1, $2, $3, '$4')")
			or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $dbh->errstr()";
} else {
	print "Not able to decode load fields!\n";
}
