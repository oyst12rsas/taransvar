#!/usr/bin/perl
use strict;
use warnings;
use File::Basename qw(basename);
use File::Compare qw(compare);

my $html_required = 0;
for my $src (glob('../html/*.*')) {
    next unless -f $src;
    my $dst = '/var/www/html/' . basename($src);
    if (!-f $dst || compare($src, $dst) != 0) {
        $html_required = 1;
        last;
    }
}

my $perl_required = 0;
for my $src (glob('./*.*')) {
    next unless -f $src;
    my $dst = '/root/taransvar/perl/' . basename($src);
    if (!-f $dst || compare($src, $dst) != 0) {
        $perl_required = 1;
        last;
    }
}

print "\nDeployment required after pull/compile:\n";
print "  sudo cp *.* /root/taransvar/perl\n" if $perl_required;
print "  sudo cp ../html/*.* /var/www/html\n" if $html_required;
print "  No Perl/HTML file copies required.\n" if !$perl_required && !$html_required;

exit(($perl_required || $html_required) ? 1 : 0);
