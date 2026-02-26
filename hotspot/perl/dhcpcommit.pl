#!/usr/bin/perl

use strict;
use warnings;
use autodie;
use DBI;

my $szLogFile = "/home/setup/log/dhcpcommit.log";
open(my $fDhcpLog, '>>', $szLogFile) or die "Could not open file '$szLogFile' $!";

my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
my $nice_timestamp = sprintf ( "%04d%02d%02d_%02d%02d%02d",
                                   $year+1900,$mon+1,$mday,$hour,$min,$sec);

print $fDhcpLog "DHCP commit at: $nice_timestamp\n";
close $fDhcpLog;
