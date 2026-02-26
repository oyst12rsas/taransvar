#Just doing some testing... to be deleted...

use lib ('/home/taransvar/programming/misc');
		
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..
use lib_dhcp;
use lib_cron;
use lib_net;

my $dbh = getConnection();

for (my $j = 12; $j < 14; $j++) {
	for (my $i = 2; $i < 102; $i++) {

		my $szSQL = "insert into partnerRouter (partnerId, ip, nettmask) values (3,inet_aton('192.168.$j.$i'),inet_aton('255.255.255.255'))";
		print "$j.$i: $szSQL\n";
		my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
		$sth->execute() or die "execution failed: $sth->errstr()";
		sleep 1;
		
	}	
}
