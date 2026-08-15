# TaraSec Fact Sheet — Assistance Request

## What it is

A TaraSec Assistance Request lets a network under attack or abnormal load ask the originating network to help reduce the harmful traffic at its source.

Instead of forcing the victim to absorb, classify and block every unwanted packet locally, TaraSec enables the victim to say, in effect:

**“Traffic associated with this reported unit is contributing to an attack or excessive load. Please help stop or reduce it before it reaches us.”**

The originating router remains in control of what action it takes.

## Why this matters

The Internet is asymmetric during attacks. A victim may need large amounts of capacity merely to receive and discard malicious traffic. The sender's network is often in a much better position to stop that traffic before it consumes downstream links and victim resources.

Today, cooperation between networks often requires manual abuse reports, support tickets, telephone calls or emergency filtering. TaraSec is designed to make this cooperation fast, machine-readable and automatable.

## Typical flow

1. A receiving network detects an attack, severe anomaly or unusually high load.
2. TaraSec correlates the traffic with the reported source identity and supporting threat information.
3. The receiving network issues an Assistance Request toward the responsible originating network.
4. The originating router verifies that the request concerns traffic it can identify and control.
5. According to its own policy, the originating network can suppress, rate-limit, isolate or otherwise restrict the traffic.
6. The action and outcome can be reported back so that both sides know whether assistance was provided.
7. When the incident ends or the unit is remediated, restrictions can be reassessed and removed.

## Not remote control of another network

An Assistance Request is a request, not a command that gives outsiders control of a router.

The originating network should independently decide what to do based on factors such as:

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
- cooperation between ISPs, enterprises, hosting providers and security services;
- automated escalation during high-load events;
- evidence that a source network responded to a verified request;
- a practical mechanism for moving cyber defence closer to the source of harmful traffic.

## Key idea

**A firewall should not only be able to say “I blocked an attack.” TaraSec lets it also ask the source network: “Can you stop sending it?”**
