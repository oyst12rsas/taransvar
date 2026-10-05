#!/usr/bin/perl
use strict;
use warnings;
use FindBin;
open my $source, '<', "$FindBin::Bin/../misc/crontasks.pl" or die $!;
local $/; my $code=<$source>;
my ($routine)=$code=~/(sub trySendWarningToRouter\s*\{.*?)(?=\nsub handle_syslogThreat_record)/s;
die 'Cannot locate reporting routine' unless $routine;
my @deliveries;
sub getRouterIpOf { return '100.68.165.190'; }
sub trim { my $s=shift;$s=~s/^\s+|\s+$//g;return $s; }
my $fail=0;
sub getUrl {my($url,%params)=@_;push @deliveries,[$url,{%params}];return $fail?'ERROR':'ok';}
{package TestDb;sub new{bless{packet=>$_[1]},$_[0]};sub selectrow_hashref{my($s,$query)=@_;return $query=~/globalDb/?{db1=>'100.68.126.0',db2=>undef,db3=>undef}:$s->{packet};}}
eval $routine;die $@ if $@;
my $row={src=>'100.68.165.190',src_ip=>1,src_port=>41000,dst_ip=>2,dst_port=>22,
 eventEpoch=>1000,description=>'SSH rejection',service=>'ssh',severity=>2};
die 'Delivery unexpectedly failed' unless trySendWarningToRouter($row,TestDb->new({tag=>0,observedAt=>1000}));
die 'Must notify router and DB' unless @deliveries==2;
die 'Zero evidence lost' unless $deliveries[1][1]{observed_tag}==0 && $deliveries[1][1]{observed_at}==1000;
@deliveries=();trySendWarningToRouter($row,TestDb->new(undef));
die 'Missing evidence was invented' if exists $deliveries[1][1]{observed_tag};
$fail=1;
die 'Failed delivery must remain pending' if trySendWarningToRouter($row,TestDb->new(undef));
print "Demo 5 ordinary report forwarding checks passed\n";
