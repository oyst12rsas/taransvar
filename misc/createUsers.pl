#!/usr/bin/perl
use strict;
use warnings;
use autodie;
use DBI;
use Data::Dumper qw(Dumper);
use File::Copy;

use lib ('.');	#Don't change this to allow opening from elsewhere because trying to hardcode full path below...
use func;
#use lib_cron;
use lib_diagnose;

if (createUsersOk()) {
	exit 0;	#exit code 0 means all well
} else {
	exit 1;	#exit after error
}

