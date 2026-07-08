#!/usr/bin/perl

# --------------------------------------------------
# About
# --------------------------------------------------
#
#  If you're installing Cowrie ssh honeypot in your network, 
#  then set up this script to send threat info to the Taransvar router
#  Save it somewhere and set it up to start when your honeypot start
#  In runs forever, captures the log, sends it to the IP specified below
#  where (hopefully) Taralink listens to port 514 and logs to 
#  syslog and syslogThreat tables along with logs from Cisco, Fortinet, ipbables and others. 
#
#  Nothing received? 
#  - Run on receiver: 	sudo tcpdump -i any -nn udp port 514
#  - Open port: 		sudo iptables -I INPUT 1 -i <nic_here> -s <network/ip here> -p udp --dport 514  -j ACCEPT
#
# --------------------------------------------------
# Configuration
# --------------------------------------------------
my $COWRIE_JSON   = "/home/cowrie/cowrie/var/log/cowrie/cowrie.json";
my $REPORT_TO_IP   = "100.68.181.35";   # change if needed
my $ROUTERVM_PORT = 514;             # Use port 514 UDP if you want taralink to handle it on receiving end.
my $ALERT_PROTO = "udp";
my $SENSOR_NAME   = "cowrie";

my $OBSERVED_PROTO  = "tcp";			# Protocol used in attack - ssh (TCP)

# Fallback cleanup for lost/never-closed sessions
my $SESSION_TTL = 3600;

# If true, also emit severity 1 connect events
my $SEND_CONNECT_EVENTS = 1;

# If true, emit command input events
my $SEND_COMMAND_EVENTS = 1;

use strict;
use warnings;
use IO::Socket::INET;
use JSON::PP;
use Time::HiRes qw(time sleep);

# --------------------------------------------------
# Globals
# --------------------------------------------------
my %session_map;
my $json = JSON::PP->new->utf8->allow_nonref;

my $sock = IO::Socket::INET->new(
	PeerAddr => $REPORT_TO_IP,
	PeerPort => $ROUTERVM_PORT,
	Proto    => $ALERT_PROTO,
) or die "Could not create UDP socket to $REPORT_TO_IP:$ROUTERVM_PORT: $!";

$| = 1;

print "Starting Cowrie watcher\n";
print "Reading: $COWRIE_JSON\n";
print "Sending alerts to $REPORT_TO_IP:$ROUTERVM_PORT\n";

monitor_cowrie();

# --------------------------------------------------
sub monitor_cowrie
{
	while (1)
	{
		open(my $fh, "-|", "tail", "-F", $COWRIE_JSON)
			or do {
				warn "Cannot tail $COWRIE_JSON: $!\n";
				sleep 5;
				next;
			};

		print "Connected to tail -F on $COWRIE_JSON\n";

		while (my $line = <$fh>)
		{
			chomp $line;
			next unless $line =~ /\S/;

			my $obj;
			eval { $obj = $json->decode($line); };
            if ($@ || ref($obj) ne 'HASH')
            {
                warn "Skipping invalid JSON line\n";
                next;
            }

            handle_event($obj);
            cleanup_sessions();
        }

        close($fh);
        warn "tail ended, reconnecting in 2 seconds...\n";
        sleep 2;
    }
}

# --------------------------------------------------
sub handle_event
{
    my ($obj) = @_;

    my $eventid = $obj->{eventid} // '';
    return unless $eventid;

    if ($eventid eq 'cowrie.session.connect')
    {
        handle_session_connect($obj);
        return;
    }

    if ($eventid eq 'cowrie.login.success')
    {
        handle_login_success($obj);
        return;
    }

    if ($eventid eq 'cowrie.command.input')
    {
        handle_command_input($obj) if $SEND_COMMAND_EVENTS;
        return;
    }

    if ($eventid eq 'cowrie.session.closed')
    {
        handle_session_closed($obj);
        return;
    }
}

# --------------------------------------------------
sub handle_session_connect
{
    my ($obj) = @_;

    my $session  = $obj->{session}    // return;
    my $src_ip   = $obj->{src_ip}     // '';
    my $src_port = $obj->{src_port}   // '';
    my $dst_ip   = $obj->{dst_ip}     // '';
    my $dst_port = $obj->{dst_port}   // '';
    my $ts       = time();

    $session_map{$session} = {
        session        => $session,
        src_ip         => $src_ip,
        src_port       => $src_port,
        dst_ip         => $dst_ip,
        dst_port       => $dst_port,
        proto          => $OBSERVED_PROTO,
        first_seen     => $ts,
        last_seen      => $ts,
        state          => 'active',
        command_count  => 0,
        login_success  => 0,
    };

    print "CONNECT session=$session $src_ip:$src_port -> $dst_ip:$dst_port\n";

    if ($SEND_CONNECT_EVENTS)
    {
        my %event = build_event(
            event      => 'cowrie_session_connect',
            severity   => 1,
            confidence => 'low',
            action     => 'observe',
            session    => $session,
            src_ip     => $src_ip,
            src_port   => $src_port,
            dst_ip     => $dst_ip,
            dst_port   => $dst_port,
        );

        send_event(\%event);
    }
}

