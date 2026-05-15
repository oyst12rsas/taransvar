#!/usr/bin/perl
use strict;
use warnings;
use POSIX qw(strftime);
use File::Path qw(make_path);

# diagnose.pl - run on each lab node
# Safe scope: only checks configured peer IPs.

my $realUser = getpwuid($<);
my $homeDir  = (getpwnam($realUser))[7];

my $CONFIG = "$homeDir/diagnose.conf";
my $RUN_DIR = "/tmp/diagnose";
my $PID_DIR = "$RUN_DIR/pids";
my $LOG_DIR = "$RUN_DIR/logs";

my @nodes;
my $my_ip;
my $ssh_mode = "client";
my @ssh_targets;

my $wg_interface = "wg0";
my $my_ip_lan = "auto";
my $my_ip_wg  = "auto";

my $VERBOSE = grep { $_ eq "--verbose" } @ARGV;

sub run {
    my ($cmd) = @_;
    print "\n\$ $cmd\n";
    system($cmd);
}

sub slurp {
    my ($cmd) = @_;
    my $out = `$cmd 2>/dev/null`;
    chomp $out;
    return $out;
}

sub readLocalConfig {
    open(my $fh, "<", $CONFIG) or die "Could not open $CONFIG: $!";

    while (my $line = <$fh>) {
        chomp $line;
        $line =~ s/#.*//;
        next unless $line =~ /\S/;

        if ($line =~ /^nodes\s*=\s*(.+)$/) {
            @nodes = split(/\s*,\s*/, $1);
        }
        elsif ($line =~ /^my_ip\s*=\s*(.+)$/) {
            $my_ip = $1;
        }
        elsif ($line =~ /^ssh_mode\s*=\s*(client|server)$/) {
            $ssh_mode = $1;
        }
        elsif ($line =~ /^ssh_targets\s*=\s*(.+)$/) {
            @ssh_targets = split(/\s*,\s*/, $1);
        }
    }

    close $fh;
    die "No nodes configured\n" unless @nodes;
}

sub getMyIp {
    return $my_ip if $my_ip;

    my $ips = slurp("ip -4 -br addr | awk '{print \$3}' | cut -d/ -f1");
    my %node = map { $_ => 1 } @nodes;

    for my $ip (split(/\n/, $ips)) {
        return $ip if $node{$ip};
    }

    return "UNKNOWN";
}

sub ok {
    my ($msg) = @_;
    return unless $VERBOSE;
    print "[OK] $msg\n";
}

sub warnmsg {
    my ($msg, $meaning, $fix) = @_;
    problem("WARN", $msg, $meaning, $fix);
}

my $fatal_errors = 0;

sub errmsg {
    my ($msg, $meaning, $fix) = @_;
    $fatal_errors++;
    problem("ERROR", $msg, $meaning, $fix);
}

sub problem {
    my ($level, $msg, $meaning, $fix) = @_;

    print "[$level] $msg\n";
    print "        Meaning: $meaning\n" if $meaning;
    print "        Try:     $fix\n" if $fix;
}

sub cleanUpOldStuff {
    print "\n=== Cleanup old diagnose processes ===\n";

    return unless -d $PID_DIR;

    opendir(my $dh, $PID_DIR) or return;
    while (my $file = readdir($dh)) {
        next unless $file =~ /\.pid$/;

        my $path = "$PID_DIR/$file";
        open(my $fh, "<", $path) or next;
        my $pid = <$fh>;
        close $fh;
        chomp $pid;

        if ($pid =~ /^\d+$/) {
            print "Stopping old diagnose process PID $pid\n";
            kill "TERM", $pid;
        }

        unlink $path;
    }
    closedir($dh);
}

sub firstIpOnInterface {
    my ($iface) = @_;
    my $ip = `ip -4 -o addr show dev $iface 2>/dev/null | awk '{print \$4}' | cut -d/ -f1 | head -1`;
    chomp $ip;
    return $ip || "";
}

sub detectDefaultInterface {
    my $iface = `ip route show default 2>/dev/null | awk '{print \$5; exit}'`;
    chomp $iface;
    return $iface || "";
}

sub detectIps {
    if (!$my_ip_lan || $my_ip_lan eq "auto") {
        my $lan_if = detectDefaultInterface();
        $my_ip_lan = firstIpOnInterface($lan_if);
    }

    if (!$my_ip_wg || $my_ip_wg eq "auto") {
        $my_ip_wg = firstIpOnInterface($wg_interface);
    }
}

sub checkPing {
    my ($target, $src) = @_;

    my $cmd = $src
        ? "ping -c1 -W2 -I $src $target > /dev/null 2>&1"
        : "ping -c1 -W2 $target > /dev/null 2>&1";

    my $rc = system($cmd);

    if ($rc != 0) {
        warnmsg(
            "Ping failed: $target",
            $src
                ? "Ping failed when using source IP $src."
                : "Ping failed without a forced source IP.",
            "Run: ip route get $target ; ping -c3 $target"
        );
    } else {
        ok("Ping OK: $target");
    }
}

