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
#     { dst => "10.47.1.0/24", dev => "ipsec0" },
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
