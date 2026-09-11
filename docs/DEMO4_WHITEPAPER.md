# Demo 4 Whitepaper: NATed Hotspot Contribution Through a TaraSec VPS Partner

**Status:** Design proposal for implementation and testing  
**Project:** TaraSec / Taransvar  
**Last updated:** 2026-09-11

## Abstract

Demo 4 is intended to demonstrate how a TaraSec hotspot behind ordinary NAT can make a real contribution to the cooperative TaraSec network without receiving a public IP address.

A publicly reachable VPS is registered as a TaraSec partner and also participates in the NetBird overlay. A phone connected through a TaraSec hotspot accesses a service at the VPS's ordinary public IP address. While the phone is clean, its traffic follows the normal internet route. When the phone is marked infected, only its tagged traffic to the registered TaraSec destination is policy-routed through NetBird. When the phone becomes clean again, new connections return to the normal public route.

NetBird provides the encrypted overlay, peer authentication, NAT traversal and relay capability. TaraSec supplies the infection state, packet tagging, participant directory, destination authorization, policy routing and fail-closed behavior.

Demo 4 is separate from Demo 2 because it uses only one special remote node: a VPS that is reachable both through a public internet address and through NetBird.

## Why this matters

Most community hotspots are behind consumer NAT, mobile broadband or other networks where inbound connections and stable public addressing are unavailable. This must not prevent them from participating in TaraSec.

Demo 4 tests whether a NATed hotspot can:

- contribute tagged traffic to another TaraSec participant;
- preserve TaraSec metadata across an encrypted overlay;
- avoid adding overlay latency to clean traffic;
- avoid changing routes for non-participant destinations;
- use a stable, authenticated route despite local NAT;
- fail safely if the protected route is unavailable.

This makes participation practical for community hotspots without turning them into publicly exposed routers.

## Scope

Demo 4 covers traffic from one phone or unit connected to a TaraSec hotspot and addressed to one registered VPS partner.

It does **not** create a general VPN or public internet exit service. Tagged traffic may enter the TaraSec overlay only when its destination is a registered TaraSec participant authorized for this test. Known malicious traffic must not be knowingly relayed to arbitrary third parties.

## Demonstration topology

1. **Phone/unit** — connected to the TaraSec hotspot and assigned a TaraSec unit identity.
2. **TaraSec hotspot** — behind NAT; runs tarakernel, taralink and NetBird.
3. **NetBird overlay** — carries authorized tagged traffic from the hotspot.
4. **VPS partner** — has both a public internet IP address and a NetBird address.
5. **Test service** — runs on the VPS and records the arrival interface, source, TaraSec tag and timing.

The same application-level destination remains the VPS public IP throughout the demonstration. The gateway, not the phone application, selects the route.

## Expected routing states

| Unit state | Destination | Route | Expected result |
|---|---|---|---|
| Clean | Registered VPS public IP | Normal internet route | Service is reached through the VPS public interface |
| Infected | Registered VPS public IP | TaraSec policy route through NetBird | Service receives the tagged flow through the TaraSec overlay |
| Infected | Non-TaraSec destination | Normal policy or local containment policy | No TaraSec overlay transit |
| Infected, TaraSec route unavailable | Registered VPS public IP | No fallback to an untagged public route | Flow is blocked and the failure is logged |
| Clean again | Registered VPS public IP | Normal internet route for new connections | Service is reached publicly again |

Existing TCP connections may remain associated with their original conntrack entry and route. Route transitions must therefore be demonstrated with new connections.

## Proposed data-plane operation

### Clean state

The phone sends traffic to the VPS public IP. The hotspot performs its normal forwarding and NAT behavior. No TaraSec policy mark selects the overlay routing table, so the main routing table sends the flow over the hotspot's ordinary uplink.

### Infected state

1. Demo 2-style infection state identifies the phone's TaraSec unit as infected.
2. Tarakernel applies the TaraSec tag to traffic from that unit.
3. The gateway checks whether the destination belongs to a registered TaraSec participant.
4. Taralink or the firewall layer applies a dedicated routing mark only when both conditions are true:
   - the traffic is tagged; and
   - the destination is an authorized TaraSec participant.
