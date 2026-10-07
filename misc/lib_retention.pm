package lib_retention;
use strict;
use warnings;
use Exporter 'import';
use Time::HiRes qw(time);
our @EXPORT_OK = qw(read_policy retention_plan run_retention);

sub read_policy {
    my ($path) = @_;
    my %cfg = (ENABLED => 1, TELEMETRY_DAYS => 30, THREAT_DAYS => 90,
        BATCH_ROWS => 1000, BATCHES_PER_TABLE => 20, TIME_BUDGET_SECONDS => 45);
    if (defined($path) && -e $path) {
        open my $fh, '<', $path or die "Cannot read retention policy: $!\n";
        while (<$fh>) {
            s/#.*//; s/^\s+|\s+$//g;
            next unless length;
            my ($key, $value) = split /\s*=\s*/, $_, 2;
            die "Invalid retention option: $key\n" unless exists $cfg{$key};
            die "Invalid value for $key\n" unless defined($value) && $value =~ /^\d+$/;
            $cfg{$key} = 0 + $value;
        }
        close $fh;
    }
    die "ENABLED must be 0 or 1\n" unless $cfg{ENABLED} <= 1;
    for my $key (qw(TELEMETRY_DAYS THREAT_DAYS)) {
        die "$key must be 1..3650 days\n" unless $cfg{$key} >= 1 && $cfg{$key} <= 3650;
    }
    die "THREAT_DAYS must be at least TELEMETRY_DAYS\n"
        if $cfg{THREAT_DAYS} < $cfg{TELEMETRY_DAYS};
    for my $pair ([BATCH_ROWS => 10000], [BATCHES_PER_TABLE => 100], [TIME_BUDGET_SECONDS => 60]) {
        die "$pair->[0] outside allowed range\n" unless
            $cfg{$pair->[0]} >= 1 && $cfg{$pair->[0]} <= $pair->[1];
    }
    return \%cfg;
}

