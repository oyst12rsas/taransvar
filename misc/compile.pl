#!/usr/bin/perl
#*******NOTE! programRunning("taralink") returned true at Grace's computer
#Note! Should also give warning when modprobe: ERROR: could not insert 'tarakernel': Key was rejected by service.... "Secure boot" in BIOS is probably activated
#Also open this in other terminal unless already running: sudo dmesg -w | grep -v "^[[:space:]]*$"
use strict;
use warnings;
use File::stat;
use Time::localtime;
use Data::Dumper;
use File::stat;
use Cwd qw(getcwd);
use File::Basename;

#NOTE! 
#	- Should if tarakernel.ko is running before and after modprobe
#	- Check that taralink is not running in other window....


#Trying to use func from same dir as compile.pl (so that can start from other folder) but not yet found out how...
#my $dirname = dirname(__FILE__);
#print "Dir: ".$dirname."\n";
#This is not working: use lib ($dirname);
use lib (".");	#So settling with this for now	
use func;

my $szSysRoot = "/root/setup";

if (-d $szSysRoot) {
    # directory called cgi-bin exists
    #print "Setup directory already exists...\n";
}
else {
       system("mkdir ".$szSysRoot);
}
if (-d $szSysRoot."log") {
    # directory called cgi-bin exists
    #print "Setup log directory already exists...\n";
}
else {
       system("mkdir ".$szSysRoot."/log");
}


sub fileModified {
	my ($szFilename, $nMinSize, $nMaxSeconds) = @_;
	if (-f $szFilename) {
		print "$szFilename exists..\n";
	} else {
		print "\n******* ERROR ***** Was not able to create $szFilename\n\n";
		return 0;
	}

	my $sb = stat($szFilename);
	my $nSeconds = time() - $sb->mtime; #$timestamp;
	if ($sb->size < $nMinSize) {
		printf "**** ERROR *** %s is only %d bytes. Supposed to be %d\n\n", $szFilename, $sb->size, $nMinSize;
		return -$sb->size;	#File is too small... return negative size
	}

	if ($nSeconds > $nMaxSeconds) {
		printf "%s was changed %d ago. Size: %d\n\n", $szFilename, $nSeconds, $sb->size;
		print "This is probably because of compiling error (check for errors above - often in red)\n";
		return 0;
	} else {
		printf "%s has been updated. Size: %d\n", $szFilename, $sb->size;
	}
	return 1;	#All well
}

sub miscDir {
	return "./";	#Do this better than assume current dir...
}

if (programRunning("taralink", "taralink/")) {
	print "*********** taralink is running in other window... Please close it (Ctrl-C) and try again.\n";
	exit;
}

print getcwd()."\n";
my $szCurDir = getcwd();
if (substr($szCurDir, length($szCurDir)-5) ne "/misc") {
	#Note... need to start in the misc directory to know where tarakernel and taralink are located.
	print "Please move to the directory where compile.pl is located, then try again.\n";
	exit;
}
sleep(5);


print "******** TRYING TO COMPILE tarakernel (Taransvar linux kernel module) ************\n\nPlease wait while compiling...\n\n";

print "touching tarakernel to enforce compilation..\n";
system("touch ../tarakernel/tarakernel.h");	#To ensure that it's compiled...
my $szAbSecLogFile = getSysRoot()."log/abseclog.txt"; 
#Not working: open STDERR, '>&STDOUT'; #This may redirect stderr to stdout....
system ("make -C ../tarakernel > $szAbSecLogFile"); 	#&> also saves stderr content which plain > doesn't
#close(STDERR); - don't know if this should be done...
open(FILE, $szAbSecLogFile) or die "Can't read file 'filename' [$!]\n";  
my $szCompileLog = <FILE>; 
close (FILE);
print "\nTHIS IS COMPILE LOG: $szCompileLog\n";

#Not working... unable to catch STDERR... so error messages in on screen and not in log file... Using touch and checking if compiled instead.
#if ($szCompileLog =~ /rror/) {
#	print "****** Compiler error... aborting..\n\n";
#	exit;
#}

my $szKoFilename = '../tarakernel/tarakernel.ko';
my $nFileSize = fileModified($szKoFilename, 51*1000, 10);       #Raspberry version (mariadb) is only 54k, Ubuntu 797kb
if ($nFileSize <= 0) {
	print "Try yourself:\ncd programming/tarakernel\nmake -C .\n\n";
	printf "Later version should retry:...\ncd tarakernel\nmake clean\nmake -C .\n";
	exit;
} else {
	print "****** tarakernel kernel module (probably) successfully compiled *****\n";
}

