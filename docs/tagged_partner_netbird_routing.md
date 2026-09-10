# Tagged partner routing over NetBird

This experiment lets a TaraSec router treat an explicitly registered partner's NetBird address as an alternate path for TaraSec-tagged TCP traffic.

## Why

A TaraSec hotspot may be behind ordinary NAT or CGNAT and therefore cannot accept unsolicited traffic on a public IPv4 address. NetBird gives the hotspot a reachable overlay address. For a partnerRouter row that has both a public TaraSec identity and a NetBird address, the sender can redirect tagged traffic to the NetBird address instead of using the ordinary Internet path.

Ordinary, untagged traffic is not changed.

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

Run as root on the sending TaraSec gateway:

```bash
sudo ./misc/setup_tagged_partner_netbird_routing.sh
```

Use `DRY_RUN=1` to print the nftables table without applying it.

The generated rules use the TCP urgent-pointer field because that is where the current TaraSec compact tag is carried. nftables supports matching `tcp urgptr` directly. The first tagged packet creates a conntrack NAT mapping, so later packets in the same connection continue to the same NetBird destination even when they do not repeat the tag.

The NetBird egress is masqueraded so the receiving partner returns the connection over the overlay rather than attempting to route the sender's private LAN address.

## Scope and limitation

This first version implements:

`partner public IP -> partner NetBird IP`, preserving the TCP port.

That is appropriate for traffic handled by the partner router itself, including TaraSec services, gateway SSH/honeypot testing, and similar router-local endpoints.

It does **not** yet make every client behind the remote hotspot independently addressable. For that, TaraSec needs another mapping such as:

`public partner IP + port range -> partnerRouter -> internal unit/IP + port range`

That can be added without changing the public-to-NetBird discovery model introduced here.

## Safety properties

- NetBird remains an overlay/management interface, not the hotspot's default WAN.
- Routing is disabled by default.
- Only partnerRouter rows explicitly enabled for NetBird routing are used.
- Only TCP packets with a non-zero TaraSec tag are redirected.
- No demo/test semantics are inferred by the gateway.
