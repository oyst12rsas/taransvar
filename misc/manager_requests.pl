#!/usr/bin/perl
use strict;
use warnings;
use lib ('/root/taransvar/perl');
use DBI;
use Digest::SHA qw(sha256_hex);
use HTTP::Tiny;
use JSON::PP qw(encode_json decode_json);
use POSIX qw(strftime);
use func;

# Generates manager credentials/tokens locally. If MAIL_SERVICE_URL is set in
# /etc/tarasec.conf, unsent email-verification requests are submitted to the
# TaraSec back-office template mail service. Gateway approval remains local.
# The relay health result is written to /run/tarasec-mail-relay-status.json and
# is also sent as a partial gateway status report to the global DB server(s).

my $STATUS_FILE = '/run/tarasec-mail-relay-status.json';

sub report_mail_status_to_db {
    my ($dbh, $status) = @_;

    my $payload = encode_json({
        _partialStatus => 1,
        mailCfg        => $status->{configured} ? 1 : 0,
        mailRelay      => $status->{relayReachable} ? 1 : 0,
        mailSend       => $status->{sendingService} ? 1 : 0,
        mailChecked    => $status->{checkedAt} // '',
        mailErr        => $status->{error} // '',
    });

    my $sth = $dbh->prepare(q{
        SELECT INET_NTOA(globalDb1ip) AS db1,
               INET_NTOA(globalDb2ip) AS db2,
               INET_NTOA(globalDb3ip) AS db3
          FROM setup
         LIMIT 1
    });
    $sth->execute();
    my $row = $sth->fetchrow_hashref() || {};
    $sth->finish();

    my %seen;
    my $http = HTTP::Tiny->new(timeout => 3);
    for my $db_ip ($row->{db1}, $row->{db2}, $row->{db3}) {
        next unless defined($db_ip) && $db_ip ne '';
        next if $seen{$db_ip}++;

        my $res = $http->post("http://$db_ip/script/statusReport.php", {
            headers => {'content-type' => 'application/json'},
            content => $payload,
        });
        if ($res->{success}) {
            print "Mail health reported to DB server $db_ip.\n";
        } else {
            my $code = $res->{status} // 0;
            my $reason = $res->{reason} // 'unknown error';
            warn "Unable to report mail health to DB server $db_ip: HTTP $code $reason\n";
        }
    }
}

sub write_mail_status {
    my ($dbh, %status) = @_;
    $status{checkedAt} = strftime('%Y-%m-%dT%H:%M:%SZ', gmtime());

    if (open(my $fh, '>', $STATUS_FILE)) {
        print $fh encode_json(\%status), "\n";
        close($fh);
        chmod 0644, $STATUS_FILE;
    }

    eval { report_mail_status_to_db($dbh, \%status); 1 } or do {
        warn "Unable to publish mail health status: $@";
    };
}

sub random_hex_32 {
    my $value = `openssl rand -hex 32 2>/dev/null`;
    chomp $value;
    die "Unable to generate secure random value with openssl\n"
        unless $value =~ /^[0-9a-f]{64}$/;
    return $value;
}