#First find current kernel version..
#asdf
my $szLogFile = getSysRoot()."log/log.txt"; 
system("uname -r > $szLogFile");
my $szUname = getFileContents($szLogFile);
$szUname = trim($szUname);
#print "Kernel version: $szUname\n"; 

#***** Make sure tarakernel is not running****
if (moduleRunning("tarakernel")) {
	print "tarakernel is running... trying to stop it..\n";
	system("rmmod tarakernel");
	if (moduleRunning("tarakernel")) {
		#Most likely reason is that taralink is running and keeps a netlink session open. Try to kill it. 
		doKill("taralink");
		system("rmmod tarakernel");
		if (moduleRunning("tarakernel")) {
			print "******** ERROR! - Unable to stop tarakernel. Is there an error on line above?\nYou can also try to run: sudo rmmod tarakernel..\nAlso check if taralink is running (and holds a netlink connection): sudo ps -aux | grep taralink\nNext option may be to restart the computer (the reason is probably an open netsocket, but we checked if you're running taralink in other window..)\n";
			exit;
		} else {
			print "tarakernel was running but able to stop it after first stopping taralink (probably holding a netlink conneciton open)\n";
		}
	}
} else {
	print "**** WARNING **** - tarakernel was not running... Maybe a crash?\n";
}

#******** Copy new module to modules lib ******
my $szCmd = "sudo rm /lib/modules/".$szUname."/tarakernel.ko";
#print "\nAbout to run: $szCmd\n";
system($szCmd);
#sudo rm /lib/modules/$(shell uname -r)/tarakernel.ko
$szCmd = "cp ../tarakernel/tarakernel.ko /lib/modules/".$szUname; 
#print "\nAbout to run: $szCmd\n";
system($szCmd);
#sudo cp tarakernel.ko /lib/modules/$(shell uname -r)
system ("depmod -a");
system ("modprobe tarakernel > $szLogFile");

#NOTE! If too much problems with modprobe, can also try insmod
#NOTE! The problem here is that modprobe prints to STDERR and not STDOUT... So need to find other way to catch the error message.
#open(FILE, $szLogFile) or die "Can't read file $szLogFile after running modprobe [$!]\n";  
#my $szModprob = <FILE>; 
#close (FILE);

#if (defined($szModprob) && index($szModprob, "ERROR:") != -1) {
#	print "\nError while running modprobe!\n\n$szModprob\n\n";
#	exit 0;
#}

#if (length($szModprob)) {
#	print "******* ERROR ****\n\n$szModprob\n";
#}

if (!moduleRunning("tarakernel")) {
	print "******** ERROR - seems like unable to start tarakernel.. \n";
	print "\nDo you see this message?:\nmodprobe: ERROR: could not insert 'tarakernel': No child processes\n\nIf so, we'd like to learn how to handle it better. But it seems\nlike booting the computer helps (a likely cause may be problems listening to netlink socket\n\n";
	print "If you get the error:\n\nERROR: could not insert 'tarakernel': Key was rejected by service....\n\nThen it probably means that \"Secure boot\" in BIOS is activated. Please deactivate and try again.\n\n";

	exit 0;
} else {
	print "\nSeems like tarakernel compiled and started successfully!\n\n";
}

system ("lsmod | grep tarakernel");
system ("ls /lib/modules/".$szUname." -l | grep tarakernel");
sleep 3;

print "\n\n\n******** TRYING TO COMPILE taralink (Taransvar user space program) ************\n";
#cd ~/programming/abmonitor

#print getcwd()."\n";
#system 'pwd';
 
chdir "..";
#print getcwd()."\n";
#system 'pwd';

chdir "taralink";
#print getcwd();

$szLogFile = getSysRoot()."log/gcc.txt"; 
system ("gcc taralink.c -o taralink -L/usr/lib/mysql -lmariadb -lcurl > $szLogFile");
my $szFilename = './taralink';
$nFileSize = fileModified($szFilename, 45*1000, 10);
if ($nFileSize <= 0) {
	print "\n****** ERROR ***** Failed to compile the taralink user space program!\n\n";
	exit 1;
} else {
	system ("cp $szFilename /root/taransvar");

	if ($ARGV[0] && ($ARGV[0] eq "install")) {
		print "\nCompile initiated by install script.. Quitting here.\n";
		exit 0;
	}
	print "******* Successfully created taralink user space program *****\n\n**** Starting....\n\n";
	
	#print "\n************** Skipping starting taralink...\n(To start manually:  ../taralink/taralink )\n";
	#print "\nOpening taralink in other window...\n";
	
	#This works, but starts taralink in separate window that closes if taralink aborts... and then we want to see error message... so drop for now
	#It also seems to leave a part active that we can't get rid of (compile complains that taralink is open in other window)
	#startTaraLinkOk();
	
	system ($szFilename);	#Start taralink
	exit 0;
}


