# TaraSec Fact Sheet — Assistance Request

## What it is

A TaraSec Assistance Request lets a network under attack or abnormal load ask the originating network to help reduce harmful traffic at its source.

Instead of forcing the victim to absorb, classify and block every unwanted packet locally, TaraSec enables the victim to say, in effect:

**“Traffic associated with this reported unit is contributing to an attack or excessive load. Please help stop or reduce it before it reaches us.”**

The originating network remains in control of what action it takes. An Assistance Request is a request for cooperation, not remote control of somebody else's router.

## Why this matters

The Internet is asymmetric during attacks. A victim may need substantial bandwidth and infrastructure merely to receive and discard malicious traffic. The sender's network may be in a much better position to stop that traffic before it consumes downstream links and victim resources.

Today, cooperation between networks often requires manual abuse reports, support tickets, telephone calls, specialist DDoS services or operator-level routing mechanisms. TaraSec is designed to make source-side cooperation fast, machine-readable and automatable.

## Similar approaches — and how TaraSec differs

| Existing approach | Key difference from TaraSec Assistance Request |
| --- | --- |
| **DDoS scrubbing services** | Protect the victim by absorbing and filtering attack traffic; TaraSec asks the originating network to suppress harmful traffic closer to the infected unit. |
| **BGP FlowSpec** | Distributes traffic-filtering rules through network infrastructure; TaraSec communicates an authenticated request and supporting information to the network responsible for the source, which remains free to choose its response. |
| **RTBH (Remote Triggered Black Hole)** | Triggers relatively coarse upstream traffic dropping; TaraSec is intended to support targeted, accountable mitigation by the network that can identify and control the source unit. |
| **ISP abuse reporting / abuse desks** | Reports malicious activity for human investigation; TaraSec aims to make this cooperation machine-to-machine and near-real-time. |
| **MANRS / operator coordination** | Establishes norms and processes for networks to cooperate on Internet security and routing incidents; TaraSec provides a technical mechanism for automated cooperation on harmful traffic. |
| **Firewall / IPS / DDoS appliance** | Protects its own network by blocking incoming traffic; TaraSec additionally lets it ask the sender's network to help stop sending that traffic. |

**Existing DDoS systems can move filtering upstream. TaraSec proposes also moving the information upstream — allowing the network that actually controls the offending unit to participate in stopping the traffic.**

## Security cooperation should not be a premium feature

Some existing mechanisms are technically available to smaller ISPs. RTBH, for example, can be implemented using existing BGP-capable routing infrastructure. BGP FlowSpec can also distribute mitigation rules where compatible equipment and operational expertise are available.

However, sophisticated automated DDoS mitigation and large-scale scrubbing are commonly provided through specialist infrastructure or commercial services. Smaller and lower-cost network operators may not have large scrubbing capacity, a dedicated security operations centre, expensive threat-intelligence subscriptions or engineers managing mitigation policies around the clock.

Yet a small originating ISP may possess something a remote mitigation provider does not: **direct control of the connection from which the harmful traffic originates.**

TaraSec Assistance Request is therefore designed so that participation does not depend on operating a huge mitigation network. A relatively small operator should be able to receive an authenticated request, identify traffic originating from its own network, and apply an appropriate local policy — for example rate limiting, temporary isolation or notification of the customer.

The goal is to move part of Internet defence away from **“Who can afford the biggest mitigation infrastructure?”** and toward **“Who is best positioned to stop the harmful traffic?”**

## Typical flow

1. A receiving network detects an attack, severe anomaly or unusually high load.
2. TaraSec correlates the traffic with the reported source identity and supporting threat information.
3. The receiving network issues an Assistance Request toward the responsible originating network.
4. The originating router authenticates the request and verifies that it concerns traffic it can identify and control.
5. According to its own policy, the originating network can suppress, rate-limit, isolate or otherwise restrict the traffic.
6. The action and outcome can be reported back so that both sides know whether assistance was provided.
7. When the incident ends or the unit is remediated, restrictions can be reassessed and removed.

## Not remote control of another network

An Assistance Request does not give an outside network authority to configure or control the originating router.

The originating network independently decides what to do based on factors such as:

- confidence in the source-unit identification;
- severity and number of reports;
- local policy;
- current network load;
- AI threat assessment;
- whether the unit is already known to be infected;
- whether the request is authenticated and authorized;
- whether the request is a real incident or a controlled TaraSec test.

This preserves network autonomy while still making cooperation automatic where both parties choose to support it.

## Why stable identity helps

Attack traffic is often hidden behind NAT, changing ports, DHCP addresses or roaming connections. An Assistance Request becomes much more useful when the originating side can resolve the observed tuple to an anonymized stable unit identity:

`ownerId + owner_generated_unit_id`

The victim does not need to know who the person behind the unit is. The originating network only needs to know which of its own units is responsible for the traffic.

## Possible source-side responses

An originating network could choose to:

- temporarily rate-limit the unit;
- block traffic toward the requesting destination;
- restrict selected protocols or ports;
- isolate the unit into a remediation network;
- require user acknowledgement through the TaraSec app;
- notify the owner that a unit appears compromised;
- stop tagged traffic while allowing known-safe traffic;
- request additional evidence before acting.

TaraSec should allow these policies to evolve rather than prescribing one universal response.

## Protection against abuse

Because an Assistance Request can affect another network's traffic, it must be harder to abuse than an ordinary report. Production implementations should include authenticated network identities, authorization, replay protection, rate limits, request IDs, audit history and clear separation between real incidents and test traffic.

The TaraSec app's planned live validation function can exercise Assistance Request end-to-end, but only for an already authorized unit/network and under strict test limits. A paid test can help cover infrastructure costs and discourage casual abuse, but payment should never replace technical authorization.

## Operational value

At scale, Assistance Request can reduce the amount of attack traffic carried across the Internet rather than merely increasing the amount of traffic that victims successfully discard.

Potential benefits include:

- earlier suppression of distributed attacks;
- reduced victim bandwidth and infrastructure costs;
- faster containment of compromised customer devices;
- practical participation by smaller ISPs and network operators;
- cooperation between ISPs, enterprises, hosting providers and security services;
- automated escalation during high-load events;
- evidence that a source network responded to a verified request;
- a practical mechanism for moving cyber defence closer to the source of harmful traffic.

## Key idea

**A firewall should not only be able to say “I blocked an attack.” TaraSec lets it also ask the source network: “Can you stop sending it?”**
