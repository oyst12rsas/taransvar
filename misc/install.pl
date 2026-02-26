#Called by install.sh to finalize the install 
#(primarily because some good functions have been made available for crontasks.pl and more)

#use lib ('/root/taransvar/perl');
#use lib ('/home/taransvar/taransvar/misc');
use lib ('.');
		
use strict;
use warnings;
use autodie;
use DBI;
use func;	#NOTE! See comment above regarding lib..
use lib_net;

my $conn = getConnection();


