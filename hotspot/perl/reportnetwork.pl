#!/usr/bin/perl
use strict;
use warnings;
use autodie;
#use DBI;

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";

my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst) = localtime(time);
$year += 1900;
my $szDateTime = "$year-$mon-$mday $hour:$min:$sec";

my $szNetworkReportFile = $szSysRoot."log/networkreport.txt";

open(my $fh, '>>', $szNetworkReportFile) or die "Could not open file '$szNetworkReportFile' $!";
say $fh "\n\nNetwork report started $szDateTime\n\n***** COMMAND: ip route\n\n";
close $fh;

system("ip route >> $szNetworkReportFile");

open($fh, '>>', $szNetworkReportFile) or die "Could not open file '$szNetworkReportFile' $!";
say $fh "\n\n***** COMMAND: ip link\n\n";
close $fh;

system("ip link >> $szNetworkReportFile");


open($fh, '>>', $szNetworkReportFile) or die "Could not open file '$szNetworkReportFile' $!";
say $fh "\n\n***** COMMAND: iwconfig\n\n";
close $fh;

system("iwconfig >> $szNetworkReportFile");


open($fh, '>>', $szNetworkReportFile) or die "Could not open file '$szNetworkReportFile' $!";
say $fh "\n\n***** COMMAND: cat /etc/network/interfaces\n\n";
close $fh;

system("cat /etc/network/interfaces >> $szNetworkReportFile");


open($fh, '>>', $szNetworkReportFile) or die "Could not open file '$szNetworkReportFile' $!";
say $fh "\n\n***** END OF REPORT ********\n\n";
close $fh;