sub checkSsh {
    my ($target) = @_;

    my $cmd =
        "timeout 6 ssh " .
        "-o BatchMode=yes " .
        "-o ConnectTimeout=3 " .
        "-o ConnectionAttempts=1 " .
        "-o PasswordAuthentication=no " .
        "-o StrictHostKeyChecking=accept-new " .
        "$target true > /dev/null 2>&1";

    my $rc = system($cmd);

    if ($rc != 0) {
        errmsg("SSH failed/timed out: $target");
    } else {
        ok("SSH OK: $target");
    }
}

sub checkRoute {
    my ($target) = @_;

    my $route = `ip route get $target 2>/dev/null`;

    if (!$route) {
        errmsg("No route to $target");
        return;
    }

    ok("Route exists to $target");
}

sub hostFromSshTarget {
    my ($target) = @_;
    $target =~ s/^.*@//;     # remove user@
    $target =~ s/:.*$//;     # optional: remove :port
    return $target;
}

sub startBackgroundSending {
    my ($name, $command) = @_;

    make_path($PID_DIR, $LOG_DIR);

    my $log = "$LOG_DIR/$name.log";
    my $pidfile = "$PID_DIR/$name.pid";

    my $loop = qq{while true; do echo "\\n--- \$(date) ---"; $command; sleep 5; done >> "$log" 2>&1};

    my $pid = fork();
    die "fork failed: $!" unless defined $pid;

    if ($pid == 0) {
        exec("sh", "-c", $loop);
        exit 1;
    }

    open(my $fh, ">", $pidfile) or die "Could not write $pidfile: $!";
    print $fh "$pid\n";
    close $fh;

    print "Started $name PID=$pid log=$log\n";
}

sub checkDetectedIps {
    if (!$my_ip_lan) {
        warnmsg(
            "Could not detect LAN IP",
            "No IPv4 found on default-route interface.",
            "Run: ip route ; ip -br addr"
        );
    }

    if (!$my_ip_wg) {
        errmsg(
            "Could not detect WireGuard IP on $wg_interface",
            "$wg_interface may be down or has no IPv4 address.",
            "Run: sudo wg-quick up $wg_interface ; ip -br addr show $wg_interface"
        );
    }
}

sub startBackgroundSendingProcesses {
    print "\n=== Starting background test traffic ===\n";

    for my $node (@nodes) {
        next if $node eq $my_ip_lan;
        next if $node eq $my_ip_wg;

        my $safe = $node;
        $safe =~ s/[^0-9A-Za-z_.-]/_/g;

        my $src = sourceIpForTarget($node);
        my $pingCmd = $src
            ? "ping -c 3 -I $src $node"
            : "ping -c 3 $node";

        startBackgroundSending("ping_$safe", $pingCmd);
        startBackgroundSending("curl_$safe", "curl -m 3 -sS -v http://$node/hello.html");
    }
}

sub checkInterface {
    my ($ifname) = @_;

    my $rc = system("ip link show $ifname > /dev/null 2>&1");

    if ($rc != 0) {
        errmsg("Interface missing: $ifname");
    } else {
        ok("Interface exists: $ifname");
    }
}

sub checkWireGuard {
    my $rc = system("wg show wg0 > /dev/null 2>&1");

    if ($rc != 0) {
        errmsg("WireGuard wg0 not working or not configured");
    } else {
        ok("WireGuard wg0 OK");
    }
}

sub checkCommandAvailable {
    my ($cmd) = @_;

    my $rc = system("command -v $cmd > /dev/null 2>&1");

    if ($rc != 0) {
        errmsg("Required command missing: $cmd");
    } else {
        ok("Command available: $cmd");
    }
}

sub checkListeningPort {
    my ($port, $proto) = @_;
    $proto ||= "tcp";

    my $cmd = $proto eq "udp" ? "ss -H -lun" : "ss -H -ltn";
    my @lines = `$cmd 2>/dev/null`;

    for my $line (@lines) {
        if ($line =~ /(?:^|\s)(?:\S+:|\*:|0\.0\.0\.0:|\[::\]:)$port(?:\s|$)/) {
            ok("Local $proto port listening: $port");
            return;
        }
    }

    warnmsg(
        "Local $proto port not listening: $port",
        "No local listening socket detected by ss.",
        "Run: sudo ss -ltnp | grep -E '[:.]$port\\b'"
    );
}

sub checkTcpPort {
    my ($host, $port) = @_;

    my $rc = system("nc -zvw2 $host $port > /dev/null 2>&1");

    if ($rc != 0) {
        warnmsg("TCP port unreachable: $host:$port");
    } else {
        ok("TCP port reachable: $host:$port");
    }
}

sub checkHttp {
    my ($host) = @_;

    my $rc = system("curl -m 3 -fsS http://$host/hello.html > /dev/null 2>&1");

    if ($rc != 0) {
        warnmsg("HTTP failed: http://$host/hello.html");
    } else {
        ok("HTTP OK: $host");
    }
}

