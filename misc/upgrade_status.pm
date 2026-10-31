package upgrade_status;
use strict;
use warnings;
use JSON qw(decode_json encode_json);
use MIME::Base64 qw(decode_base64);
use File::Temp qw(tempfile);
use Exporter 'import';
our @EXPORT_OK = qw(softwareForReport upgradeReceipt);
sub softwareForReport {
    my $value = _softwareForReport(@_);
    my $helper = __FILE__;
    $helper =~ s{[^/]+$}{automatic_upgrade_status.py};
    if (-f $helper && open(my $pipe, '-|', '/usr/bin/python3', $helper)) {
        local $/;
        my $raw = <$pipe>;
        if (close($pipe)) {
            my $automatic = eval { decode_json($raw) };
            $value->{automatic_upgrade} = $automatic if ref($automatic) eq 'HASH';
        }
    }
    return $value;
}
sub _softwareForReport {
    my ($path) = @_;
    $path //= '/var/lib/tarasec/upgrade/software.json';
    return {version => undef, status => 'not_reported'} unless -f $path && !-l $path && (-s $path // 0) <= 32768;
    open(my $fh, '<', $path) or return {version => undef};
    local $/; my $value = eval { decode_json(<$fh>) }; close($fh);
    return {version => undef} unless ref($value) eq 'HASH';
    return $value;
}
sub upgradeReceipt {
    my ($response, $primary, $directory) = @_;
    return unless $primary && $response->is_success;
    $directory //= '/var/lib/tarasec/upgrade';
    return unless -d $directory && !-l $directory;
    my $raw = $response->header('X-Tarasec-Upgrade') // '';
    return unless length($raw) && length($raw) <= 8192 && $raw =~ m{^[A-Za-z0-9+/=]+$};
    my $value = eval { decode_json(decode_base64($raw)) };
    return unless ref($value) eq 'HASH' && ref($value->{pending}) eq 'ARRAY' && @{$value->{pending}} <= 8;
    # It is an advisory receipt, never an executable operation or permission.
    my $receipt = {checked_at => time(), pending => $value->{pending},
                   catalog_status => $value->{catalog_status} // 'unavailable'};
    my ($fh, $temp) = tempfile('notice-XXXXXX', DIR => $directory, UNLINK => 0);
    chmod(0600, $temp); print $fh encode_json($receipt); close($fh);
    rename($temp, "$directory/notice.json") or unlink($temp);
}
1;
