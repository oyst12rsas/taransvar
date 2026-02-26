#Called from install.sh... to install certificate... first part is same as sendReport.pl... also make relevant changes there...

use strict;
use warnings;
use autodie;
use DBI;

print "Checking dir..\n";

#my $dir = '/home';
#opendir( DIR, $dir ) or die $!;
#my $szUser = "";

#while ( my $file = readdir(DIR) ) {	# && $szUser eq ""

# We only want dirs
 #  next unless ( -d "$dir/$file" );

# Use a regular expression to find files ending in .txt
#next unless ( $file =~ /\.pdf$/ );
#	if ($file ne "." && $file ne ".." && $file ne "setup")
#	{
		#Check if there's downloaded file...
#		if ( -f "$dir/$file/Downloads/Taransvar.tar.gz" )
#		{
#			print "$file\n";
#			$szUser = $file;
#		}
#	}
#}

#Better method to find superuser: getent group root wheel adm admin  (and see who's in adm...)
#closedir(DIR);

#if ($szUser eq "") {
#	print "\n*********** UNABLE TO FIND USER (downloaded file not found!)*******************\n";
#} else {
#	print "Calling checkCert as $szUser...\n\n";
	#system ('sudo -i -u '.$szUser.' /usr/bin/perl /home/setup/perl/checkCert.pl');
	
	print "Printing keys..";
	system('gpg --list-keys > ~/grpkeys.txt');

	my $szCertFile = "distro/copythese/oystein.gpg";
	
	if (! -f $szCertFile)
	{
		print "\ngpg file not found, using default! *********\n";
		$szCertFile = "/home/oystein/Downloads/home/setup/distro/copythese/oystein.gpg";
	}
	
	#$szCertFile = "/home/setup/distro/copythese/oystein.gpg";
	#$szCertFile = "~/Downloads/home/setup/distro/copythese/oystein.gpg";
system('gpg --import '.$szCertFile);

system('gpg --list-keys > ~/grpkeys2.txt');
	
	
	
	my $szSysSetupFile = "/home/setup/setup.txt";
	open(my $fh, '>>', $szSysSetupFile) or die "Could not open file '$szSysSetupFile' $!";
	print $fh "Superuser=???\n";
	close $fh;

	#open(my $fh, '>>', "/home/$szUser/system.txt") or die "Could not open file '$szSysSetupFile' $!";
	#print $fh "This is the superuser (please don't touch this file)";
	#close $fh;
#}