5. An `ip rule` selects a dedicated TaraSec routing table for the mark.
6. That table routes the VPS public `/32` through the NetBird interface `wt0`.
7. NetBird encrypts and transports the inner packet to the VPS partner.
8. The VPS accepts traffic for its public service address arriving through the authorized overlay path.

The inner TCP packet and TaraSec tag must survive overlay encapsulation. Any SNAT needed for return routing must not remove or invalidate the TaraSec tag. Pseudonymous TaraSec unit attribution must not depend solely on the translated IP address.

### Return path

The design must provide a deterministic return path. The initial implementation may SNAT the tunneled flow to the hotspot's NetBird address, allowing the VPS to return traffic through NetBird. TaraSec's pseudonymous unit identifier remains the source of unit-level attribution.

An alternative is to advertise hotspot client subnets through the overlay. That preserves more network information but is harder to scale and introduces overlapping-subnet concerns. SNAT is therefore the preferred initial demonstration approach.

### Return to clean state

When the unit is marked clean, the gateway stops applying the TaraSec routing mark. New connections use the main routing table and the VPS public route. Existing tracked connections should either expire naturally or be explicitly cleared as part of the demonstration reset if safe.

## Control-plane requirements

The DB server or designated TaraSec directory must distribute an authenticated mapping containing at least:

- TaraSec partner identifier;
- public service IP or prefix;
- NetBird peer or next-hop identity;
- allowed ports or services;
- route priority;
- validity period;
- active/inactive state;
- demo/test classification.

The hotspot must accept routes only from the trusted TaraSec control plane. A hotspot must not be able to declare an arbitrary public destination as a TaraSec participant.

## Security requirements

### Participant-only forwarding

The overlay path must be restricted to explicitly registered destination IPs and services. The VPS must not operate as a generic internet exit node.

### Fail closed

If tagged traffic is intended for a TaraSec participant but the NetBird/TaraSec route is unavailable, the gateway must block and log the flow. It must not silently send the same flow untagged through the ordinary public route.

### Identity and authenticity

The receiving participant must be able to distinguish:

- the registered hotspot or owner;
- the pseudonymous originating unit;
- infection/tag state;
- demo traffic versus real traffic;
- stale or replayed information.

A later implementation may supplement the packet tag with a short signed flow assertion containing the destination, pseudonymous unit ID, severity/state, timestamp, expiry and nonce.

### Loop prevention

TaraSec routes require a hop limit and stable destination ownership. A relay or participant must never send the same protected flow back toward a previous TaraSec hop.

### Separation from administration

A VPS used for experimental TaraSec transit must not be the only recovery path for its own administration. Administrative SSH should remain separately restricted, preferably with an independent provider console or recovery mechanism.

### Logging and privacy

Logs should contain only the information required to verify routing and TaraSec behavior. Unit identity should remain pseudonymous. Demo records must be explicitly marked as test data and given a defined retention period.

## NetBird's role

NetBird already supplies most of the overlay transport:

- authenticated peers;
- WireGuard encryption;
- stable overlay addresses;
- NAT traversal;
- direct peer-to-peer connectivity where possible;
- relay fallback when direct connectivity is unavailable;
- access-control distribution.

TaraSec should not build a competing tunnel protocol for Demo 4. It should add policy routing and participant authorization on top of NetBird.

For longer-term resilience, the deployment should consider redundant NetBird management, signal and relay services. Established direct WireGuard paths may survive some control-plane interruptions, but TaraSec must not assume that all paths will remain available after peer restart, address change or relay failure.

## Latency and resource properties

A central benefit of Demo 4 is selective overhead:

- clean traffic keeps the normal route;
- untagged traffic does not enter the TaraSec overlay;
- traffic to non-participants does not enter the overlay merely because its source unit is infected;
- only tagged traffic to participating destinations incurs tunnel or relay latency;
- the receiving participant gains a controlled and recognizable ingress path for higher-risk traffic.

