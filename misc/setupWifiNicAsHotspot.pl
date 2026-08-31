#!/usr/bin/perl
use strict;
use warnings;
use FindBin qw($Bin);

# Configure a Wi-Fi interface as a TaraSec captive-portal hotspot without
# disturbing the currently active Internet uplink. NetworkManager owns
# AP/DHCP/DNS/NAT; misc/install_opennds.sh owns all captive-portal integration.

my $wifi_if = shift @ARGV // '';
my $requested_ssid = shift @ARGV // '';
my $addr = shift @ARGV // '192.168.50.1/24';

sub sh { my ($cmd)=@_; print "+ $cmd\n"; system($cmd)==0 or die "Command failed: $cmd\n"; }
sub valid_short_name { my ($name)=@_; return $name =~ /^[A-Za-z0-9][A-Za-z0-9_-]{0,15}$/; }
sub wait_for_nm_lease_file { my ($ifname)=@_; my $leases="/var/lib/NetworkManager/dnsmasq-$ifname.leases"; for (1..20) { return $leases if -e $leases; sleep 1; } die "NetworkManager hotspot is up, but expected DHCP lease file '$leases' was not created.\n"; }
sub slurp_file { my ($path)=@_; return '' unless -f $path; open my $fh,'<',$path or die "Cannot read $path: $!\n"; local $/; my $text=<$fh>//''; close $fh; return $text; }
sub networkmanager_manages { my ($ifname)=@_; my $managed=`nmcli -g GENERAL.NM-MANAGED device show '$ifname' 2>/dev/null`; chomp $managed; return lc($managed) eq 'yes'; }
sub remove_interface_from_auto_line { my ($line,$ifname)=@_; return $line unless $line =~ /^(\s*)(auto|allow-hotplug)(\s+)(.*?)(\r?\n)?$/; my ($indent,$kind,$space,$list,$nl)=($1,$2,$3,$4,$5//''); my @ifs=grep { $_ ne $ifname } split /\s+/,$list; return '' unless @ifs; return $indent.$kind.$space.join(' ',@ifs).$nl; }
sub remove_static_interface_stanza { my ($text,$ifname)=@_; my @lines=split /(?<=\n)/,$text; my @out; my $removed=0; for(my $i=0;$i<@lines;$i++){ my $line=$lines[$i]; if($line =~ /^\s*(?:auto|allow-hotplug)\s+/){ my $new=remove_interface_from_auto_line($line,$ifname); $removed=1 if $new ne $line; push @out,$new if $new ne ''; next; } if($line =~ /^\s*iface\s+\Q$ifname\E\s+inet\s+static\s*(?:#.*)?(?:\r?\n)?$/){ $removed=1; while($i+1<@lines){ my $next=$lines[$i+1]; last if $next =~ /^\S/ && $next !~ /^#/; $i++; } next; } push @out,$line; } return (join('',@out),$removed); }

sub ensure_nm_ifupdown_managed {
    my $nmconf='/etc/NetworkManager/NetworkManager.conf'; return unless -f $nmconf;
    my $text=slurp_file($nmconf); return unless $text =~ /^\s*\[ifupdown\]\s*$/mi; return if $text =~ /^\s*managed\s*=\s*true\s*$/mi;
    my $backup="$nmconf.tarasec-before"; if(!-f $backup){ open my $bfh,'>',$backup or die "Cannot create backup $backup: $!\n"; print {$bfh} $text; close $bfh; }
    if($text =~ /^\s*managed\s*=\s*false\s*$/mi){ $text =~ s/^(\s*managed\s*=\s*)false\s*$/${1}true/mi; } else { $text =~ s/(^\s*\[ifupdown\]\s*$)/$1\nmanaged=true/mi; }
    open my $fh,'>',$nmconf or die "Cannot update $nmconf: $!\n"; print {$fh} $text; close $fh; print "Updated NetworkManager ifupdown policy to managed=true (backup: $backup).\n";
}

sub ensure_wpa_supplicant {
    my $load = `systemctl show -p LoadState --value wpa_supplicant.service 2>/dev/null`;
    chomp $load;
    if ($load eq 'masked') {
        print "wpa_supplicant.service is masked; unmasking it for NetworkManager Wi-Fi support.\n";
        sh('systemctl unmask wpa_supplicant.service');
        system('systemctl daemon-reload >/dev/null 2>&1');
    }
    if (system('systemctl start wpa_supplicant.service >/dev/null 2>&1') != 0) {
        print "wpa_supplicant service is unavailable; installing/reinstalling it for NetworkManager Wi-Fi support.\n";
        sh('apt-get update');
        sh('DEBIAN_FRONTEND=noninteractive apt-get install --reinstall -y wpasupplicant');
        sh('systemctl unmask wpa_supplicant.service');
        system('systemctl daemon-reload >/dev/null 2>&1');
        sh('systemctl enable wpa_supplicant.service');
        sh('systemctl restart wpa_supplicant.service');
    } else {
        system('systemctl enable wpa_supplicant.service >/dev/null 2>&1');
    }
    die "wpa_supplicant is not active; NetworkManager cannot initialize Wi-Fi.\n" unless system('systemctl is-active --quiet wpa_supplicant.service') == 0;
}

sub ensure_wifi_device_ready {
    my ($ifname)=@_;
    for (1..5) {
        my $state=`nmcli -g GENERAL.STATE device show '$ifname' 2>/dev/null`; chomp $state;
        return if $state !~ /^20\b/;
        sleep 1;
    }
    print "$ifname is still unavailable after wpa_supplicant recovery; restarting NetworkManager so it can reacquire the supplicant interface.\n";
    sh('systemctl restart NetworkManager');
    sleep 3;
    sh("nmcli device set '$ifname' managed yes");
    system('nmcli radio wifi on >/dev/null 2>&1');
    for (1..20) {
        my $state=`nmcli -g GENERAL.STATE device show '$ifname' 2>/dev/null`; chomp $state;
        return if $state !~ /^20\b/;
        sleep 1;
    }
    die "$ifname remains unavailable even though wpa_supplicant is active. Check NetworkManager journal for Wi-Fi backend errors.\n";
}

sub interrupted_tarasec_migration_detected { my ($ifname)=@_; my @if_backups=glob('/etc/network/interfaces.tarasec-legacy-*'); my @hostapd_backups=glob('/etc/hostapd/hostapd.conf.tarasec-legacy-*'); return 0 unless @if_backups && @hostapd_backups; my $current=slurp_file('/etc/network/interfaces'); return 0 if $current =~ /^\s*iface\s+\Q$ifname\E\s+inet\s+static\s*$/m; for my $h(@hostapd_backups){ my $text=slurp_file($h); next unless $text =~ /^\s*interface\s*=\s*\Q$ifname\E\s*$/m; next unless $text =~ /^\s*ssid\s*=\s*TaraSec(?:[_-].*)?\s*$/mi; return 1; } return 0; }

sub finish_nm_handoff {
    my ($ifname)=@_; ensure_nm_ifupdown_managed(); ensure_wpa_supplicant(); system('systemctl daemon-reload >/dev/null 2>&1'); system("ip addr flush dev '$ifname' >/dev/null 2>&1"); system("ip link set '$ifname' down >/dev/null 2>&1"); system('systemctl restart NetworkManager >/dev/null 2>&1'); sleep 3; sh("nmcli device set '$ifname' managed yes"); system('nmcli general reload >/dev/null 2>&1'); sleep 3;
    die "TaraSec hotspot migration reached NetworkManager handoff, but NetworkManager still does not manage $ifname.\n" unless networkmanager_manages($ifname);
}

sub migrate_legacy_tarasec_hotspot {
    my ($ifname)=@_; return if networkmanager_manages($ifname);
    print "\n$ifname is not currently managed by NetworkManager.\nChecking for a legacy TaraSec hostapd/ifupdown hotspot...\n";
    if(interrupted_tarasec_migration_detected($ifname)){ print "Interrupted TaraSec hotspot migration detected; resuming NetworkManager handoff.\n"; finish_nm_handoff($ifname); print "Interrupted TaraSec hotspot migration completed.\n"; return; }
    my $hostapd_path='/etc/hostapd/hostapd.conf'; my $interfaces_path='/etc/network/interfaces'; my $hostapd=slurp_file($hostapd_path); my $interfaces=slurp_file($interfaces_path);
    my $hostapd_is_tarasec=$hostapd =~ /^\s*interface\s*=\s*\Q$ifname\E\s*$/m && $hostapd =~ /^\s*ssid\s*=\s*TaraSec(?:[_-].*)?\s*$/mi;
    my $static_is_tarasec=$interfaces =~ /^\s*iface\s+\Q$ifname\E\s+inet\s+static\s*$/m && $interfaces =~ /^\s*address\s+192\.168\.50\.1(?:\/24)?\s*$/m;
    die "$ifname is unmanaged, but the installer cannot prove that the existing networking is a legacy TaraSec hotspot. Refusing to modify it.\n" unless $hostapd_is_tarasec && $static_is_tarasec;
    print "Legacy TaraSec hotspot detected on $ifname; migrating it to NetworkManager.\n";
    my $stamp=time(); my $interfaces_backup="$interfaces_path.tarasec-legacy-$stamp"; open my $bfh,'>',$interfaces_backup or die "Cannot create backup $interfaces_backup: $!\n"; print {$bfh} $interfaces; close $bfh;
    my ($new_interfaces,$removed)=remove_static_interface_stanza($interfaces,$ifname); die "Legacy TaraSec interface stanza was detected but could not be removed safely.\n" unless $removed;
    open my $ifh,'>',$interfaces_path or die "Cannot update $interfaces_path: $!\n"; print {$ifh} $new_interfaces; close $ifh;
    system('systemctl stop hostapd >/dev/null 2>&1'); system('systemctl disable hostapd >/dev/null 2>&1'); unlink '/etc/systemd/system/hostapd.service.d/10-tarasec-boot.conf' if -f '/etc/systemd/system/hostapd.service.d/10-tarasec-boot.conf';
    if(-f $hostapd_path){ my $backup="$hostapd_path.tarasec-legacy-$stamp"; rename $hostapd_path,$backup or die "Cannot archive legacy TaraSec hostapd config to $backup: $!\n"; }
    unlink '/etc/dnsmasq.d/tarasec-hotspot.conf' if -f '/etc/dnsmasq.d/tarasec-hotspot.conf'; finish_nm_handoff($ifname); print "Legacy TaraSec hotspot migration complete. Backup: $interfaces_backup\n";
}

if($> != 0){ die "Run as root, for example: sudo perl misc/setupWifiNicAsHotspot.pl wlp5s0\n"; }
if(!$wifi_if){ chomp($wifi_if=`nmcli -t -f DEVICE,TYPE device status | awk -F: '\$2=="wifi" {print \$1; exit}'`); }
die "No Wi-Fi interface found. Specify it as the first argument.\n" unless $wifi_if;
die "NetworkManager is not running.\n" unless system('systemctl is-active --quiet NetworkManager') == 0;
chomp(my $wan_if=`ip -4 route show default | awk 'NR==1 {print \$5}'`); die "No IPv4 default-route interface found. Keep an Internet uplink connected before enabling the hotspot.\n" unless $wan_if; die "Refusing to convert $wifi_if to hotspot because it is the active Internet uplink. Connect another uplink first.\n" if $wan_if eq $wifi_if;
my $iw=`iw list 2>/dev/null`; die "Wi-Fi hardware does not advertise AP mode support.\n" unless $iw =~ /Supported interface modes:.*?\*\s+AP\b/s;
system('rfkill unblock wifi >/dev/null 2>&1'); system('nmcli radio wifi on >/dev/null 2>&1'); sleep 1;

print "\nScanning nearby Wi-Fi networks on $wifi_if...\n"; my @nearby; my $scan=`nmcli -t -f SSID device wifi list ifname '$wifi_if' --rescan yes 2>/dev/null`; for my $s(split /\n/,$scan){ $s =~ s/\\:/:/g; next if $s eq ''; push @nearby,$s unless grep { $_ eq $s } @nearby; last if @nearby>=8; }
print "\nChoose the Wi-Fi name for this TaraSec hotspot.\nPeople nearby will see it when they open the Wi-Fi list on their phone.\n"; if(@nearby){ print "\nNearby Wi-Fi names currently include:\n"; print "    $_\n" for @nearby; }
chomp(my $hostname=`hostname -s 2>/dev/null`); $hostname||='hotspot'; $hostname =~ s/[^A-Za-z0-9_-]//g; $hostname=substr($hostname,0,16); $hostname='hotspot' unless valid_short_name($hostname);
my $ssid; if($requested_ssid ne ''){ my $short=$requested_ssid; $short =~ s/^TaraSec_//; die "Invalid hotspot name '$short'. Use 1-16 characters: letters, digits, '-' or '_'.\n" unless valid_short_name($short); $ssid="TaraSec_$short"; } else { while(1){ print "\nHotspot name [$hostname]: "; my $short=<STDIN>; defined $short or die "No hotspot name received.\n"; chomp $short; $short=$hostname if $short eq ''; if(!valid_short_name($short)){ print "Please use 1-16 characters: letters, digits, '-' or '_'. No spaces.\n"; next; } my $candidate="TaraSec_$short"; print "\nNote: '$candidate' is already visible nearby. Duplicate Wi-Fi names are allowed, but may be confusing locally.\n" if grep { lc($_) eq lc($candidate) } @nearby; print "\nYour hotspot will appear in people's Wi-Fi list as:\n\n        $candidate\n\nIs this what you want? [Y/n]: "; my $answer=<STDIN>; defined $answer or die "No confirmation received.\n"; chomp $answer; if($answer eq '' || $answer =~ /^y(?:es)?$/i){ $ssid=$candidate; last; } } }

migrate_legacy_tarasec_hotspot($wifi_if);
ensure_wpa_supplicant();
ensure_wifi_device_ready($wifi_if);
system('systemctl stop opennds >/dev/null 2>&1'); unlink '/etc/dnsmasq.d/tarasec-hotspot.conf' if -f '/etc/dnsmasq.d/tarasec-hotspot.conf';
my $profile="tarasec-hotspot-$wifi_if"; system("nmcli connection down '$profile' >/dev/null 2>&1"); system("nmcli connection delete '$profile' >/dev/null 2>&1"); sh("nmcli connection add type wifi ifname '$wifi_if' con-name '$profile' autoconnect yes ssid '$ssid'"); sh("nmcli connection modify '$profile' 802-11-wireless.mode ap ipv4.method shared ipv4.addresses '$addr' ipv6.method disabled connection.autoconnect-priority 100");
sh("nmcli connection up '$profile'");
my $leases=wait_for_nm_lease_file($wifi_if); print "NetworkManager DHCP lease file: $leases\n";
my $users_helper="$Bin/tarasec-users.pl"; die "Missing TaraSec account helper: $users_helper\n" unless -f $users_helper; install_users_helper($users_helper); sh('/usr/local/sbin/tarasec-users --ensure-schema');
sh(q{mysql taransvar -e "DELETE FROM access;"}); print "Cleared previous captive-portal access authorizations.\n";
my $helper="$Bin/install_opennds.sh"; die "Missing TaraSec openNDS installer: $helper\n" unless -f $helper; $ENV{TARASEC_HOTSPOT_IF}=$wifi_if; $ENV{TARASEC_HOTSPOT_ADDR}=$addr; $ENV{TARASEC_HOTSPOT_NAME}=$ssid; sh("bash '$helper'");
my $watch_helper="$Bin/install_wifi_session_watch.sh"; die "Missing TaraSec Wi-Fi session watcher installer: $watch_helper\n" unless -f $watch_helper; sh("bash '$watch_helper'");
my $status=`ndsctl status 2>&1`; die "TaraSec hotspot NOT complete: ndsctl status failed after portal installation.\n$status\n" if $? != 0; die "TaraSec hotspot NOT complete: custom ThemeSpec is not configured.\n" unless system("grep -q \"option themespec_path '/usr/lib/opennds/theme_tarasec.sh'\" /etc/config/opennds") == 0; die "TaraSec hotspot NOT complete: Apache captive login is not listening on 8080.\n" unless system("ss -lnt | grep -q ':8080 '") == 0; die "TaraSec hotspot NOT complete: Wi-Fi session watcher is not active.\n" unless system("systemctl is-active --quiet tarasec-wifi-session-watch.service") == 0;
print "\nTaraSec hotspot interface configured and custom captive portal enforced.\nWAN:     $wan_if (left unchanged)\nHotspot: $wifi_if\nSSID:    $ssid\nAddress: $addr\nDHCP:    NetworkManager shared mode\nLeases:  $leases\nProfile: $profile\nPortal:  TaraSec ThemeSpec active on openNDS\nSessions: disconnect watcher active\n";
sub install_users_helper { my ($src)=@_; sh("install -m 0755 '$src' /usr/local/sbin/tarasec-users"); }
