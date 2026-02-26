#Checking if able to connect to database
use lib ('.');
		
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..
use lib_net;

my $conn = getConnection();

print "Able to connect!";
exit 0;		#Caller checks this to verify if able to connect, meaning the database exists..
