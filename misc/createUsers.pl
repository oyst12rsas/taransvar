#!/usr/bin/perl
use strict;
use warnings;
use FindBin;
# Kept for existing installers; credential provisioning never logs passwords.
exec 'bash', "$FindBin::Bin/install_database_credentials.sh";
die "Unable to start local database credential installer\n";