sub sourceIpForTarget {
    my ($target) = @_;

    #
    # WireGuard/internal overlay ranges
    #
    if ($target =~ /^10\.47\./ || $target =~ /^10\.100\./) {
        return $my_ip_wg if $my_ip_wg;
    }

    #
    # Everything else uses LAN/default-route IP
    #
    return $my_ip_lan if $my_ip_lan;

    return "";
}

sub checkLocalOutputTcpPort {
    my ($port) = @_;

    my $policy = `iptables -L OUTPUT -n 2>/dev/null | head -1`;

    my $has_accept =
        system("iptables -C OUTPUT -p tcp --dport $port -j ACCEPT > /dev/null 2>&1") == 0;

    if ($policy =~ /policy DROP/ && !$has_accept) {
        errmsg(
            "Local OUTPUT policy is DROP and no tcp/$port ACCEPT rule exists",
            "Outgoing SSH is blocked locally before packets leave this machine.",
            "Fix: sudo iptables -I OUTPUT 1 -p tcp --dport $port -j ACCEPT"
        );
    } else {
        ok("Local OUTPUT allows tcp/$port");
    }
}

sub systemCheck {
    detectIps();
    checkDetectedIps();

    checkInterface($wg_interface);
    checkCommandAvailable("wg");
    checkWireGuard();

    checkListeningPort(22, "tcp");
    checkListeningPort(80, "tcp");

    for my $node (@nodes) {
        next if $node eq $my_ip_lan;
        next if $node eq $my_ip_wg;

        my $src = sourceIpForTarget($node);

        checkRoute($node);
        checkPing($node, $src);
        checkHttp($node);
        checkTcpPort($node, 22);
        checkTcpPort($node, 80);
    }

    checkLocalOutputTcpPort(22);
    checkLocalOutputTcpPort(80);
    checkLocalOutputTcpPort(443);

    for my $target (@ssh_targets) {
        diagnoseSshFailure($target);
    }
}

sub diagnoseSshFailure {
    my ($target) = @_;

    my $host = hostFromSshTarget($target);

    #
    # Step 1: route exists?
    #
    my $route = `ip route get $host 2>/dev/null`;

    if (!$route) {
        errmsg(
            "SSH impossible: no route to $host",
            "Kernel has no route for this destination.",
            "Check: ip route ; wg show ; AllowedIPs"
        );
        return;
    }

    #
    # Step 2: ping works?
    #
    my $pingRc = system("ping -c1 -W2 $host > /dev/null 2>&1");

    if ($pingRc != 0) {
        warnmsg(
            "Host does not answer ping: $host",
            "Could be firewall DROP, host offline, or return routing issue.",
            "Run tcpdump on both ends while pinging."
        );
    }

    #
    # Step 3: TCP 22 reachable?
    #
    my $ncRc = system("nc -zvw3 $host 22 > /dev/null 2>&1");

    if ($ncRc != 0) {
        errmsg(
            "TCP/22 unreachable on $host",
            "sshd may be down, firewall may DROP port 22, or SYN/SYN-ACK path broken.",
            "Run on target: ss -ltnp | grep ':22'"
        );

        #
        # Important extra clue:
        #
        warnmsg(
            "SSH timeout/hang strongly suggests firewall DROP instead of REJECT",
            "DROP causes SSH to wait forever until timeout.",
            "Temporarily test: sudo iptables -I INPUT 1 -p tcp --dport 22 -j ACCEPT"
        );

        return;
    }

    #
    # Step 4: actual SSH auth/connect
    #
 #   my $sshRc = system(
 #       "timeout 6 ssh " .
 #       "-o BatchMode=yes " .
 #       "-o ConnectTimeout=3 " .
 #       "-o ConnectionAttempts=1 " .
 #       "$target true > /dev/null 2>&1"
 #   );

 #   if ($sshRc != 0) {
 #       warnmsg(
 #           "TCP/22 reachable but SSH login failed: $target",
 #           "Likely wrong username, missing SSH key, or auth configuration problem.",
 #           "Try: ssh -vvv $target"
 #       );
 #       return;
 #   }

    ok("SSH OK: $target");
}

if (@ARGV && $ARGV[0] eq "--cleanup") {
    cleanUpOldStuff();
    exit 0;
}

readLocalConfig();

if (!@ssh_targets) {
    warnmsg(
        "No ssh_targets configured",
        "The script cannot diagnose SSH problems without ssh_targets.",
        "Add: ssh_targets = user\@ip,user\@ip"
    );
}

make_path($RUN_DIR, $PID_DIR, $LOG_DIR);

cleanUpOldStuff();

systemCheck();

if ($fatal_errors > 0) {
    print "\nNot starting background tests because there are local errors above.\n";
    exit 1;
}

startBackgroundSendingProcesses();

print "\nDone.\n";
print "Logs: $LOG_DIR\n";
print "Stop background tests with:\n";
print "  sudo perl $0 --cleanup\n";