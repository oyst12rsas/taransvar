package TaraSecDB;
use strict;
use warnings;
use Exporter 'import';
our @EXPORT_OK = qw(db_password);
sub db_password {
    my ($kind) = @_;
    die "Invalid local database account\n" unless defined($kind) && $kind =~ /\A(?:app|perl)\z/;
    my $path = $ENV{'TARASEC_DB_'.uc($kind).'_PASSWORD_FILE'} // "/etc/tarasec/db-$kind.password";
    open(my $fh, '<', $path) or die "Local database credential unavailable; run the updated installer\n";
    local $/;
    my $password = <$fh>;
    close($fh);
    $password =~ s/\s+\z//;
    die "Invalid local database credential\n" unless $password =~ /\A[a-f0-9]{64}\z/;
    return $password;
}
1;