sub qi {
    my ($name) = @_;
    $name =~ s/`/``/g;
    return "`$name`";
}

# Only append-only telemetry is eligible. No identity, permissions, accounting,
# infection, incident, assistance or delivery-queue tables are removed.
sub retention_plan {
    my ($columns, $cfg, $foreign_keys) = @_;
    my @spec = (
        [traffic => 'trafficId', 'created'],
        [syslogThreat => 'syslogThreatId', 'created', 'handled'],
        [syslog => 'syslogId', 'created'],
        [dhcpEvent => 'dhcpEventId', 'seenAt', 'handled'],
        [dmesg => 'dmesgId', 'created'],
        [partnerRouterStatusLog => 'ip', 'created'],
        [aiResponse => 'aiResponseId', 'created'],
        [loginAttempt => 'loginAttemptId', 'theTime'],
    );
    my @plan;
    for my $spec (@spec) {
        my ($table, $id, $date, $handled) = @$spec;
        my $cols = $columns->{$table} or next;
        # Status log has a composite key; handled below using created + ip.
        next unless $cols->{$id} && $cols->{$date};
        next if $handled && !$cols->{$handled};
        my @where = (qi($date) . ' < ?');
        push @where, '(' . qi('lastSeen') . ' IS NULL OR ' . qi('lastSeen') . ' < ?)'
            if $cols->{lastSeen};
        push @where, qi($handled) . " = b'1'" if $handled;
        if ($table eq 'syslogThreat') {
            push @where, '`demoSshSessionId` IS NULL' if $cols->{demoSshSessionId};
        }
        # Logical evidence links are not all declared as foreign keys.
        my @refs = $table eq 'syslogThreat' ? (
            [demoSshSession => 'nodeAEvidenceId'], [demoSshSession => 'nodeBEvidenceId'],
            [demoSshEvent => 'syslogThreatId'],
        ) : $table eq 'syslog' ? (
            [syslogThreat => 'syslogId'], [partnerObservationLocalEvidence => 'syslogId'],
        ) : ();
        for my $ref (@refs) {
            my ($other, $col) = @$ref;
            next unless $columns->{$other};
            die "Cannot protect $other evidence: missing $col\n" unless $columns->{$other}{$col};
            push @where, 'NOT EXISTS (SELECT 1 FROM ' . qi($other) . ' r WHERE r.' . qi($col)
                . ' = ' . qi($table) . '.' . qi($id) . ')';
        }
        # Retain referenced rows even when an installation added ON DELETE CASCADE.
        for my $fk (@{$foreign_keys // []}) {
            next unless $fk->{target} eq $table;
            push @where, 'NOT EXISTS (SELECT 1 FROM ' . qi($fk->{schema}) . '.' . qi($fk->{table})
                . ' r WHERE ' . join(' AND ', map { 'r.' . qi($_->[0]) . ' = '
                    . qi($table) . '.' . qi($_->[1]) } @{$fk->{pairs}}) . ')';
        }
        push @plan, {table => $table, id => $id, date => $date,
            composite => ($table eq 'partnerRouterStatusLog'),
            days => $table eq 'syslogThreat' ? $cfg->{THREAT_DAYS} : $cfg->{TELEMETRY_DAYS},
            where => join(' AND ', @where), dates => $cols->{lastSeen} ? 2 : 1};
    }
    return \@plan;
}

sub run_retention {
    my ($dbh, $cfg, $state, $apply, $emit) = @_;
    $emit //= sub { print "$_[0]\n" };
    return $state unless $cfg->{ENABLED};
    my $meta = $dbh->selectall_arrayref(q{
        SELECT c.TABLE_NAME,c.COLUMN_NAME FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
        WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE'
    });
    my %columns;
    $columns{$_->[0]}{$_->[1]} = 1 for @$meta;
    my $fk_rows = $dbh->selectall_arrayref(q{
        SELECT CONSTRAINT_SCHEMA,TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,
               REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE REFERENCED_TABLE_SCHEMA=DATABASE() ORDER BY ORDINAL_POSITION
    });
    my %fk;
    for my $r (@$fk_rows) {
        my $key = join ':', @$r[0..2];
        $fk{$key} //= {schema => $r->[0], table => $r->[1], target => $r->[4], pairs => []};
        push @{$fk{$key}{pairs}}, [$r->[3], $r->[5]];
    }
    my $plan = retention_plan(\%columns, $cfg, [values %fk]);
    my $started = time();
    my (%cutoff, %ceiling, %batches, %total, %done);
    # Freeze the scan ceiling so continuously arriving rows cannot prevent wraparound.
    for my $p (@$plan) {
        ($cutoff{$p->{table}}) = $dbh->selectrow_array('SELECT DATE_SUB(NOW(), INTERVAL ? DAY)', undef, $p->{days});
        next if $p->{composite};
        ($ceiling{$p->{table}}) = $dbh->selectrow_array('SELECT MAX(' . qi($p->{id}) . ') FROM ' . qi($p->{table}));
    }
    $state->{cursors} //= {};
    my $offset = ($state->{next_table} // 0) % (@$plan || 1);
    my @order = @$plan ? (@$plan[$offset .. $#$plan], @$plan[0 .. $offset - 1]) : ();
    while (grep { !$done{$_->{table}} } @order) {
        for my $p (@order) {
            last if time() - $started >= $cfg->{TIME_BUDGET_SECONDS};
            my ($table, $id) = @$p{qw(table id)};
            next if $done{$table};
            my @bind = ($cutoff{$table}) x $p->{dates};
            my $where = $p->{where};
            if ($p->{composite}) {
                my $cursor = $state->{cursors}{$table} // {ip => 0, created => '1000-01-01 00:00:00'};
                die "Invalid status-log cursor\n" unless ref($cursor) eq 'HASH'
                    && ($cursor->{ip} // '') =~ /^\d+$/
                    && ($cursor->{created} // '') =~ /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/;
                my $lower = '(`ip` > ? OR (`ip` = ? AND `created` > ?))';
                my @lower_bind = ($cursor->{ip}, $cursor->{ip}, $cursor->{created});
                my $keys = $dbh->selectall_arrayref('SELECT `ip`,`created` FROM ' . qi($table)
                    . ' WHERE ' . $lower . ' ORDER BY `ip`,`created` LIMIT '
                    . $cfg->{BATCH_ROWS}, undef, @lower_bind);
                if (!@$keys) { delete $state->{cursors}{$table}; $done{$table} = 1; next; }
                my ($ip, $created) = @{$keys->[-1]};
                $where = $lower . ' AND (`ip` < ? OR (`ip` = ? AND `created` <= ?)) AND ' . $where;
                unshift @bind, @lower_bind, $ip, $ip, $created;
                $state->{cursors}{$table} = {ip => $ip, created => $created};
                if (@$keys < $cfg->{BATCH_ROWS}) {
                    $done{$table} = 1;
                    delete $state->{cursors}{$table};
                }
            } else {
                my $cursor = $state->{cursors}{$table} // 0;
                die "Invalid cursor for $table\n" unless $cursor =~ /^\d+$/;
                # A restored database can have a lower maximum than the saved cursor.
                $cursor = 0 if $cursor >= ($ceiling{$table} // 0);
                my $ids = $dbh->selectcol_arrayref('SELECT ' . qi($id) . ' FROM ' . qi($table)
                    . ' WHERE ' . qi($id) . ' > ? AND ' . qi($id) . ' <= ? ORDER BY '
                    . qi($id) . ' LIMIT ' . $cfg->{BATCH_ROWS}, undef, $cursor, $ceiling{$table} // 0);
                if (!@$ids) { $state->{cursors}{$table} = 0; $done{$table} = 1; next; }
                $where = qi($id) . ' > ? AND ' . qi($id) . ' <= ? AND ' . $where;
                unshift @bind, $cursor, $ids->[-1];
                $state->{cursors}{$table} = $ids->[-1];
                if ($ids->[-1] >= $ceiling{$table}) {
                    $done{$table} = 1;
                    $state->{cursors}{$table} = 0;
                }
            }
            my $count;
            if ($apply) {
                $count = 0 + $dbh->do('DELETE FROM ' . qi($table) . ' WHERE ' . $where
                    . ' LIMIT ' . $cfg->{BATCH_ROWS}, undef, @bind);
            } else {
                # Bounded preview; no expensive full-table COUNT(*) query.
                my $rows = $dbh->selectall_arrayref('SELECT ' . qi($id) . ' FROM ' . qi($table)
                    . ' WHERE ' . $where . ' LIMIT ' . $cfg->{BATCH_ROWS}, undef, @bind);
                $count = scalar @$rows;
            }
            $total{$table} += $count;
            $done{$table} = 1 if ++$batches{$table} >= $cfg->{BATCHES_PER_TABLE};
            my ($index) = grep { $plan->[$_]{table} eq $table } 0 .. $#$plan;
            $state->{next_table} = ($index + 1) % @$plan;
        }
        last if time() - $started >= $cfg->{TIME_BUDGET_SECONDS};
    }
    for my $p (@$plan) {
        my $table = $p->{table};
        $emit->("$table: " . ($total{$table} // 0) . ($apply ? ' deleted' : ' eligible in preview')
            . "; keep $p->{days} days; batches=" . ($batches{$table} // 0));
    }
    return $state;
}
1;
