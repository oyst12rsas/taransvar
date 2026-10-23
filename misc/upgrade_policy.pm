package upgrade_policy;
use strict;
use warnings;
use Exporter 'import';
our @EXPORT_OK = qw(requireUpgradeConfirmation);
sub requireUpgradeConfirmation {
    my ($path, $confirmed, $interactive, $ask) = @_;
    return unless -e $path; # Legacy unconfigured hosts retain their prior policy.
    open(my $fh, '<', $path) or die "Cannot inspect upgrade policy\n";
    my $mode = <$fh> // ''; close($fh); $mode =~ s/^\s+|\s+$//g;
    return if $mode eq 'demo';
    die "Invalid upgrade mode; migration stopped\n" unless $mode eq 'production';
    return if $confirmed;
    die "Production database upgrades require confirmation: sudo tarasec upgrade\n" unless $interactive;
    die "Database upgrade postponed\n" unless $ask->();
}
1;
