#!/usr/bin/perl
use strict;
use warnings;
use DBI;
use Getopt::Long qw(GetOptions);

my ($set_admin_password,$help);
GetOptions('set-admin-password:s'=>\$set_admin_password,'help'=>\$help) or usage(1);
usage(0) if $help;
die "This command must be run as root. Use: sudo tarasec-users\n" if $> != 0;

my $dbh=DBI->connect('DBI:mysql:database=taransvar','root','',{RaiseError=>1,PrintError=>0,AutoCommit=>1,mysql_enable_utf8mb4=>1})
    or die "Unable to connect to TaraSec database.\n";
ensure_schema($dbh);
if (defined $set_admin_password) { set_admin_password($dbh,$set_admin_password); exit 0; }
print "=== TARASEC ACCESS ACCOUNTS ===\n\n";
show_admins($dbh); print "\n"; show_hotspot_users($dbh);
print "\nAdmin passwords are never displayed.\nTo create the first administrator or reset an existing one:\n  sudo tarasec-users --set-admin-password\n";
$dbh->disconnect; exit 0;

sub column_exists {
 my($dbh,$table,$col)=@_; my $s=$dbh->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
 $s->execute($table,$col); my($n)=$s->fetchrow_array; return $n?1:0;
}
sub table_exists {
 my($dbh,$table)=@_; my $s=$dbh->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
 $s->execute($table); my($n)=$s->fetchrow_array; return $n?1:0;
}
sub ensure_schema {
 my($dbh)=@_;
 my @usercols=(
  ['isAdmin',"bit(1) NOT NULL DEFAULT b'0'"],['lastLogin','timestamp NULL DEFAULT NULL'],['lastLoginIp','int(10) unsigned DEFAULT NULL'],
  ['loginFailsSinceSuccess','int(10) unsigned NOT NULL DEFAULT 0'],['loginFailReportedTime','timestamp NULL DEFAULT NULL']);
 for my $c(@usercols){ next if column_exists($dbh,'user',$c->[0]); $dbh->do("ALTER TABLE user ADD COLUMN $c->[0] $c->[1]"); }
 if (table_exists($dbh,'setup')) {
  $dbh->do("ALTER TABLE setup ADD COLUMN selfRegistration bit(1) NOT NULL DEFAULT b'1'") unless column_exists($dbh,'setup','selfRegistration');
  $dbh->do("ALTER TABLE setup ADD COLUMN requireRegistration bit(1) NOT NULL DEFAULT b'1'") unless column_exists($dbh,'setup','requireRegistration');
 }
 $dbh->do(q{CREATE TABLE IF NOT EXISTS hotspotSubscriber (
  subscriberId int(10) unsigned NOT NULL AUTO_INCREMENT, username varchar(100) NOT NULL, password varchar(255) NOT NULL,
  name varchar(100) DEFAULT NULL,email varchar(150) DEFAULT NULL,phone varchar(100) DEFAULT NULL,
  createdTime timestamp NOT NULL DEFAULT current_timestamp(),confirmedTime timestamp NULL DEFAULT NULL,lastLogin timestamp NULL DEFAULT NULL,
  subscriptionType enum('quota','expiry','limited') NOT NULL DEFAULT 'expiry',expiryTime timestamp NULL DEFAULT NULL,
  giveHoursAfterLogin smallint(5) unsigned DEFAULT NULL,quotaMB int(10) unsigned NOT NULL DEFAULT 0,usageMB double NOT NULL DEFAULT 0,
  campaignId smallint(5) unsigned DEFAULT NULL,enabled bit(1) NOT NULL DEFAULT b'1',legacyRadcheckId int(11) unsigned DEFAULT NULL,
  PRIMARY KEY(subscriberId),UNIQUE KEY hotspotSubscriber_username(username),UNIQUE KEY hotspotSubscriber_legacyRadcheckId(legacyRadcheckId)
 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci});
 migrate_radcheck($dbh) if table_exists($dbh,'radcheck');
}
sub migrate_radcheck {
 my($dbh)=@_;
 my $sql=q{INSERT IGNORE INTO hotspotSubscriber
 (username,password,name,email,phone,createdTime,confirmedTime,lastLogin,subscriptionType,expiryTime,giveHoursAfterLogin,quotaMB,usageMB,campaignId,enabled,legacyRadcheckId)
 SELECT username,COALESCE(value,''),NULLIF(name,''),NULLIF(email,''),NULLIF(phone,''),createdTime,
 CASE WHEN op='==' AND COALESCE(attribute,'')='' THEN COALESCE(confirmedTime,createdTime) ELSE confirmedTime END,
 last_login,subscriptionType,expirytime,giveHoursAfterLogin,GREATEST(COALESCE(mbquota,0),0),GREATEST(COALESCE(mbusage,0),0),campaignid,b'1',id
 FROM radcheck WHERE (op=':=' AND attribute='Cleartext-Password') OR (op='==' AND COALESCE(attribute,'')='')};
 eval{$dbh->do($sql)}; warn "Legacy radcheck migration skipped: $@" if $@ && $ENV{TARASEC_USERS_DEBUG};
}
sub show_admins {
 my($dbh)=@_; print "Back-office administrators\n--------------------------\n";
 my $s=$dbh->prepare('SELECT username,suspendedUntil,lastLogin FROM user WHERE CAST(isAdmin AS UNSIGNED)=1 ORDER BY username'); $s->execute;
 my $n=0; while(my $r=$s->fetchrow_hashref){$n++; my $st=$r->{suspendedUntil}?"suspended until $r->{suspendedUntil}":'enabled'; my $last=$r->{lastLogin}//'never'; printf "  %-24s %-28s last login: %s\n",$r->{username},$st,$last;}
 print "  (none configured; use --set-admin-password to create the first administrator)\n" unless $n;
}
sub show_hotspot_users {
 my($dbh)=@_; print "Valid hotspot subscriber logins\n-------------------------------\n";
 my $s=$dbh->prepare(q{SELECT username,password,subscriptionType,expiryTime,giveHoursAfterLogin,quotaMB,COALESCE(usageMB,0) usageMB,confirmedTime FROM hotspotSubscriber WHERE CAST(enabled AS UNSIGNED)=1 ORDER BY username}); $s->execute;
 my $n=0; while(my $r=$s->fetchrow_hashref){ next unless valid_subscriber($r); $n++; printf "  %-18s password: %-18s %s\n",$r->{username},$r->{password}//'',describe_validity($r); }
 print "  (no currently valid hotspot subscriber logins)\n" unless $n;
}
sub valid_subscriber {
 my($r)=@_; return 0 unless $r->{confirmedTime}; my $t=$r->{subscriptionType}//'';
 my $q=(0+($r->{quotaMB}//0))>(0+($r->{usageMB}//0));
 my $e=$r->{expiryTime}?($r->{expiryTime} ge sql_now()):((0+($r->{giveHoursAfterLogin}//0))>0);
 return $t eq 'quota'?$q:$t eq 'expiry'?$e:$t eq 'limited'?($q&&$e):0;
}
sub describe_validity {
 my($r)=@_; my @p; my $t=$r->{subscriptionType}//'';
 if($t eq 'expiry'||$t eq 'limited'){push @p,$r->{expiryTime}?"expires $r->{expiryTime}":(0+($r->{giveHoursAfterLogin}//0)).' hour(s) from first login';}
 if($t eq 'quota'||$t eq 'limited'){my $left=(0+($r->{quotaMB}//0))-(0+($r->{usageMB}//0));$left=0 if $left<0;push @p,sprintf('%.0f MB remaining',$left);}
 return @p?'('.join(', ',@p).')':'';
}
sub set_admin_password {
 my($dbh,$requested)=@_; my $s=$dbh->prepare('SELECT username FROM user WHERE CAST(isAdmin AS UNSIGNED)=1 ORDER BY username');$s->execute;my @a;while(my($u)=$s->fetchrow_array){push @a,$u;}
 my $u=$requested//'';
 if(!@a){ if(!$u){print "No administrator exists yet. Username for new administrator: ";chomp($u=<STDIN>//'');} die "Administrator username cannot be empty.\n" unless length $u; }
 elsif(!$u){$u=@a==1?$a[0]:choose_admin(\@a);}
 my $p1=read_hidden('New password: ');my $p2=read_hidden('Repeat password: ');die "Passwords did not match.\n" if $p1 ne $p2;die "Password must be at least 10 characters.\n" if length($p1)<10;
 if(!@a){my $i=$dbh->prepare("INSERT INTO user(username,password,isAdmin,verified) VALUES(?,?,b'1',b'1')");$i->execute($u,$p1);print "Administrator '$u' created.\n";return;}
 my %a=map{$_=>1}@a;die "'$u' is not an administrator account.\n" unless $a{$u};
 my $q=$dbh->prepare("UPDATE user SET password=?,loginFailsSinceSuccess=0,loginFailReportedTime=NULL,suspendedUntil=NULL WHERE username=? AND CAST(isAdmin AS UNSIGNED)=1");$q->execute($p1,$u);print "Administrator password updated for '$u'.\n";
}
sub choose_admin {my($a)=@_;print "Administrators:\n";for my $i(0..$#$a){printf "  %d) %s\n",$i+1,$a->[$i];}print "Select administrator: ";chomp(my $x=<STDIN>//'');die "Invalid selection.\n" if $x!~/^\d+$/||$x<1||$x>@$a;return $a->[$x-1];}
sub read_hidden {my($p)=@_;print $p;system('stty','-echo')==0 or die "Unable to disable terminal echo.\n";my $v=<STDIN>;system('stty','echo');print "\n";defined $v or die "No password entered.\n";chomp $v;return $v;}
sub sql_now {my @t=localtime();return sprintf('%04d-%02d-%02d %02d:%02d:%02d',$t[5]+1900,$t[4]+1,$t[3],$t[2],$t[1],$t[0]);}
sub usage {my($e)=@_;print "Usage:\n  sudo tarasec-users\n  sudo tarasec-users --set-admin-password [USERNAME]\n\nThe first --set-admin-password creates the initial back-office administrator.\n";exit $e;}
