# Demo 4 — Design Goal and Invariants

## Primary goal

Demo 4 shall prove that a TaraSec hotspot located behind ordinary ISP NAT or carrier-grade NAT (for example a Safaricom mobile or community-hotspot connection) can participate in TaraSec partner routing **without having its own stable public IP address**.

When TaraSec identifies a flow as tagged partner/security traffic, that flow shall be routed through a TaraSec-controlled egress router that has a stable public IP address. The receiving network (for example Telenor) can then recognize the source public IP as belonging to an approved TaraSec partner path.

The stable public identity therefore belongs to the TaraSec egress router, not to the hotspot.

## Intended traffic path

Normal traffic:

```text
phone/client
    |
TaraSec hotspot behind Safaricom NAT/CGNAT
    |
    +---------------------> normal Safaricom Internet path
```

TaraSec-tagged partner traffic:

```text
phone/client
    |
TaraSec hotspot behind Safaricom NAT/CGNAT
    |
    | TaraSec tag causes selective policy routing
    v
WireGuard/IPsec-style encrypted tunnel initiated outbound by hotspot
    |
    v
TaraSec egress router with stable public IP
    |
    | SNAT to approved stable public TaraSec address
    v
receiving partner network, e.g. Telenor
```

## What selects the special route

The special route must ultimately be selected by **TaraSec classification/tagging**, not merely by installing a route for every destination.

For the Demo 4 test, two conditions are deliberately required:

1. the flow carries a TaraSec tag; and
2. its destination is an explicitly authorized partner destination.

This prevents the tunnel from becoming a general-purpose Internet exit while demonstrating that a tagged flow can receive a stable, recognizable public source identity.

The partner-destination restriction is a safety/authorization condition. It is **not** the primary routing signal. The TaraSec tag is the primary signal.

## Design invariants

Every Demo 4 implementation should preserve these properties:

1. **No public IP requirement for the hotspot.** The hotspot may be behind home NAT, mobile NAT or CGNAT.
2. **Outbound tunnel establishment.** The hotspot initiates the encrypted tunnel so no inbound NAT mapping is required at the hotspot.
3. **Selective routing only.** Untagged/ordinary user traffic continues over the normal ISP connection.
4. **Tag-driven policy.** A TaraSec-tagged flow can be selected for partner routing independently of the hotspot's public source address.
5. **Stable egress identity.** The receiving network sees an approved stable public IP belonging to a TaraSec egress router.
6. **Partner authorization.** Tagged traffic may use this path only for destinations/services authorized by TaraSec control data.
7. **No unrestricted exit VPN.** A TaraSec egress router must never become a generic Internet gateway for hotspot users.
8. **Fail closed for selected flows.** If a tagged flow is required to use the TaraSec partner path and that path is unavailable, it must not silently fall back to the ordinary ISP path as untagged traffic.
9. **Normal traffic must remain unaffected.** Installing or removing Demo 4 must not replace the hotspot's normal default route.
10. **Scalable provisioning.** Adding a hotspot must not require manual creation of per-destination NetBird network routes. Tunnel credentials and policy should be automatable by TaraSec.
11. **Receiving partner need not know hotspot NAT details.** Telenor or another receiving partner should only need to trust/recognize the stable TaraSec egress IP (plus any future TaraSec authentication/metadata), not Safaricom's changing CGNAT addresses.
12. **TaraSec attribution remains separate from NAT identity.** Unit/owner attribution must use TaraSec identifiers/metadata; the shared egress IP identifies the approved TaraSec path, not the individual user.

## Current Demo 4 test architecture

The next test uses plain WireGuard rather than NetBird network-route management:

- `tarakernel` creates the existing TaraSec TCP tag;
- hotspot firewall rules detect the tag after tarakernel's PREROUTING processing;
- the decision is stored in conntrack and represented as a Linux `fwmark`;
- an `ip rule` sends only marked flows to a dedicated routing table;
- that table sends the flow through `wg-demo4`;
- the remote TaraSec egress router only forwards authorized tagged flows;
- the egress router SNATs accepted flows to its stable public address;
- a second policy rule blackholes selected traffic if the WireGuard route cannot resolve, preventing silent fallback to the hotspot's ordinary ISP route.

The current test still restricts forwarding to one configured partner destination. That keeps the experiment bounded and demonstrates the intended trust model. A later control-plane implementation can distribute multiple authorized partner prefixes/services without changing the underlying tag-driven routing principle.

## Success criterion

The most important observable result is:

> Two otherwise similar connections from a NATed TaraSec hotspot behave differently solely because TaraSec selected one as partner/tagged traffic: the ordinary connection exits through the hotspot ISP, while the selected connection exits through the stable public TaraSec partner-router IP that the receiving network can recognize.

If a proposed Demo 4 design does not preserve this property, it is solving a different problem.