# --------------------------------------------------
sub handle_login_success
{
    my ($obj) = @_;

    my $session  = $obj->{session} // '';
    my $username = defined $obj->{username} ? $obj->{username} : '';
    my $password = defined $obj->{password} ? $obj->{password} : '';

    my $ctx = get_or_build_context($obj);
    $ctx->{last_seen}     = time();
    $ctx->{login_success} = 1;

    print "LOGIN SUCCESS session=$session $ctx->{src_ip}:$ctx->{src_port} -> $ctx->{dst_ip}:$ctx->{dst_port}\n";

    my %event = build_event(
        event      => 'cowrie_login_success',
        severity   => 7,
        confidence => 'high',
        action     => 'tag_host',
        session    => $session,
        username   => $username,
        password   => $password,
        src_ip     => $ctx->{src_ip},
        src_port   => $ctx->{src_port},
        dst_ip     => $ctx->{dst_ip},
        dst_port   => $ctx->{dst_port},
        proto      => $ctx->{proto},
    );

    send_event(\%event);
}

# --------------------------------------------------
sub handle_command_input
{
    my ($obj) = @_;

    my $session = $obj->{session} // '';
    my $input   = defined $obj->{input} ? $obj->{input} : '';

    my $ctx = get_or_build_context($obj);
    $ctx->{last_seen} = time();
    $ctx->{command_count}++;

    print "COMMAND session=$session $ctx->{src_ip}:$ctx->{src_port} cmd=$input\n";

    my %event = build_event(
        event         => 'cowrie_command_input',
        severity      => 8,
        confidence    => 'high',
        action        => 'tag_host',
        session       => $session,
        command       => $input,
        command_count => $ctx->{command_count},
        src_ip        => $ctx->{src_ip},
        src_port      => $ctx->{src_port},
        dst_ip        => $ctx->{dst_ip},
        dst_port      => $ctx->{dst_port},
        proto         => $ctx->{proto},
    );

    send_event(\%event);
}

# --------------------------------------------------
sub handle_session_closed
{
    my ($obj) = @_;

    my $session = $obj->{session} // return;
    my $ctx     = $session_map{$session};

    if ($ctx)
    {
        $ctx->{last_seen} = time();
        $ctx->{state}     = 'closed';

        my $duration = sprintf("%.3f", $ctx->{last_seen} - ($ctx->{first_seen} // $ctx->{last_seen}));

        print "CLOSE session=$session duration=${duration}s commands=$ctx->{command_count}\n";

        my %event = build_event(
            event         => 'cowrie_session_closed',
            severity      => $ctx->{login_success} ? 4 : 1,
            confidence    => $ctx->{login_success} ? 'medium' : 'low',
            action        => 'archive',
            session       => $session,
            src_ip        => $ctx->{src_ip},
            src_port      => $ctx->{src_port},
            dst_ip        => $ctx->{dst_ip},
            dst_port      => $ctx->{dst_port},
            proto         => $ctx->{proto},
            duration_sec  => $duration,
            command_count => $ctx->{command_count},
            login_success => $ctx->{login_success},
        );

        send_event(\%event);

        delete $session_map{$session};
    }
    else
    {
        print "CLOSE session=$session (no state found)\n";
    }
}

# --------------------------------------------------
sub get_or_build_context
{
    my ($obj) = @_;

    my $session = $obj->{session} // '';

    if ($session && exists $session_map{$session})
    {
        return $session_map{$session};
    }

    my $ts = time();
    my $ctx = {
        session        => $session,
        src_ip         => $obj->{src_ip}   // '',
        src_port       => $obj->{src_port} // '',
        dst_ip         => $obj->{dst_ip}   // '',
        dst_port       => $obj->{dst_port} // '',
        proto          => $OBSERVED_PROTO,
        first_seen     => $ts,
        last_seen      => $ts,
        state          => 'active',
        command_count  => 0,
        login_success  => 0,
    };

    $session_map{$session} = $ctx if $session;
    return $ctx;
}

# --------------------------------------------------
sub build_event
{
    my (%args) = @_;

    my %event = (
        sensor    => $SENSOR_NAME,
        proto     => $OBSERVED_PROTO,
        timestamp => scalar(time),
        %args,
    );

    return %event;
}

# --------------------------------------------------
sub send_event
{
    my ($event_ref) = @_;

    my $msg = eval { $json->encode($event_ref) };
    if ($@)
    {
        warn "JSON encode failed: $@\n";
        return;
    }

    print "Reporting to $REPORT_TO_IP:$ROUTERVM_PORT: $msg\n";

    my $ok = $sock->send($msg);
    warn "UDP send failed: $!\n" unless defined $ok;
}

# --------------------------------------------------
sub cleanup_sessions
{
    my $now = time();

    for my $session (keys %session_map)
    {
        my $last_seen = $session_map{$session}->{last_seen}
            // $session_map{$session}->{first_seen}
            // 0;

        if (($now - $last_seen) > $SESSION_TTL)
        {
            print "CLEANUP stale session=$session state=$session_map{$session}->{state}\n";
            delete $session_map{$session};
        }
    }
}