sub read_tarasec_config {
    my %cfg;
    my $file = '/etc/tarasec.conf';
    return \%cfg unless -r $file;
    open(my $fh, '<', $file) or return \%cfg;
    while (my $line = <$fh>) {
        chomp $line;
        $line =~ s/^\s+|\s+$//g;
        next if $line eq '' || $line =~ /^#/;
        my ($k, $v) = split(/\s*=\s*/, $line, 2);
        if (defined($k) && defined($v)) {
            $v =~ s/^['"]|['"]$//g;
            $cfg{$k} = $v;
        }
    }
    close($fh);
    return \%cfg;
}

my $dbh = getConnection();
my $select = $dbh->prepare(q{
    SELECT managerRequestId
    FROM managerRequest
    WHERE credentialHash IS NULL
      AND rejectedTime IS NULL
      AND (expires IS NULL OR expires > NOW())
    ORDER BY managerRequestId
    LIMIT 20
});

eval { $select->execute(); 1 } or do {
    print "managerRequest table not available yet; nothing to generate.\n";
    $dbh->disconnect();
    exit 0;
};

my $update = $dbh->prepare(q{
    UPDATE managerRequest
       SET credentialPlain = ?, credentialHash = ?, credentialCreatedTime = NOW(),
           emailVerifyTokenPlain = ?, emailVerifyTokenHash = ?,
           active = IF(emailVerifiedTime IS NOT NULL AND gatewayApprovedTime IS NOT NULL, b'1', active)
     WHERE managerRequestId = ? AND credentialHash IS NULL
});

while (my $row = $select->fetchrow_hashref()) {
    my $credential = random_hex_32();
    my $email_token = random_hex_32();
    $update->execute($credential, sha256_hex($credential), $email_token,
                     sha256_hex($email_token), $row->{managerRequestId});
    print "Generated manager credential and email token for request $row->{managerRequestId}.\n"
        if $update->rows > 0;
}
$update->finish();
$select->finish();

my $cfg = read_tarasec_config();
my $mail_url = $cfg->{MAIL_SERVICE_URL} // '';
if ($mail_url ne '') {
    my $health_url = $cfg->{MAIL_SERVICE_HEALTH_URL} // $mail_url;
    $health_url =~ s{/send\.php(?:\?.*)?$}{/health.php};
    my $http = HTTP::Tiny->new(timeout => 10);
    my $health = $http->get($health_url);
    my $relay_reachable = $health->{success} ? 1 : 0;
    my $sending_service = 0;
    my $health_error = '';

    if ($health->{success}) {
        eval {
            my $h = decode_json($health->{content} // '{}');
            $sending_service = $h->{sendingService} ? 1 : 0;
            $health_error = $h->{error} // '';
        };
        $health_error = 'invalid_health_response' if $@;
    } else {
        $health_error = 'HTTP '.($health->{status} // 0).' '.($health->{reason} // 'unreachable');
    }

    write_mail_status(
        $dbh,
        configured => 1,
        relayReachable => $relay_reachable,
        sendingService => $sending_service,
        healthUrl => $health_url,
        error => $health_error,
    );
    print "Mail relay health: relay=".($relay_reachable?'UP':'DOWN').", sending service=".($sending_service?'UP':'DOWN')."\n";

    my $nickname = '';
    eval {
        my $sth = $dbh->prepare('SELECT nickname FROM setup LIMIT 1');
        $sth->execute();
        my $r = $sth->fetchrow_hashref();
        $nickname = $r->{nickname} // '' if $r;
        $sth->finish();
    };
    $nickname = 'TaraSec gateway' if $nickname eq '';
    my $owner = $cfg->{MAIL_ROUTER_OWNER} // $nickname;

    my $pending = $dbh->prepare(q{
        SELECT managerRequestId, email, emailVerifyTokenPlain
          FROM managerRequest
         WHERE emailSentTime IS NULL
           AND emailVerifyTokenPlain IS NOT NULL
           AND rejectedTime IS NULL
           AND (expires IS NULL OR expires > NOW())
         ORDER BY managerRequestId
         LIMIT 20
    });
    $pending->execute();
    my $mark = $dbh->prepare(q{
        UPDATE managerRequest
           SET emailSentTime = NOW(), emailVerifyTokenPlain = NULL
         WHERE managerRequestId = ? AND emailSentTime IS NULL
    });

    while (my $row = $pending->fetchrow_hashref()) {
        if (!$relay_reachable || !$sending_service) {
            warn "Verification email remains pending: mail relay/sending backend is not healthy.\n";
            next;
        }
        my $payload = encode_json({
            template => 'manager_activation',
            to => $row->{email},
            variables => {
                name => $row->{email},
                router_name => $nickname,
                router_owner => $owner,
                activation_code => $row->{emailVerifyTokenPlain},
            },
        });
        my $res = $http->post($mail_url, {
            headers => {'content-type' => 'application/json'},
            content => $payload,
        });
        if ($res->{success}) {
            $mark->execute($row->{managerRequestId});
            print "Queued manager verification email for request $row->{managerRequestId}.\n";
        } else {
            my $status = $res->{status} // 0;
            my $reason = $res->{reason} // 'unknown error';
            write_mail_status(
                $dbh,
                configured => 1,
                relayReachable => 1,
                sendingService => 0,
                healthUrl => $health_url,
                error => "send failed: HTTP $status $reason",
            );
            warn "Mail service failed for manager request $row->{managerRequestId}: HTTP $status $reason\n";
        }
    }
    $mark->finish();
    $pending->finish();
} else {
    write_mail_status(
        $dbh,
        configured => 0,
        relayReachable => 0,
        sendingService => 0,
        error => 'MAIL_SERVICE_URL not configured'
    );
    print "MAIL_SERVICE_URL not configured; verification email delivery skipped.\n";
}

$dbh->disconnect();
