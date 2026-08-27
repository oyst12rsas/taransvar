#!/usr/bin/perl
use strict;
use warnings;

# Legacy compatibility wrapper.
#
# The historical version of this script wrote /etc/network/interfaces and
# restarted systemd-networkd. That is unsafe on modern Ubuntu installations
# using NetworkManager and could take the active Internet connection down.
#
# Network/firewall generation now lives in net_setup.pl and hotspot Wi-Fi
# configuration lives in setupWifiNicAsHotspot.pl.

print "\nTaraSec setup_network.pl legacy wrapper\n";
print "This script no longer rewrites /etc/network/interfaces or restarts systemd-networkd.\n\n";

if (system('systemctl is-active --quiet NetworkManager') == 0) {
    print "NetworkManager detected. Existing uplink will be preserved.\n";
} else {
    print "WARNING: NetworkManager is not active. No automatic network changes will be made.\n";
}

print "For TaraSec firewall/router configuration use:\n";
print "  misc/net_setup.conf + misc/net_setup.pl\n\n";
print "For a Wi-Fi hotspot use:\n";
print "  sudo perl misc/setupWifiNicAsHotspot.pl <wifi-interface> [SSID] [gateway/prefix]\n\n";
print "No changes were made.\n";

exit 0;
