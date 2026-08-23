#!/usr/bin/perl
use strict;
use warnings;

print STDERR <<'MSG';
sleepingbeauty.pl is deprecated.

Hotspot access control is now managed by systemd:
  tarasec-hotspot-access.service

Hotspot health/DHCP checks are managed by:
  tarasec-hotspot-watch.timer

Install/update the services with:
  sudo bash misc/setup_node_app_services.sh

Inspect the access controller with:
  sudo systemctl status tarasec-hotspot-access.service
  sudo journalctl -u tarasec-hotspot-access.service -n 100 --no-pager
MSG

exit 0;
