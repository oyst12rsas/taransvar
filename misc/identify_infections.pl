#!/usr/bin/perl
use strict;
use warnings;
use DBI;
use lib ('.');
use func;	#NOTE! See comment above regarding lib..


# Connect to MySQL database
my $dbh = getConnection();

#Get other connection for lookup
my $lookupDbh = getConnection();

# Prepare and execute query to scan the table
my $query = "SELECT reportId, inet_ntoa(ip) as ip, port, status FROM hackReport where handledTime is null";
my $sth = $dbh->prepare($query);
$sth->execute();

# Fetch and print each row
while (my $row = $sth->fetchrow_hashref()) {
#    print "Row:\n";
        print "   $row->{'ip'} $row->{'port'} $row->{'status'}\n";

        my $szLookup = "select portAssignmentId, ipAddress, inet_ntoa(ipAddress) as aIp from unitPort where port = ".$row->{'port'}." order by created desc limit 1";
        print "\n$szLookup\n";
        
        my $sth = $lookupDbh->prepare($szLookup);
        $sth->execute();
        while (my $lookupRow = $sth->fetchrow_hashref()) {
                #    print "Row:\n";
                print "Internal IP found: ".$lookupRow->{'aIp'}."\t".$lookupRow->{'portAssignmentId'}."\t".(defined($lookupRow->{'status'})?$lookupRow->{'status'}:"No status")."\n";
                $szLookup = "select unitId, coalesce(description, hostname, hex(dhcpClientId)) as desc from unit where ipAddress = ? order by    
        } else {
                print "No record found!\n";
        }



}
    print "\nNOTE! Should only handle unhandled...\n";











# Insert new records into the table
#my $insert_sql = "INSERT INTO internalInfections (ip, nettmask, status) VALUES (?, ?, ?)";
#my $sth = $dbh->prepare($insert_sql);

# Example data to insert
#my @data = (
#    ['Alice', 25],
#    ['Bob', 30],
#    ['Charlie', 28],
#);




# Clean up
$sth->finish();
$dbh->disconnect();





#foreach my $row (@data) {
#    $sth->execute(@$row) or die $DBI::errstr;
#    print "Inserted: @$row\n";
#}

# Disconnect from the database
$dbh->disconnect;
print "Done.\n";

