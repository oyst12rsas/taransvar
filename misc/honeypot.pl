#!/usr/bin/perl -w
# chat_server.pl
use strict;
use IO::Socket::INET;

my $port = shift or die "Port required!\n";
my $socket = IO::Socket::INET->new(
        LocalPort   => $port,
        Proto       => 'tcp',
        Listen      => SOMAXCONN
    ) or die "Can't create socket: $!!\n";
my $child;

print "Listening for clients on $port...\n";
REQUEST:
while(my $client = $socket->accept) {
    my $addr = gethostbyaddr($client->peeraddr, AF_INET);
   # if (!$addr)
    	$addr = "";
    	
    my $port = $client->peerport;

    if($child = fork) {
        print "New connection from $addr:$port\n";
        close $client;
        next REQUEST;
    } die "fork failed!\n" unless defined $child;

    while (<$client>) {
        #print "[$addr:$port] says: $_";
        #print $client "[$addr:$port] says: $_";
        print $client "Please type password.\n";
    }   
}
close $socket;
