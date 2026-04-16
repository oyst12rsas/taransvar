

sub getDumpTxt {
	my ($szCmdLine) = @_;
    return qx($szCmdLine);
}


sub programWithParamsRunning {
	#Call it with e.g "tshark -i wlp1n0"
    my ($cmd) = @_;
	my $szPsLog = getDumpTxt("ps -aux | grep \"$cmd\"");
	my @lines = split("\n", $szPsLog);
	foreach (@lines) {
		#print "Checking $_\n";
		if (index($_, "grep \"$cmd") == -1 && index($_, "grep $cmd") == -1 && index($_, "nano $cmd") == -1) {
			return 1;
		}
	}
	return 0;
}


sub is_tshark_running {

    my ($iface) = @_;

	return programWithParamsRunning("tshark -i $iface");
}	


sub get_tshark_args {
    my ($iface) = @_;

    return (
        "tshark",
        "-i", $iface,
        "-l",
        "-nn",
        "-Y", "bootp",
        "-T", "fields",
        "-e", "frame.time_epoch",
        "-e", "ip.src",
        "-e", "ip.dst",
        "-e", "bootp.hw.mac_addr",
        "-e", "bootp.ip.your",
        "-e", "bootp.option.hostname",
        "-e", "bootp.option.vendor_class_id",
        "-e", "bootp.option.dhcp",
    );
}


sub OLD_ONE_start_process_dhcpdump {
    my ($iface) = @_;
    if (is_tshark_running($iface)) {
        print "*************** tshark already running on $iface\n";
        return;
    }

    print "**************** Starting tshark DHCP capture on $iface...\n";

	my $cmd = join(" ", get_tshark_args($iface));

    system("$cmd > /tmp/dhcp_stream.log 2>/dev/null &");
    print "********* just started... $cmd\n";

	my $pid = fork();
	die "fork failed: $!" unless defined $pid;

	if ($pid == 0) {
        my $szScript = "/root/taransvar/perl/dhcp_capture.pl";
    	exec("/usr/bin/perl", $szScript, $iface)
        	or die "exec failed: $!";
    	print "************ Started dhcp_capture.pl with PID $pid: $cPlCmd\n";
	} else {
        print "************ NO FORK\n";
    }

}


sub start_process_dhcpdump {
    my ($iface) = @_;

    if (programWithParamsRunning("dhcp_capture.pl"))
    {
        print "*************** dhcp_capture.pl already running on $iface\n";
        return;
    }

	my $pid = fork();
	die "fork failed: $!" unless defined $pid;

    if ($pid)
    {
        #This is the original process... continue
        return;
    }

    #now, this is the newly created child process. 

    print "**************** Starting tshark DHCP capture on $iface...\n";

    my $szScript = "/root/taransvar/perl/dhcp_capture.pl";
  	exec("/usr/bin/perl", $szScript, $iface)
        	or die "exec failed: $!";

    print "************ Started dhcp_capture.pl with PID $pid: $szScript $iface\n";
}

start_process_dhcpdump("enp2s0");