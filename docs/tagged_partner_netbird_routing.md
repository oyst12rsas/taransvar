# Tagged partner routing over NetBird

This experiment lets a TaraSec gateway treat an explicitly registered partner router's NetBird address as an alternate path for TaraSec-tagged TCP traffic.

## Primary use case

The important case is:

`hotspot behind NAT/CGNAT -> ordinary TaraSec router`

The hotspot does not need a public IPv4 address. It only needs working NetBird connectivity. When tagged traffic is addressed to the public IP of a known partner router, TaraSec can redirect that connection to the partner router's NetBird IP instead of trying to reach the public address directly.

This means a community hotspot can participate in TaraSec routing even when its ISP places it behind NAT or CGNAT.

Ordinary, untagged Internet traffic is not changed.

## Data model

Apply:

```sql
SOURCE db/migrate_partnerrouter_netbird_routing.sql;
```

Then configure a partner explicitly, for example:

```sql
UPDATE partnerRouter
   SET netbirdIp=INET_ATON('100.68.51.247'),
       routeTaggedViaNetbird=b'1'
 WHERE ip=INET_ATON('203.0.113.25');
```

The feature is opt-in per partnerRouter row.

## Runtime

Run as root on the sending TaraSec gateway or hotspot:

```bash
sudo ./misc/setup_tagged_partner_netbird_routing.sh
```

Use `DRY_RUN=1` to print the nftables table without applying it.

The generated rules use the TCP urgent-pointer field because that is where the current TaraSec compact tag is carried. The first tagged packet creates a conntrack NAT mapping, so later packets in the same connection continue to the same NetBird destination even when they do not repeat the tag.

The NetBird egress is masqueraded so the receiving ordinary router returns the connection over the overlay rather than trying to route the hotspot client's private address directly.

## What the receiving router sees

The destination IP on the overlay is the ordinary router's NetBird IP and the original TCP port is preserved. This is therefore directly useful for services handled by that router itself, including TaraSec control/demo endpoints, SSH/honeypot services, and other router-local services.

If the ordinary router already has local port-forwarding rules for a service, those can be extended to accept the same service arriving on the NetBird interface. TaraSec does not need the hotspot to be publicly reachable.

## Later extension: clients behind the ordinary router

This first version maps:

`partner public IP -> partner NetBird IP`, preserving the TCP port.

If TaraSec later needs to address multiple different clients behind the receiving router, add a mapping such as:

`public partner IP + port range -> partnerRouter -> internal unit/IP + port range`

That is a separate destination-selection problem and does not change the public-IP-to-NetBird transport introduced here.

## Safety properties

- NetBird remains an overlay interface, not the hotspot's default WAN.
- Routing is disabled by default.
- Only partnerRouter rows explicitly enabled for NetBird routing are used.
- Only TCP packets with a non-zero TaraSec tag are redirected.
- Untagged customer Internet traffic follows the normal WAN.
- No demo/test semantics are inferred by the gateway.