Latency is therefore concentrated on traffic already subject to TaraSec handling rather than imposed on every hotspot user.

## Proposed demonstration sequence

1. Register the VPS public IP, NetBird identity and test service as a TaraSec partner destination.
2. Confirm that the phone is clean.
3. Start a new connection from the phone to the VPS public service.
4. Record that the connection arrived through the normal public path.
5. Record baseline latency and route information.
6. Mark the phone infected using the Demo 2 infection mechanism.
7. Start a new connection to the same public destination.
8. Verify that the gateway tagged and policy-routed the connection through `wt0`.
9. Verify that the VPS received the flow through the TaraSec/NetBird path with valid TaraSec metadata.
10. Access an unrelated non-TaraSec destination and verify that it continues to use the normal route.
11. Temporarily make the TaraSec route unavailable and verify that participant-bound tagged traffic is blocked rather than leaked through the public route.
12. Mark the phone clean.
13. Start a new connection and verify that it again uses the ordinary public path.
14. Reset all temporary demo state and retain an explicitly marked demo audit record.

## Evidence to display

The phone/app, hotspot dashboard and VPS should collectively show:

- demo/session name;
- pseudonymous unit identity;
- clean or infected state;
- destination partner;
- packet tag or verified flow assertion;
- selected route: public or TaraSec overlay;
- ingress interface observed by the VPS;
- latency for the current path;
- block reason when fail-closed behavior is tested;
- timestamps and final demo completion state.

## Acceptance criteria

Demo 4 succeeds only if all of the following are demonstrated:

- The NATed hotspot establishes TaraSec connectivity without an inbound public address.
- Clean connections to the VPS use the ordinary public route.
- New infected/tagged connections to the same public destination use NetBird.
- The VPS observes and validates TaraSec metadata.
- Non-participant traffic is not redirected into the TaraSec overlay.
- Overlay failure cannot downgrade tagged participant traffic to an untagged public connection.
- Returning the unit to clean state restores the ordinary route for new connections.
- No VPS becomes an unrestricted internet exit.
- Demo records are distinguishable from production security events.

## Implementation areas

Likely implementation work includes:

- DB schema or directory fields mapping participant public destinations to NetBird next hops;
- DB-server distribution of signed/authorized participant routes;
- taralink configuration updates;
- tarakernel or nftables/iptables marking rules;
- a dedicated Linux policy-routing table;
- NetBird route and ACL configuration;
- SNAT and return-path handling;
- VPS ingress validation and logging;
- Demo 4 app/dashboard presentation;
- automated cleanup and rollback;
- tests for route failure, stale state, loops and reconnects.

## Open questions

Before implementation, the following should be decided:

1. Whether the first VPS is the final service endpoint or a transit router in front of another TaraSec service.
2. Whether Demo 4 uses only the existing TCP tag or also prototypes a signed flow assertion.
3. How the partner directory authorizes ports and public prefixes.
4. Whether conntrack entries are cleared during state transitions.
5. How two public TaraSec routers are selected for priority and failover.
6. Whether NetBird network routes can be used directly or TaraSec should manage a separate marked routing table while using `wt0` only as transport.
7. What telemetry is retained and for how long.
8. How the demo behaves when NetBird management is down but an existing peer tunnel is still usable.

## Current status

The following foundation was added on 2026-09-11:

- database version 87 adds `partnerRouter.taggedTrafficRoute` and `taggedTrafficRouteUpdated`;
- Gatekeeper can set, display, edit and disable the route;
- `/script/appDemo4.php` exposes configured routes to registered TaraSec gateways;
- the Android app contains a Demo 4 panel that displays the DB-authoritative route configuration.

The complete packet-routing chain is **not yet implemented**. In particular, the app's route display does not prove that taralink installed a policy route.

The next engineering step is to make taralink consume the authorized route list, install the marked `wt0` routing policy atomically, report its applied state, and then add end-to-end clean/infected path verification.
