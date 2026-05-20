#cron_sinkhole.pl
#this script is based on crontasks.pl, but stripped down to just script for reporting iptables drops. 
#Run it with sudo crontab -e and   cron_sinkhole.pl cron  (drop cron parameter for debug)

use lib ('/root/taransvar/perl');
#use lib ('.');
		
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..

use POSIX qw(setsid);

sub check_start_perl_bg_script
{
	my ($script, $pidfile, $logfile) = @_;

    # Check if already running
    if (-f $pidfile)
    {
        open(my $pf, "<", $pidfile) or die "Cannot read pidfile: $!";
        my $oldpid = <$pf>;
        chomp $oldpid;
        close($pf);

        if ($oldpid && kill(0, $oldpid))
        {
            print "script already running (PID $oldpid)\n";
            return;
        }
        else
        {
            print "Stale pidfile found, removing\n";
            unlink $pidfile;
        }
    }
	else
	{
		print "pidfile not found.\n";
	}

    my $pid = fork();
    die "fork failed: $!" unless defined $pid;

    if ($pid == 0)
    {
        # --- child (daemon) ---
        chdir "/" or die "chdir failed: $!";
        setsid() or die "setsid failed: $!";

        open(STDIN,  "<", "/dev/null") or die $!;
        open(STDOUT, ">>", $logfile) or die $!;
        open(STDERR, ">>", $logfile) or die $!;

        # Write PID file
        open(my $pf, ">", $pidfile) or die "Cannot write pidfile: $!";
        print $pf $$;
        close($pf);

        exec("/usr/bin/perl", $script) or die "exec failed: $!";
    }

    print "Started iptables monitor (PID $pid)\n";
}

sub start_local_iptables_monitor
{
	my $script     = "/root/taransvar/perl/log_iptables_drops_to_dbservers.pl";
	my $pidfile    = "/root/setup/log/log_iptables_drops_to_dbserver_monitor.pid";
	my $logfile    = "/root/setup/log/log_iptables_drops_to_dbserver_monitor.log";

	check_start_perl_bg_script($script, $pidfile, $logfile);
}

my $nice_timestamp = getNiceTimestamp();
print "Started: $nice_timestamp\n\n";

start_local_iptables_monitor();
print "\nFinished! Ending script\n";


