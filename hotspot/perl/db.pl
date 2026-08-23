#!/usr/bin/perl
use strict;
use warnings;

print STDERR <<'MSG';
hotspot/perl/db.pl is retired.

Historically this script combined IPFM usage accounting with a complete
iptables/NAT flush and firewall rebuild. That is unsafe on current TaraSec
nodes because it can erase TaraSec, WireGuard/NetBird and other host rules.

Replacement services:
  tarasec-hotspot-usage.timer    IPFM/quota accounting
  tarasec-hotspot-access.service fallback access control when openNDS is absent
  opennds.service               preferred captive portal when installed

Install/update these through:
  sudo bash misc/setup_node_app_services.sh

For a one-off usage refresh:
  sudo systemctl start tarasec-hotspot-usage.service
MSG

exit 1;
