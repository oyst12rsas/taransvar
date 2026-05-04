#!/usr/bin/perl
use strict;
use warnings;
use Getopt::Long qw(GetOptions);

# VPN_router_diagnose.pl
# Split: TEST_TARGETS are routed site peers to test outward.
#        VPN_CLIENT_POOLS are NATed access clients hidden behind VPS SNAT.
# Default asks for mode. Use -diagnose to report only, or -fix to apply safe fixes.
# Fix mode enforces firewall/NAT/routes/XFRM/overlay /32 sanity in one pass.
# v17 adds local host-firewall checks for routed site peers (ICMP + outbound TCP tests).

our $NODE_NAME     = "UNCONFIGURED";
our $OVERLAY_LOCAL = "";
our $CONF_GLOB     = "/etc/swanctl/conf.d/*.conf";

# Local machine-specific configuration.
# Preferred workflow:
#   1. Keep vpn_router_diagnose.local.template.pl in the GitHub/repo directory.
#   2. Copy it once to ~/vpn_router_diagnose.local.pl and edit the home copy.
#   3. The home copy overrides script defaults and survives future downloads/git pulls.
#
# Load order:
#   1. ~/vpn_router_diagnose.local.pl              (user-owned override)
#   2. ./vpn_router_diagnose.local.template.pl    (repo template fallback/documentation)
#   3. safe internal defaults                     (no site-specific checks)
our $HOME_LOCAL     = user_home() . "/vpn_router_diagnose.local.pl";
our $CWD_LOCAL      = "./vpn_router_diagnose.local.pl";
our $REPO_TEMPLATE  = "./vpn_router_diagnose.local.template.pl";
our $LOCAL_CONFIG   = undef;
our $LOCAL_CONFIG_KIND = "safe internal defaults";
our $LOCAL_CONFIG_VERSION = 1;
our $XFRM_IF      = "";
our $XFRM_IF_ID   = undef;
our @XFRM_ROUTES  = ();
our @LOCAL_LAN_ROUTES = ();

our %TEST_TARGETS = ();

our %ROUTED_SITE_LANS = ();

our %VPN_CLIENT_POOLS = ();

our @MTU_TUNE_IFACES = ();
our $SAFE_MTU = 1400;
our $MTU_PING_SIZE = 1360;

my ($SHOW_CONF, $PRINT_LOCAL_TEMPLATE, $HELP) = (0, 0, 0);
my $MODE = q{};       # diagnose | fix
my $FIX  = 0;
my $INTERACTIVE_MODE = 0;
my @FINDINGS;
my %COUNTS = ( OK => 0, WARN => 0, ERROR => 0, FIX => 0, MISSING => 0 );
GetOptions(
    q{fix|f}      => sub { die "Choose only one mode: -fix or -diagnose\n" if $MODE && $MODE ne q{fix}; $MODE = q{fix}; },
    q{diagnose|d} => sub { die "Choose only one mode: -fix or -diagnose\n" if $MODE && $MODE ne q{diagnose}; $MODE = q{diagnose}; },
    q{show-conf}  => \$SHOW_CONF,
    q{print-local-template} => \$PRINT_LOCAL_TEMPLATE,
    q{help|h}     => \$HELP,
) or die "Invalid option. Use --help\n";

if ($HELP) { print_usage(); exit 0; }
if ($PRINT_LOCAL_TEMPLATE) { print_local_template(); exit 0; }

if (!$MODE) {
    $INTERACTIVE_MODE = 1;
    print "\nSelect mode:\n";
    print "1) Diagnose only\n";
    print "2) Diagnose + fix\n";
    while (!$MODE) {
        print "Choice [1/2]: ";
        chomp(my $choice = <STDIN>);
        if ($choice eq q{1}) { $MODE = q{diagnose}; }
        elsif ($choice eq q{2}) { $MODE = q{fix}; }
        else { print "Invalid choice. Please enter 1 or 2.\n"; }
    }
}
$FIX = ($MODE eq q{fix}) ? 1 : 0;
if ($FIX && $> != 0) { die "ERROR: -fix requires root (sudo)\n"; }

print_banner();
($LOCAL_CONFIG, $LOCAL_CONFIG_KIND) = load_config_with_template($HOME_LOCAL, $CWD_LOCAL, $REPO_TEMPLATE);
my %configured = parse_swanctl_conf($CONF_GLOB);
my ($sas_text, %active) = get_active_sas();

maybe_prompt_for_ports($INTERACTIVE_MODE || ($FIX && -t STDIN));

section("Node");
print "Node name       : $NODE_NAME\n";
print "Overlay local   : $OVERLAY_LOCAL\n";
print "Config glob     : $CONF_GLOB\n";
print "Mode            : " . uc($MODE) . ($FIX ? " (apply safe fixes)" : " (dry-run/no changes)") . "\n";
if (defined $LOCAL_CONFIG) {
    print "Local config    : $LOCAL_CONFIG ($LOCAL_CONFIG_KIND loaded)\n";
} else {
    print "Local config    : not found; using safe internal defaults only\n";
    print "                  No site-specific checks will run without local/template config.\n";
    print "                  First-time setup:\n";
    print "                  perl VPN_router_diagnose.pl --print-local-template > vpn_router_diagnose.local.template.pl\n";
    print "                  cp ./vpn_router_diagnose.local.template.pl ~/vpn_router_diagnose.local.pl\n";
    print "                  nano ~/vpn_router_diagnose.local.pl\n";
    finding("WARN", "No local config or repo template found; using safe minimal defaults", "Create a visible local config: cp ./vpn_router_diagnose.local.template.pl ~/vpn_router_diagnose.local.pl, then enable the section for this machine.");
}
print "XFRM interface  : " . ($XFRM_IF ? $XFRM_IF . (defined($XFRM_IF_ID) ? " if_id=$XFRM_IF_ID" : "") : "not configured") . "\n";

section("Interfaces"); run("ip -br a");
section("Routes"); run("ip route"); run("ip rule"); run("ip route show table 220 2>/dev/null || true");

section("XFRM interface and local route checks");
check_or_fix_xfrm_interface($FIX);
check_or_fix_loopback_sanity($FIX);
check_or_fix_overlay_local_address($FIX);
check_or_fix_xfrm_routes($FIX);
check_or_fix_local_lan_routes($FIX);

section("MTU and offload checks");
check_or_fix_mtu_offload($FIX);

section("strongSwan active SAs"); print $sas_text || "(no output)\n";
if (!$sas_text || $sas_text !~ /ESTABLISHED/) {
    finding("ERROR", "No ESTABLISHED strongSwan SA found", no_sa_advice(\%configured));
}

