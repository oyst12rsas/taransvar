#!/usr/bin/perl
use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper qw(Dumper);
use File::Copy;
#TO DO!
# /etc/default/dhcpd.conf
# INTERFACES="eth1"	#NOTE replace with LAN nic

#my $szSysRoot = "/home/setup/";
my $szSysRoot = "/root/wifi/";


print "\n\n *********** checkdb.pl **********************\n\n";

my $szTmpIpLink = $szSysRoot."log/iplinkdmp.txt";
my $szPingTestTempFile = $szSysRoot."log/pingtest.txt";

my $database = "taransvar";
my $hostname = "localhost";
my $port = "3306";
my $user = "perl";
my $password = "RevSjoko731";

my $dsn = "DBI:mysql:database=$database;host=$hostname;port=$port";
my $dbh = DBI->connect($dsn, $user, $password);# or die "Unable to connect: $dbh->errstr()";

if (!$dbh)
{
	print "Error connecting (maybe database is not yet installed!\n";
	exit;
}


my @cFlds = ("setup^maintenanceRequest^varchar(200)",
            "setup^lastAccessUpdatePoll^timestamp after lastAccessUpdate",
            "setup^dummyToBeDeleted^varchar(1)"
);

#alter table setup add lastAccessUpdatePoll timestamp after lastAccessUpdate;
#altre table setup add maintenanceRequest varchar(200);


foreach (@cFlds)
{
    my $szFld = $_;
    print "About to process: $szFld\n";

    my @cElem = split(/\^/, $szFld);
    my $szTable = $cElem[0];
    my $szField = $cElem[1];

    print "Table: $cElem[0]\nField: ".$cElem[1]."\n";

    my $szSQL = "SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$database' AND TABLE_NAME = '$szTable' AND COLUMN_NAME = '$szField'";
    #SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'db_name' AND TABLE_NAME = 'setup' AND COLUMN_NAME = 'maintenanceRequest';

	my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	$sth->execute() or die "execution failed: $dbh->errstr()";
	if (my $cFldInfo = $sth->fetchrow_hashref()) 
	{
        #| TABLE_CATALOG | TABLE_SCHEMA | TABLE_NAME | COLUMN_NAME        | ORDINAL_POSITION | COLUMN_DEFAULT | IS_NULLABLE | DATA_TYPE | CHARACTER_MAXIMUM_LENGTH | CHARACTER_OCTET_LENGTH | NUMERIC_PRECISION |  NUMERIC_SCALE |DATETIME_PRECISION | CHARACTER_SET_NAME | COLLATION_NAME    | COLUMN_TYPE  | COLUMN_KEY | EXTRA | PRIVILEGES                      | COLUMN_COMMENT | GENERATION_EXPRESSION |
    
        my $szFldtype = $cFldInfo->{'DATA_TYPE'};
        print "$szField is $szFldtype\n";
    }
    else {
        print "**** $szField does not exist...\n";
        $szSQL = "alter table $szTable add $szField $cElem[2]";
	    my $sth = $dbh->prepare($szSQL) or die "prepare statement failed: $dbh->errstr()";
	    $sth->execute() or die "execution failed: $dbh->errstr()";
    }
}



print "--------------- CheckDB ended ----------------\n";