section("Configured peers summary");
for my $name (sort keys %configured) {
    my $cfg = $configured{$name};
    my $status = exists $active{$name} ? "ACTIVE" : "INACTIVE";
    my $role = exists $TEST_TARGETS{$name} ? "sitePeer/testTarget" : "configured";
    print "\n[$status] $name ($role)\n";
    print "  file         : " . ($cfg->{file} // '') . "\n";
    print "  peer_id      : " . ($cfg->{peer_id} // '') . "\n";
    print "  child        : " . ($cfg->{child} // '') . "\n";
    print "  local_ts     : " . ($cfg->{local_ts} // '') . "\n";
    print "  remote_ts    : " . ($cfg->{remote_ts} // '') . "\n";
    print "  if_id_in     : " . ($cfg->{if_id_in} // '') . "\n";
    print "  if_id_out    : " . ($cfg->{if_id_out} // '') . "\n";
    print "  pools        : " . ($cfg->{pools} // '') . "\n";
    print "  remote_ip    : " . ($cfg->{remote_addrs} // '') . "\n";
    print "  test_ip      : " . (exists($TEST_TARGETS{$name}) ? get_test_ip($name, $cfg) : 'not a TEST_TARGET') . "\n";
    if (exists($TEST_TARGETS{$name}) && !exists($active{$name})) {
        finding("WARN", "Configured site peer $name is not active", inactive_peer_advice($name, $cfg));
    }
}

section("Unexpected active SAs");
my $unexpected = 0;
for my $name (sort keys %active) {
    next if exists $configured{$name};
    $unexpected = 1;
    print "[WARN] Active SA not found in config parse: $name\n";
    finding("WARN", "Active SA $name was not found in parsed config", "Check parser/config naming, or inspect /etc/swanctl/conf.d/*.conf manually.");
}
print "None\n" unless $unexpected;

section("Detailed site peer checks");
for my $name (sort keys %TEST_TARGETS) {
    my $cfg    = $configured{$name};
    my $sa     = $active{$name};
    my $testip = $cfg ? get_test_ip($name, $cfg) : $TEST_TARGETS{$name}{test_ip};
    section("Site peer: $name");
    print "Configured : " . ($cfg ? "yes" : "no") . "\n";
    print "Active     : " . ($sa  ? "yes" : "no") . "\n";
    print "test_ip    : " . ($testip // 'UNKNOWN') . "\n";
    print_sa_interpretation($sa) if $sa;
    if ($testip) {
        check_or_fix_site_peer_host_firewall(
                $name, 
                $testip, 
                $TEST_TARGETS{$name}{ports} || [], 
                \@ALLOW_FROM_VPS_TCP,
                $FIX);
        print "\n-- Route checks --\n";
        run("ip route get $testip");
        run("ip route get $testip from $OVERLAY_LOCAL");
        print "\n-- Overlay ping --\n";
        my $ping_ok = run("ping -I $OVERLAY_LOCAL -c 2 $testip");
        if ($ping_ok) { finding("OK", "Overlay ping to $name ($testip) works from $OVERLAY_LOCAL", undef); }
        else { finding("ERROR", "Overlay ping to $name ($testip) failed from $OVERLAY_LOCAL", "Check SA, xfrm policy/state, ipsec0 routes, and peer firewall."); }
        my $ports = $TEST_TARGETS{$name}{ports} || [];
        if (@$ports) {
            print "\n-- TCP port checks --\n";
            for my $port (@$ports) {
                my $ok = run("nc -vz -w 2 $testip $port");
                finding($ok ? "OK" : "WARN", "TCP $testip:$port " . ($ok ? "is reachable" : "is not reachable"), $ok ? undef : "If this service should be open, check listener and firewall on $testip.");
            }
        }
    }
}

section("Routed site LAN return-route checks");
for my $name (sort keys %ROUTED_SITE_LANS) {
    check_or_fix_routed_site_lan($name, $ROUTED_SITE_LANS{$name}, $FIX);
}

section("VPN client pool checks");
my @clients = active_vpn_clients(\%active);
if (!@clients) {
    print "No active roadwarrior/client virtual IPs detected in swanctl output.\n";
} else {
    for my $c (@clients) {
        print "\nActive client SA: $c->{conn}\n";
        print "  virtual_ip : $c->{vip}\n";
        print "  underlay   : " . ($c->{underlay} // 'UNKNOWN') . "\n";
        print "  pool       : " . ($c->{pool_name} // 'UNKNOWN/NO MATCH') . "\n";
        print "  local_ts   : " . ($c->{local_ts} // '') . "\n";
        print "  remote_ts  : " . ($c->{remote_ts} // '') . "\n";
        if ($c->{pool}) {
            check_or_fix_client_pool($c->{pool}, $FIX);
        } else {
            print "  [WARN] Client virtual IP does not match any VPN_CLIENT_POOLS client_net.\n";
            finding("WARN", "Client virtual IP $c->{vip} does not match configured VPN_CLIENT_POOLS", "Add/adjust a pool entry or move clients to the expected pool.");
        }
    }
}

section("iptables INPUT/FORWARD/NAT snapshot");
run("sudo iptables -L INPUT -n -v --line-numbers");
run("sudo iptables -L FORWARD -n -v --line-numbers");
run("sudo iptables -t nat -L POSTROUTING -n -v --line-numbers");

section("XFRM");
run("sudo ip xfrm policy");
run("sudo ip xfrm state");

if ($SHOW_CONF) { section("Suggested swanctl config"); print_suggested_swanctl_conf(); }

print_final_diagnosis();
exit(($COUNTS{ERROR} || $COUNTS{MISSING}) ? 2 : ($COUNTS{WARN} ? 1 : 0));



sub user_home {
    return $ENV{SUDO_USER} ? (getpwnam($ENV{SUDO_USER}))[7] || $ENV{HOME} : $ENV{HOME};
}

sub load_config_with_template {
    my ($home_local, $cwd_local, $repo_template) = @_;
    my @candidates = (
        [$home_local,    "home local config"],
        [$cwd_local,     "current-directory local config"],
        [$repo_template, "repo template fallback"],
    );
    for my $pair (@candidates) {
        my ($file, $kind) = @$pair;
        next unless defined $file && -f $file;
        print "[INFO] Loading $kind: $file\n";
        my $loaded = load_config_file($file);
        if ($loaded) {
            if ($kind ne "repo template fallback" && defined $repo_template && -f $repo_template) {
                compare_local_with_template($file, $repo_template);
            }
            if ($kind eq "repo template fallback") {
                print "[INFO] To customize this machine, copy the template to your home directory:\n";
                print "       cp $repo_template $home_local\n" if defined $home_local;
                print "       nano $home_local\n\n" if defined $home_local;
                finding("WARN", "Using repo template instead of user local config", "Copy it to ~/vpn_router_diagnose.local.pl and edit it. The home copy will not be overwritten by GitHub downloads.");
            }
            return ($file, $kind);
        }
        return ($file, "$kind failed");
    }
    return (undef, "internal defaults");
}

sub load_config_file
 {
    my ($file) = @_;
    my $rv = do $file;
    if (!defined $rv) {
        my $err = $@ || $! || "unknown error";
        finding("ERROR", "Failed to load local/template config $file", "Fix syntax/permissions in $file. Perl error: $err");
        return 0;
    }
    return 1;
}

sub compare_local_with_template {
    my ($home_local, $repo_template) = @_;
    return unless defined $home_local && defined $repo_template;
    return unless -f $home_local && -f $repo_template;

    my $diff = `diff -q '$home_local' '$repo_template' 2>/dev/null`;
    if ($diff) {
        print "[INFO] Local config differs from repo template (expected after customization).\n";
        print "       Review template updates with:\n";
        print "       diff -u $home_local $repo_template\n";
    } else {
        print "[INFO] Local config is identical to the repo template. Edit $home_local if this machine needs custom values.\n";
    }
}

sub print_local_template {
    print <<'EOF';
# vpn_router_diagnose.local.template.pl
# Copy to ~/vpn_router_diagnose.local.pl and edit for THIS machine:
#   cp ./vpn_router_diagnose.local.template.pl ~/vpn_router_diagnose.local.pl
#   nano ~/vpn_router_diagnose.local.pl
#
# IMPORTANT:
# - This template is intentionally safe: examples are commented out.
# - Do not leave VPS routed-LAN settings enabled on routervm, or vice versa.
# - Missing checks are better than wrong fixes.

$CONF_GLOB = "/etc/swanctl/conf.d/*.conf";

# -----------------------------
# RouterVM example: uncomment and adapt on routervm only
# -----------------------------
# $NODE_NAME     = "routervm";
# $OVERLAY_LOCAL = "10.47.1.1";
# $XFRM_IF       = "ipsec0";
# $XFRM_IF_ID    = 42;   # 0x2a
#
# %TEST_TARGETS = (
#     'VPS' => { test_ip => '10.47.0.1', ports => [80, 443] },
# );
#
# @XFRM_ROUTES = (
#     { dst => "10.47.0.0/24", dev => "ipsec0" },
# );
#
# @LOCAL_LAN_ROUTES = (
#     { dst => "192.168.50.0/24", dev => "wlx088af12de289", src => "192.168.50.1" },
# );
#
# # RouterVM normally NATs DHCP WiFi clients to 10.47.1.1 for VPN traffic,
# # so it should NOT define ROUTED_SITE_LANS for 192.168.50.0/24.
# %ROUTED_SITE_LANS = ();
#
# %VPN_CLIENT_POOLS = ();
# @MTU_TUNE_IFACES = (q{enp1s0});
# $SAFE_MTU = 1400;
# $MTU_PING_SIZE = 1360;

# -----------------------------
# VPS example: uncomment and adapt on VPS only
# -----------------------------
# $NODE_NAME     = "VPS";
# $OVERLAY_LOCAL = "10.47.0.1";
# $XFRM_IF       = "ipsec0";
# $XFRM_IF_ID    = 1;
#
# %TEST_TARGETS = (
#     'TORSAS_ROUTERVM' => { test_ip => '10.47.1.1', ports => [80, 443] },
#     'TORSAS_COWRIE'   => { test_ip => '10.47.0.2', ports => [2222] },
# );
#
# @XFRM_ROUTES = (
#     { dst => "10.47.1.0/24", dev => "ipsec0", src => $OVERLAY_LOCAL },
# );
#
# @LOCAL_LAN_ROUTES = ();
#
# # Enable this ONLY if you intentionally preserve real LAN client IPs
# # behind routervm. Leave empty if routervm NATs 192.168.50.0/24 to 10.47.1.1.
# %ROUTED_SITE_LANS = ();
# # Example routed mode only:
# # %ROUTED_SITE_LANS = (
# #   'TORSAS_ROUTERVM_WIFI' => {
# #       lan_net   => '192.168.50.0/24',
# #       via       => '10.47.1.1',
# #       peer_name => 'TORSAS_ROUTERVM',
# #       test_host => '192.168.50.101',
# #   },
# # );
#
# @MTU_TUNE_IFACES = (q{ens3});
# $SAFE_MTU = 1400;
# $MTU_PING_SIZE = 1360;
#
# %VPN_CLIENT_POOLS = (
#     'vpnClients' => {
#         client_net       => '192.168.250.0/24',
#         nat_to           => $OVERLAY_LOCAL,
#         allowed_dst      => '10.0.0.0/8',
#         local_service_ip => $OVERLAY_LOCAL,
#         service_ports    => [80, 443],
#     },
# );

1;
EOF
}

sub get_xfrm_interface_info {
    my ($dev) = @_;
    my $out = `ip -d link show $dev 2>&1`;
    return ($? == 0, $out);
}

sub infer_if_id_from_config {
    my %ids;
    for my $name (keys %configured) {
        my $cfg = $configured{$name};
        for my $k (qw(if_id_in if_id_out)) {
            next unless defined $cfg->{$k} && $cfg->{$k} ne '';
            $ids{$cfg->{$k}} = 1;
        }
    }
    my @ids = sort keys %ids;
    return @ids == 1 ? $ids[0] : undef;
}

sub xfrm_id_from_link_text {
    my ($txt) = @_;
    return undef unless defined $txt;
    return hex($1) if $txt =~ /xfrm\s+if_id\s+0x([0-9a-fA-F]+)/;
    return $1 if $txt =~ /xfrm\s+if_id\s+(\d+)/;
    return undef;
}

sub check_or_fix_xfrm_interface {
    my ($fix) = @_;
    if (!defined($XFRM_IF) || $XFRM_IF eq '') {
        print "XFRM interface check skipped: XFRM_IF is not configured in local config.\n";
        return 1;
    }
    my ($exists, $info) = get_xfrm_interface_info($XFRM_IF);
    print "\$ ip -d link show $XFRM_IF\n$info";
    my $want_id = defined($XFRM_IF_ID) ? $XFRM_IF_ID : infer_if_id_from_config();
    if (!$exists) {
        my $advice = defined($want_id)
            ? "Create it: sudo ip link add $XFRM_IF type xfrm if_id $want_id; sudo ip link set $XFRM_IF up. Persist it with systemd."
            : "Either remove if_id_in/out from swanctl config to use policy mode, or set XFRM_IF_ID in ~/vpn_router_diagnose.local.pl and create $XFRM_IF.";
        finding("ERROR", "XFRM interface $XFRM_IF is missing", $advice);
        print "[ERROR] XFRM interface $XFRM_IF is missing\nAdvice: $advice\n";
        if ($fix && defined($want_id)) {
            my $ok = run("sudo ip link add $XFRM_IF type xfrm if_id $want_id && sudo ip link set $XFRM_IF up");
            finding($ok ? "FIX" : "ERROR", ($ok ? "Created $XFRM_IF if_id $want_id" : "Failed creating $XFRM_IF"), $ok ? undef : $advice);
        }
        return 0;
    }
    my $actual_id = xfrm_id_from_link_text($info);
    if (!defined($actual_id)) {
        finding("ERROR", "$XFRM_IF exists but does not look like an XFRM interface", "Recreate it as: sudo ip link del $XFRM_IF; sudo ip link add $XFRM_IF type xfrm if_id <ID>; sudo ip link set $XFRM_IF up");
        return 0;
    }
    if (defined($want_id) && $actual_id != $want_id) {
        finding("ERROR", "$XFRM_IF if_id mismatch: interface=$actual_id expected=$want_id", "Recreate it: sudo ip link del $XFRM_IF; sudo ip link add $XFRM_IF type xfrm if_id $want_id; sudo ip link set $XFRM_IF up");
        return 0;
    }
    finding("OK", "$XFRM_IF exists with if_id $actual_id", undef);
    return 1;
}


sub addr_exists_exact {
    my ($ip, $prefix, $dev) = @_;
    return 0 unless $ip && defined $prefix;
    my $scope = defined($dev) && $dev ne '' ? "dev $dev" : "";
    my $out = `ip -o addr show $scope 2>/dev/null`;
    return ($out =~ /\binet\s+\Q$ip\E\/$prefix\b/) ? 1 : 0;
}

sub check_or_fix_loopback_sanity {
    my ($fix) = @_;
    my $out = `ip -o -4 addr show dev lo 2>&1`;
    print "\$ ip -o -4 addr show dev lo\n" . ($out || "(no IPv4 output)\n");
    my $ok_all = 1;
    while ($out =~ /\binet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)\b/g) {
        my ($ip, $prefix) = ($1, $2);
        next if $ip eq '127.0.0.1';
        next if $prefix == 32;
        $ok_all = 0;
        my $target_dev = ($XFRM_IF ne '') ? $XFRM_IF : 'lo';
        my $cmd = "sudo ip addr del $ip/$prefix dev lo && sudo ip addr add $ip/32 dev $target_dev";
        finding("ERROR", "Loopback has $ip/$prefix; this makes a whole overlay range local and breaks IPsec replies", "Run: $cmd");
        print "[ERROR] loopback $ip/$prefix is too broad; would fix to $ip/32 on $target_dev\n" unless $fix;
        if ($fix) {
            my $ok = run($cmd);
            finding($ok ? "FIX" : "ERROR", ($ok ? "Changed loopback $ip/$prefix to $ip/32 on $target_dev" : "Failed fixing broad loopback $ip/$prefix"), $ok ? undef : "Run manually: $cmd");
        }
    }
    finding("OK", "Loopback overlay addresses are /32 only", undef) if $ok_all;
    return $ok_all;
}

sub check_or_fix_overlay_local_address {
    my ($fix) = @_;
    return 1 unless defined($OVERLAY_LOCAL) && $OVERLAY_LOCAL ne '';
    my $dev = ($XFRM_IF ne '') ? $XFRM_IF : 'lo';
    my $out = `ip -o -4 addr show 2>/dev/null`;
    print "\$ ip -o -4 addr show | grep $OVERLAY_LOCAL\n";
    my @matches = grep { /\binet\s+\Q$OVERLAY_LOCAL\E\// } split /\n/, $out;
    print(@matches ? join("\n", @matches) . "\n" : "(not assigned)\n");

    if (addr_exists_exact($OVERLAY_LOCAL, 32, undef)) {
        finding("OK", "Overlay local $OVERLAY_LOCAL/32 is assigned", undef);
        return 1;
    }

    my @del_cmds;
    for my $line (@matches) {
        if ($line =~ /\b(\S+)\s+inet\s+\Q$OVERLAY_LOCAL\E\/(\d+)\b/) {
            my ($found_dev, $prefix) = ($1, $2);
            push @del_cmds, "sudo ip addr del $OVERLAY_LOCAL/$prefix dev $found_dev" if $prefix != 32;
        }
    }
    my $cmd = join(" && ", @del_cmds, "sudo ip addr add $OVERLAY_LOCAL/32 dev $dev");
    finding("MISSING", "Overlay local $OVERLAY_LOCAL/32 is not assigned", "Run: $cmd. This prevents kernel choosing a public source for IPsec replies.");
    print "[MISSING] would run: $cmd\n" unless $fix;
    if ($fix) {
        my $ok = run($cmd);
        finding($ok ? "FIX" : "ERROR", ($ok ? "Installed overlay local $OVERLAY_LOCAL/32 on $dev" : "Failed installing overlay local $OVERLAY_LOCAL/32"), $ok ? undef : "Run manually: $cmd");
        return $ok;
    }
    return 0;
}

sub table_220_is_active {
    my $rules = `ip rule 2>/dev/null`;
    return ($rules =~ /\blookup\s+220\b/) ? 1 : 0;
}

sub route_destination_uses_dev {
    my ($dst, $dev, $table, $src) = @_;
    return 0 unless defined($dst) && $dst ne '' && defined($dev) && $dev ne '';

    my $table_part = defined($table) && $table ne '' ? " table $table" : "";
    my $cmd = "ip route show$table_part exact $dst";
    my $out = `$cmd 2>&1`;
    print "\$ $cmd\n" . ($out ne '' ? $out : "(no matching route)\n");
    my $has_dev = ($out =~ /^\Q$dst\E\s+.*\bdev\s+\Q$dev\E\b/m) ? 1 : 0;
    my $has_src = (!defined($src) || $src eq '' || $out =~ /^\Q$dst\E\s+.*\bsrc\s+\Q$src\E\b/m) ? 1 : 0;
    return $has_dev && $has_src;
}

sub install_route_cmd {
    my ($dst, $dev, $table, $src) = @_;
    my $cmd = "sudo ip route replace $dst dev $dev";
    $cmd .= " src $src" if defined($src) && $src ne '';
    $cmd .= " table $table" if defined($table) && $table ne '';
    return $cmd;
}

sub check_one_xfrm_route {
    my ($dst, $dev, $table, $src, $fix) = @_;
    return 1 unless $dst && $dev;
    my $label = "Route to $dst uses $dev" . (defined($src) && $src ne '' ? " src $src" : "") . (defined($table) && $table ne '' ? " table $table" : "");
    if (route_destination_uses_dev($dst, $dev, $table, $src)) {
        finding("OK", $label, undef);
        return 1;
    }
    my $cmd = install_route_cmd($dst, $dev, $table, $src);
    finding("MISSING", $label, "Run: $cmd. This prevents overlay traffic from escaping via the underlay NIC or using a public source IP.");
    print "[MISSING] would run: $cmd\n" unless $fix;
    if ($fix) {
        my $ok = run($cmd);
        finding($ok ? "FIX" : "ERROR", ($ok ? "Installed $label" : "Failed installing $label"), $ok ? undef : "Run manually: $cmd");
        return $ok;
    }
    return 0;
}

sub check_or_fix_xfrm_routes {
    my ($fix) = @_;
    if (!@XFRM_ROUTES) { print "No XFRM routes configured in local config.\n"; return; }
    my $use_table_220 = table_220_is_active();
    for my $r (@XFRM_ROUTES) {
        my $dst = $r->{dst};
        my $dev = $r->{dev} || $XFRM_IF;
        my $src = $r->{src};
        $src = $OVERLAY_LOCAL if (!defined($src) || $src eq '') && defined($OVERLAY_LOCAL) && $OVERLAY_LOCAL ne '';
        next unless $dst;
        check_one_xfrm_route($dst, $dev, undef, $src, $fix);
        check_one_xfrm_route($dst, $dev, 220,  $src, $fix) if $use_table_220;
    }
}

sub check_or_fix_local_lan_routes {
    my ($fix) = @_;
    if (!@LOCAL_LAN_ROUTES) { print "No local LAN route repairs configured in local config.\n"; return; }
    for my $r (@LOCAL_LAN_ROUTES) {
        my $dst = $r->{dst}; my $dev = $r->{dev}; my $src = $r->{src};
        next unless $dst && $dev;
        if (route_destination_uses_dev($dst, $dev)) { finding("OK", "Local LAN route to $dst uses $dev", undef); next; }
        my $cmd = "sudo ip route replace $dst dev $dev" . (defined($src) && $src ne '' ? " src $src" : "");
        finding("ERROR", "Local LAN route to $dst is wrong; replies may leave via the wrong interface", "Run: $cmd. Fixes ipsec0 In reply followed by enp1s0 Out to WiFi client.");
        print "[ERROR] would run: $cmd\n" unless $fix;
        if ($fix) { my $ok = run($cmd); finding($ok ? "FIX" : "ERROR", ($ok ? "Repaired local LAN route $dst dev $dev" : "Failed repairing local LAN route $dst"), $ok ? undef : "Run manually: $cmd"); }
    }
}


sub check_or_fix_mtu_offload {
    my ($fix) = @_;
    my @ifaces = grep { defined($_) && $_ ne '' } @MTU_TUNE_IFACES;
    my %seen; @ifaces = grep { !$seen{$_}++ } @ifaces;

    if (!@ifaces) {
        print "No MTU/offload tuning interfaces configured.\n";
        print "Configure \\@MTU_TUNE_IFACES in ~/vpn_router_diagnose.local.pl, e.g. ('enp1s0') or ('ens3').\n";
    }
    for my $dev (@ifaces) {
        print "\n-- Interface MTU/offload: $dev --\n";
        my $link = `ip -br link show dev $dev 2>&1`;
        print "\$ ip -br link show dev $dev\n$link";
        if ($? != 0) {
            finding("WARN", "MTU/offload interface $dev is missing", "Check interface name in ~/vpn_router_diagnose.local.pl.");
            next;
        }
        my $mtu_out = `ip -o link show dev $dev 2>/dev/null`;
        my ($mtu) = $mtu_out =~ /\bmtu\s+(\d+)/;
        if (defined $mtu) {
            print "Current MTU : $mtu\n";
            if ($mtu > $SAFE_MTU) {
                my $cmd = "sudo ip link set dev $dev mtu $SAFE_MTU";
                finding("WARN", "$dev MTU is $mtu; safe VPN/VM MTU is $SAFE_MTU", "If SSH/HTTP hangs on large packets, run: $cmd");
                if ($fix) {
                    my $ok = run($cmd);
                    finding($ok ? "FIX" : "ERROR", ($ok ? "Set $dev MTU to $SAFE_MTU" : "Failed setting $dev MTU"), $ok ? undef : "Run manually: $cmd");
                } else { print "[DRY-RUN] would run: $cmd\n"; }
            } else { finding("OK", "$dev MTU is $mtu (<= $SAFE_MTU)", undef); }
        }
        my $ethtool = `command -v ethtool 2>/dev/null`; chomp $ethtool;
        if (!$ethtool) {
            finding("WARN", "ethtool not installed; cannot check offload on $dev", "Install with: sudo apt install ethtool");
            next;
        }
        my $features = `sudo ethtool -k $dev 2>&1`;
        print "\$ sudo ethtool -k $dev | egrep 'tcp-segmentation-offload|generic-segmentation-offload|generic-receive-offload'\n";
        for my $line (split /\n/, $features) { print "$line\n" if $line =~ /tcp-segmentation-offload|generic-segmentation-offload|generic-receive-offload/; }
        my @on;
        push @on, 'tso' if $features =~ /tcp-segmentation-offload:\s+on\b/;
        push @on, 'gso' if $features =~ /generic-segmentation-offload:\s+on\b/;
        push @on, 'gro' if $features =~ /generic-receive-offload:\s+on\b/;
        if (@on) {
            my $cmd = "sudo ethtool -K $dev tso off gso off gro off";
            finding("WARN", "$dev has offload features enabled: " . join(',', @on), "These can cause VM/bridge/IPsec SSH hangs. Run: $cmd");
            if ($fix) {
                my $ok = run($cmd);
                finding($ok ? "FIX" : "ERROR", ($ok ? "Disabled TSO/GSO/GRO on $dev" : "Failed disabling offload on $dev"), $ok ? undef : "Run manually: $cmd");
            } else { print "[DRY-RUN] would run: $cmd\n"; }
        } else { finding("OK", "$dev TSO/GSO/GRO offloads are already off", undef); }
    }

    if (defined($OVERLAY_LOCAL) && $OVERLAY_LOCAL ne '' && %TEST_TARGETS) {
        print "\n-- Path MTU smoke tests to TEST_TARGETS --\n";
        for my $name (sort keys %TEST_TARGETS) {
            my $ip = $TEST_TARGETS{$name}{test_ip}; next unless defined($ip) && $ip ne '';
            my $size = $MTU_PING_SIZE || 1360;
            my $cmd = "ping -I $OVERLAY_LOCAL -M do -s $size -c 1 -W 2 $ip";
            my $ok = run($cmd);
            finding($ok ? "OK" : "WARN", "Path MTU smoke test to $name ($ip) " . ($ok ? "works" : "failed") . " with payload $size", $ok ? undef : "This can cause SSH/HTTP hangs. Configure \\@MTU_TUNE_IFACES and run -fix, or lower MTU manually.");
        }
    }
}

sub no_sa_advice {
    my ($configured_ref) = @_;
    my @cmds;
    for my $conn (sort keys %$configured_ref) {
        my $child = $configured_ref->{$conn}{child} // '';
        if ($child ne '') {
            push @cmds, "sudo swanctl --initiate --child $child    # connection $conn";
        } else {
            push @cmds, "sudo swanctl --initiate --ike $conn";
        }
    }
    my $init = @cmds ? join("; ", @cmds) : "sudo swanctl --list-conns   # find the child name, then: sudo swanctl --initiate --child NAME";
    return "Run: sudo systemctl status strongswan-swanctl; sudo swanctl --load-all; $init";
}

sub inactive_peer_advice {
    my ($name, $cfg) = @_;
    my $child = $cfg->{child} // '';
    my $cmd = $child ne '' ? "sudo swanctl --initiate --child $child" : "sudo swanctl --initiate --ike $name";
    return "Try: $cmd; then check sudo journalctl -u strongswan-swanctl -n 100";
}

sub finding {
    my ($level, $summary, $advice) = @_;
    $level = uc($level // 'WARN');
    $COUNTS{$level}++ if exists $COUNTS{$level};
    push @FINDINGS, { level => $level, summary => $summary, advice => $advice };
}

sub print_final_diagnosis {
    section("Final diagnosis");
    print "OK      : $COUNTS{OK}\n";
    print "FIXED   : $COUNTS{FIX}\n";
    print "WARN    : $COUNTS{WARN}\n";
    print "MISSING : $COUNTS{MISSING}\n";
    print "ERROR   : $COUNTS{ERROR}\n";

    my @bad = grep { $_->{level} ne 'OK' && $_->{level} ne 'FIX' } @FINDINGS;
    if (!@bad) {
        print "\nNo unresolved errors found by scripted checks.\n";
        return;
    }

    print "\nProblems found and what to do:\n";
    my $n = 1;
    for my $f (@bad) {
        print "\n$n. [$f->{level}] $f->{summary}\n";
        print "   Advice: $f->{advice}\n" if defined $f->{advice} && $f->{advice} ne '';
        $n++;
    }

    print "\nInterpretation hints for unresolved problems:\n";
    print "- TEST_TARGETS are routed site peers/subnets to probe outward. Do not put NATed clients there.\n";
    print "- VPN_CLIENT_POOLS are incoming access clients hidden behind VPS NAT. Phones and laptops fit here.\n";
    print "- INPUT controls access to VPS services, e.g. http://10.47.0.1/.\n";
    print "- FORWARD controls whether VPS routes traffic onwards. INPUT access does not make VPS an internet gateway.\n";
    print "- POSTROUTING SNAT hides client VPN addresses from routed site peers.\n";
    print "- ROUTED_SITE_LANS are real LANs behind site peers. They need a VPS return route if you do not NAT them.\n";
    print "- If routervm sees LAN_CLIENT > 10.47.0.1 go Out on ipsec0 but no reply comes In, fix route on VPS: ip route replace LAN_NET via ROUTERVM_OVERLAY_IP.\n";
    print "- strongSwan IN/OUT counters are from the VPS/IPsec perspective.\n";
    print "- SSH hanging after connection/KEX often indicates MTU/offload problems; configure \ and run -fix.\n";
    print "- Site-peer host firewall matters too: even with IPsec up, INPUT DROP on the peer overlay IP can break ping/curl.\n";
}

sub print_usage {
    print "Usage:\n";
    print "  sudo perl VPN_router_diagnose.pl -diagnose   # detect only / dry-run\n";
    print "  sudo perl VPN_router_diagnose.pl -fix        # apply safe fixes\n";
    print "  sudo perl VPN_router_diagnose.pl             # ask mode interactively\n\n";
    print "Options:\n";
    print "  -diagnose, -d           Report problems and show what -fix would change.\n";
    print "  -fix, -f                Apply safe fixes for firewall/NAT/routes/XFRM/overlay /32 sanity.\n";
    print "  --show-conf             Print suggested swanctl config for NATed clients.\n";
    print "  --print-local-template  Print a repo/local config template.\n";
    print "  --help, -h              Show help.\n";
}

sub print_banner { print "\n=========================================\n VPN Router Diagnose v19 port-prompt\n=========================================\n"; }
sub section { my ($t)=@_; print "\n-----------------------------------------\n $t\n-----------------------------------------\n"; }
sub run { my ($cmd)=@_; print "\n\$ $cmd\n"; system($cmd); return ($? == 0); }
sub sh_quote { my ($s)=@_; $s =~ s/'/'\"'\"'/g; return "'$s'"; }

sub get_test_ip {
    my ($name, $cfg) = @_;
    return $TEST_TARGETS{$name}{test_ip} if exists $TEST_TARGETS{$name} && $TEST_TARGETS{$name}{test_ip};
    return derive_test_ip_from_ts($cfg->{remote_ts} // '');
}

sub derive_test_ip_from_ts {
    my ($ts) = @_;
    return "$1.1" if $ts =~ /^(\d+\.\d+\.\d+)\.0\/24$/;
    return $1 if $ts =~ /^(\d+\.\d+\.\d+\.\d+)\/32$/;
    return undef;
}

sub parse_swanctl_conf {
    my ($glob) = @_;
    my %peers;
    for my $file (glob($glob)) {
        open my $fh, '<', $file or next;
        local $/ = undef;
        my $text = <$fh>;
        close $fh;
        my @stack;
        my $conn;
        for my $raw (split /\n/, $text) {
            my $line = $raw;
            $line =~ s/#.*$//; $line =~ s/^\s+//; $line =~ s/\s+$//;
            next if $line eq '';
            if ($line =~ /^connections\s*\{$/) { push @stack, 'connections'; next; }
            if (@stack && $stack[-1] eq 'connections' && $line =~ /^([A-Za-z0-9_.-]+)\s*\{$/) {
                $conn = $1;
                $peers{$conn} ||= { file=>$file, name=>$conn, child=>'', peer_id=>'', local_id=>'', remote_addrs=>'', local_ts=>'', remote_ts=>'', pools=>'', if_id_in=>'', if_id_out=>'' };
                push @stack, 'conn'; next;
            }
            next unless defined $conn;
            if ($line =~ /^remote_addrs\s*=\s*(.+)$/) { $peers{$conn}{remote_addrs}=trim($1); next; }
            if ($line =~ /^pools\s*=\s*(.+)$/)        { $peers{$conn}{pools}=trim($1); next; }
            if ($line =~ /^local\s*\{$/)              { push @stack, 'local'; next; }
            if ($line =~ /^remote\s*\{$/)             { push @stack, 'remote'; next; }
            if ($line =~ /^children\s*\{$/)           { push @stack, 'children'; next; }
            if (@stack && $stack[-1] eq 'children' && $line =~ /^([A-Za-z0-9_.-]+)\s*\{$/) { $peers{$conn}{child}=$1; push @stack, 'child'; next; }
            if (@stack && $stack[-1] eq 'local'  && $line =~ /^id\s*=\s*(.+)$/) { $peers{$conn}{local_id}=trim($1); next; }
            if (@stack && $stack[-1] eq 'remote' && $line =~ /^id\s*=\s*(.+)$/) { $peers{$conn}{peer_id}=trim($1); next; }
            if (@stack && $stack[-1] eq 'child'  && $line =~ /^local_ts\s*=\s*(.+)$/) { $peers{$conn}{local_ts}=trim($1); next; }
            if (@stack && $stack[-1] eq 'child'  && $line =~ /^remote_ts\s*=\s*(.+)$/) { $peers{$conn}{remote_ts}=trim($1); next; }
            if (@stack && $stack[-1] eq 'child'  && $line =~ /^if_id_in\s*=\s*(.+)$/) { $peers{$conn}{if_id_in}=trim($1); next; }
            if (@stack && $stack[-1] eq 'child'  && $line =~ /^if_id_out\s*=\s*(.+)$/) { $peers{$conn}{if_id_out}=trim($1); next; }
            if ($line eq '}') { my $ctx = pop @stack // ''; $conn = undef if $ctx eq 'conn'; next; }
        }
    }
    return %peers;
}

sub get_active_sas {
    my $text = `sudo swanctl --list-sas 2>&1`;
    my %sas; my @blocks; my $cur='';
    for my $line (split /\n/, $text) {
        if ($line =~ /^[A-Za-z0-9_.-]+:\s+#\d+,\s+/) { push @blocks, $cur if $cur ne ''; $cur = "$line\n"; }
        else { $cur .= "$line\n" if $cur ne ''; }
    }
    push @blocks, $cur if $cur ne '';
    for my $block (@blocks) {
        next unless $block =~ /^([A-Za-z0-9_.-]+):\s+#\d+,\s+/m;
        my $name = $1;
        my ($underlay) = $block =~ /remote\s+'.*?'\s+\@\s+(\d+\.\d+\.\d+\.\d+)/m;
        my ($vip)      = $block =~ /remote\s+'.*?'\s+\@\s+\d+\.\d+\.\d+\.\d+\[\d+\]\s+\[(\d+\.\d+\.\d+\.\d+)\]/m;
        my ($in_b,$in_p)   = $block =~ /in\s+\S+.*?,\s+(\d+)\s+bytes,\s+(\d+)\s+packets/m;
        my ($out_b,$out_p) = $block =~ /out\s+\S+.*?,\s+(\d+)\s+bytes,\s+(\d+)\s+packets/m;
        my ($local_ts)  = $block =~ /\n\s+local\s+(.+)$/m;
        my ($remote_ts) = $block =~ /\n\s+remote\s+(.+)$/m;
        $sas{$name} = { block=>$block, established=>($block =~ /ESTABLISHED/) ? 1 : 0, remote_underlay=>$underlay, virtual_ip=>$vip, in_bytes=>$in_b//0, in_pkts=>$in_p//0, out_bytes=>$out_b//0, out_pkts=>$out_p//0, local_ts=>trim($local_ts//''), remote_ts=>trim($remote_ts//'') };
    }
    return ($text, %sas);
}

sub print_sa_interpretation {
    my ($sa)=@_;
    print "\n-- SA block --\n$sa->{block}";
    print "\n-- SA interpretation --\n";
    print "ESTABLISHED : " . ($sa->{established} ? "yes" : "no") . "\n";
    print "IN bytes    : $sa->{in_bytes}\nIN packets  : $sa->{in_pkts}\nOUT bytes   : $sa->{out_bytes}\nOUT packets : $sa->{out_pkts}\n";
    print "Remote seen : " . ($sa->{remote_underlay} // 'UNKNOWN') . "\n";
}

sub active_vpn_clients {
    my ($active_ref)=@_; my @clients;
    for my $conn (sort keys %$active_ref) {
        my $sa=$active_ref->{$conn}; my $vip=$sa->{virtual_ip}; next unless $vip;
        my ($pool_name,$pool)=find_pool_for_ip($vip);
        push @clients, { conn=>$conn, vip=>$vip, underlay=>$sa->{remote_underlay}, local_ts=>$sa->{local_ts}, remote_ts=>$sa->{remote_ts}, pool_name=>$pool_name, pool=>$pool };
    }
    return @clients;
}

sub find_pool_for_ip {
    my ($ip)=@_;
    for my $name (sort keys %VPN_CLIENT_POOLS) {
        my $pool=$VPN_CLIENT_POOLS{$name};
        return ($name,$pool) if ip_in_cidr($ip,$pool->{client_net});
    }
    return (undef,undef);
}

sub check_or_fix_routed_site_lan {
    my ($name,$lan,$fix)=@_;
    my $lan_net   = $lan->{lan_net};
    my $via       = $lan->{via};
    my $peer_name = $lan->{peer_name} // '';
    my $test_host = $lan->{test_host} // '';

    print "\n[$name]\n";
    print "  lan_net   : $lan_net\n";
    print "  via       : $via\n";
    print "  peer_name : $peer_name\n" if $peer_name ne '';
    print "  test_host : $test_host\n" if $test_host ne '';

    my $peer_active = ($peer_name ne '' && exists $active{$peer_name}) ? 1 : 0;
    print "  peer_sa   : " . ($peer_name eq '' ? 'not specified' : ($peer_active ? 'ACTIVE' : 'INACTIVE/WARN')) . "\n";

    print "\n-- Return route check --\n";
    my $route_probe = route_probe_ip($lan->{test_host} || $lan_net);
    my $route = `ip route get $route_probe 2>&1`;
    print "\$ ip route get $route_probe\n$route";

    my $has_route = route_uses_gateway($route, $via);
    if ($has_route) {
        print "  [OK] VPS has a return route for $lan_net via $via\n";
        finding("OK", "Return route exists for $lan_net via $via", undef);
    } else {
        print "  [MISSING] VPS may not know how to return traffic to $lan_net via $via\n";
        print "            This matches the symptom: LAN request exits routervm/ipsec0, but no reply returns.\n";
        finding("MISSING", "VPS return route missing/wrong for $lan_net via $via", "Run on VPS: sudo ip route replace $lan_net via $via. This preserves real LAN client IPs.");
        my $fixcmd = "sudo ip route replace $lan_net via $via";
        if ($fix) {
            print "  [FIX] Adding/replacing return route: $fixcmd\n";
            my $fixed = run($fixcmd);
            finding($fixed ? "FIX" : "ERROR", ($fixed ? "Installed return route $lan_net via $via" : "Failed to install return route $lan_net via $via"), $fixed ? undef : "Run manually and check gateway reachability: sudo ip route replace $lan_net via $via");
        } else {
            print "            would run: $fixcmd\n";
        }
    }

    print "\n-- Optional live packet test --\n";
    print "  On routervm, run:\n";
    print "    sudo tcpdump -ni any 'host $OVERLAY_LOCAL or net $lan_net'\n";
    print "  From LAN client, run:\n";
    print "    ping $OVERLAY_LOCAL\n";
    print "  Healthy pattern includes reply: $OVERLAY_LOCAL > LAN_CLIENT on ipsec0 In.\n";
    print "  Broken return-route pattern: LAN_CLIENT > $OVERLAY_LOCAL appears ipsec0 Out on routervm, but no reply appears.\n";
}

sub route_probe_ip {
    my ($target)=@_;
    return '' if !defined($target) || $target eq '';
    return $1 if $target =~ /^(\d+\.\d+\.\d+\.\d+)\/\d+$/;
    return $target;
}

sub route_uses_gateway {
    my ($route,$via)=@_;
    return 0 unless defined $route && defined $via;
    return ($route =~ /\bvia\s+\Q$via\E\b/ || $route =~ /\bdev\s+ipsec0\b.*\bsrc\s+\Q$via\E\b/) ? 1 : 0;
}


sub parse_port_list {
    my ($s) = @_;
    my @ports;
    for my $p (split /[\s,]+/, $s // '') {
        next if $p eq '';
        if ($p =~ /^\d+$/ && $p >= 1 && $p <= 65535) { push @ports, int($p); }
        else { print "  Ignoring invalid port: $p\n"; }
    }
    my %seen;
    return grep { !$seen{$_}++ } @ports;
}


sub check_or_fix_site_peer_host_firewall {
    my ($name, $peer_ip, $ports, $local_ports, $fix) = @_;
    return unless defined($OVERLAY_LOCAL) && $OVERLAY_LOCAL ne '' && defined($peer_ip) && $peer_ip ne '';

    my $local32 = cidr32($OVERLAY_LOCAL);
    my $peer32  = cidr32($peer_ip);

    print "\n-- Local host firewall checks for site peer $name --\n";
    print "  local_overlay : $local32\n";
    print "  peer_overlay  : $peer32\n";

    # Catches the common failure: IPsec and routes are OK, but INPUT DROP on the
    # local overlay IP prevents echo requests from being accepted. OUTPUT is also
    # checked for nodes with OUTPUT policy DROP.
    ensure_rule('INPUT',  undef, ['-s',$peer32,'-d',$local32,'-p','icmp','-j','ACCEPT'], $fix, "Allow ICMP from site peer $name ($peer32) to local overlay $local32");
    ensure_rule('OUTPUT', undef, ['-s',$local32,'-d',$peer32,'-p','icmp','-j','ACCEPT'], $fix, "Allow ICMP replies/requests from local overlay $local32 to site peer $name ($peer32)");

    # Local services on THIS node that should be reachable from the peer.
    # This catches cases like: routervm:80 works locally, but not from VPS/vpnClients.
    for my $port (@$local_ports) {
        next unless defined($port) && $port =~ /^\d+$/;
        ensure_rule('INPUT',  undef, ['-s',$peer32,'-d',$local32,'-p','tcp','--dport',$port,'-j','ACCEPT'], $fix, "Allow site peer $name ($peer32) to reach local TCP port $port on $local32");
        ensure_rule('OUTPUT', undef, ['-s',$local32,'-d',$peer32,'-p','tcp','-m','conntrack','--ctstate','RELATED,ESTABLISHED','-j','ACCEPT'], $fix, "Allow established TCP replies from local port $port on $local32 to $name ($peer32)");
    }

    # If this node initiates TCP tests to peer services, OUTPUT must allow those
    # SYNs when OUTPUT policy is DROP. The peer still needs its own INPUT rules;
    # run this script on that peer too to enforce its local firewall.
    for my $port (@$ports) {
        next unless defined($port) && $port =~ /^\d+$/;
        ensure_rule('OUTPUT', undef, ['-s',$local32,'-d',$peer32,'-p','tcp','--dport',$port,'-j','ACCEPT'], $fix, "Allow outbound TCP test to $name ($peer32) port $port from $local32");
        ensure_rule('INPUT',  undef, ['-s',$peer32,'-d',$local32,'-p','tcp','-m','conntrack','--ctstate','RELATED,ESTABLISHED','-j','ACCEPT'], $fix, "Allow established TCP replies from $name ($peer32) to $local32");
    }
}

sub cidr32 {
    my ($ip) = @_;
    return '' unless defined $ip;
    return $ip if $ip =~ m{/};
    return "$ip/32";
}

sub check_or_fix_client_pool {
    my ($pool,$fix)=@_;
    my $client_net=$pool->{client_net}; my $dst=$pool->{allowed_dst}; my $nat_to=$pool->{nat_to}; my $svc=$pool->{local_service_ip}; my $ports=$pool->{service_ports} || [];
    print "\n-- VPN client policy checks --\n";
    print "  client_net  : $client_net\n  allowed_dst : $dst\n  nat_to      : $nat_to\n  service_ip  : $svc\n";

    # These INPUT permits must be before broad DROP rules for the local overlay IP.
    # ensure_rule() inserts ACCEPT rules at the top when -fix is used.
    for my $port (@$ports) {
        ensure_rule('INPUT', undef, ['-s',$client_net,'-d',$svc,'-p','tcp','--dport',$port,'-j','ACCEPT'], $fix, "Allow VPN clients to reach local VPS tcp/$port");
    }
    ensure_rule('INPUT', undef, ['-s',$client_net,'-d',$svc,'-p','icmp','-j','ACCEPT'], $fix, "Allow VPN clients to ping local VPS overlay IP");

    ensure_rule('FORWARD', undef, ['-s',$client_net,'-d',$dst,'-j','ACCEPT'], $fix, "Allow VPN clients to reach internal overlay only");
    ensure_rule('FORWARD', undef, ['-s',$dst,'-d',$client_net,'-m','conntrack','--ctstate','RELATED,ESTABLISHED','-j','ACCEPT'], $fix, "Allow established replies to VPN clients");
    ensure_rule('FORWARD', undef, ['-s',$client_net,'!','-d',$dst,'-j','DROP'], $fix, "Block VPN clients from using VPS as internet gateway");
    ensure_rule('POSTROUTING', 'nat', ['-s',$client_net,'-d',$dst,'-j','SNAT','--to-source',$nat_to], $fix, "SNAT VPN clients to provider-edge overlay IP");
}

sub ensure_rule {
    my ($chain,$table,$args,$fix,$desc)=@_;
    my $table_part = defined($table) ? "-t $table " : "";
    my $cmd_base = "sudo iptables ${table_part}";
    my $argstr = join(' ', map { sh_quote($_) } @$args);
    my $check = "$cmd_base-C $chain $argstr";

    my $target = iptables_target($args);
    my $is_filter_table = !defined($table) || $table eq 'filter';

    # Important: ACCEPT rules must be inserted before broad DROP rules.
    # DROP/SNAT rules are appended so they do not accidentally override earlier allows.
    my $op = ($is_filter_table && defined($target) && $target eq 'ACCEPT') ? "-I $chain 1" : "-A $chain";
    my $add = "$cmd_base$op $argstr";

    system("$check >/dev/null 2>&1");
    if ($? == 0) {
        print "  [OK] $desc\n";
        finding("OK", $desc, undef);
        warn_if_allow_shadowed_by_earlier_drop($chain, $table, $args, $desc) if $is_filter_table && defined($target) && $target eq 'ACCEPT';
        return 1;
    }
    if (!$fix) {
        print "  [MISSING] $desc\n            would add: $add\n";
        finding("MISSING", $desc, "Run with -fix or add manually: $add");
        return 0;
    }
    print "  [FIX] $desc\n";
    my $ok = run($add);
    finding($ok ? "FIX" : "ERROR", ($ok ? "Added rule: $desc" : "Failed adding rule: $desc"), $ok ? undef : "Try manually: $add");
    warn_if_allow_shadowed_by_earlier_drop($chain, $table, $args, $desc) if $ok && $is_filter_table && defined($target) && $target eq 'ACCEPT';
    return $ok;
}

sub iptables_target {
    my ($args)=@_;
    for (my $i=0; $i < @$args - 1; $i++) {
        return $args->[$i+1] if $args->[$i] eq '-j';
    }
    return undef;
}

sub arg_value {
    my ($args,$key)=@_;
    for (my $i=0; $i < @$args - 1; $i++) {
        return $args->[$i+1] if $args->[$i] eq $key;
    }
    return undef;
}

sub warn_if_allow_shadowed_by_earlier_drop {
    my ($chain,$table,$args,$desc)=@_;
    return if defined($table) && $table ne 'filter';

    my $dst   = arg_value($args, '-d');
    my $src   = arg_value($args, '-s');
    my $proto = arg_value($args, '-p');
    my $dport = arg_value($args, '--dport');

    my $rules = `sudo iptables -S $chain 2>/dev/null`;
    return if $rules eq '';

    my @lines = split /\n/, $rules;
    my $saw_shadowing_drop = 0;
    my $shadow_line = '';

    for my $line (@lines) {
        next unless $line =~ /^-A\s+\Q$chain\E\b/;

        if (line_matches_allow_rule($line, $src, $dst, $proto, $dport)) {
            if ($saw_shadowing_drop) {
                print "  [WARN] $desc exists, but an earlier DROP may shadow it:\n";
                print "         $shadow_line\n";
                finding("WARN", "$desc may be shadowed by an earlier DROP rule", "Move the ACCEPT above the DROP, for example: sudo iptables -I $chain 1 ...; or run this script with -fix after removing the shadowed appended rule.");
            }
            return;
        }

        if (line_is_shadowing_drop($line, $src, $dst)) {
            $saw_shadowing_drop = 1;
            $shadow_line = $line;
        }
    }
}

sub line_matches_allow_rule {
    my ($line,$src,$dst,$proto,$dport)=@_;
    return 0 unless $line =~ /\s-j\s+ACCEPT\b/;
    return 0 if defined($src)   && !iptables_line_has_addr($line, '-s', $src);
    return 0 if defined($dst)   && !iptables_line_has_addr($line, '-d', $dst);
    return 0 if defined($proto) && $line !~ /\s-p\s+\Q$proto\E\b/;
    return 0 if defined($dport) && $line !~ /\s--dport\s+\Q$dport\E\b/;
    return 1;
}

sub line_is_shadowing_drop {
    my ($line,$src,$dst)=@_;
    return 0 unless $line =~ /\s-j\s+DROP\b/;

    # Main bug this catches: a broad DROP to the same local overlay IP before
    # later ACCEPT rules for VPN clients. A DROP without -d is also broad enough
    # to be suspicious.
    if (defined($dst)) {
        return 1 if iptables_line_has_addr($line, '-d', $dst);
        return 1 if $line !~ /\s-d\s+/;
        return 0;
    }
    return 1;
}

sub iptables_line_has_addr {
    my ($line,$flag,$addr)=@_;
    return 0 unless defined $addr && $addr ne '';
    my $q = quotemeta($addr);
    my $without_32 = $addr;
    $without_32 =~ s{/32$}{};
    my $q2 = quotemeta($without_32);
    return 1 if $line =~ /\s\Q$flag\E\s+$q(?:\s|$)/;
    return 1 if $line =~ /\s\Q$flag\E\s+$q2\/32(?:\s|$)/;
    return 0;
}

sub ip_to_int { my ($ip)=@_; return undef unless $ip =~ /^(\d+)\.(\d+)\.(\d+)\.(\d+)$/; for ($1,$2,$3,$4) { return undef if $_<0 || $_>255; } return (($1<<24)|($2<<16)|($3<<8)|$4); }
sub ip_in_cidr { my ($ip,$cidr)=@_; return 0 unless defined $ip && defined $cidr; return $ip eq $cidr if $cidr !~ m{/}; my ($net,$bits)=split m{/},$cidr,2; return 0 unless defined $bits && $bits =~ /^\d+$/ && $bits>=0 && $bits<=32; my $ii=ip_to_int($ip); my $ni=ip_to_int($net); return 0 unless defined $ii && defined $ni; my $mask=$bits==0 ? 0 : (0xffffffff << (32-$bits)) & 0xffffffff; return (($ii & $mask) == ($ni & $mask)); }

sub print_suggested_swanctl_conf {
    print "Example swanctl snippet for NATed clients:\n\n";
    print "pools {\n  vpnClients {\n    addrs = 192.168.250.0/24\n  }\n}\n\n";
    print "connections {\n  vpnClients {\n    version = 2\n    local_addrs = 81.88.19.252\n    encap = yes\n    pools = vpnClients\n\n";
    print "    local {\n      auth = psk\n      id = 81.88.19.252\n    }\n\n";
    print "    remote {\n      auth = psk\n      id = %any\n    }\n\n";
    print "    children {\n      net {\n        local_ts = 10.0.0.0/8\n        remote_ts = dynamic\n        start_action = trap\n      }\n    }\n  }\n}\n\n";
    print "secrets {\n  ike-vpnClients {\n    id = %any\n    secret = \"CHANGE_THIS_LONG_RANDOM_PSK\"\n  }\n}\n";
}

sub trim { my ($s)=@_; $s =~ s/^\s+// if defined $s; $s =~ s/\s+$// if defined $s; return $s; }
