# TaraSec Fact Sheet — Traffic Tagging

## What it is

TaraSec traffic tagging lets a network mark traffic from a unit that has previously been reported as probably involved in malicious activity. The tag travels with the traffic so that receiving networks do not have to rediscover the same risk from scratch.

The purpose is not to block all tagged traffic. A tag is information. The receiving network remains free to decide what additional security, monitoring, rate limiting or blocking it wants to apply.

## Why tagging matters

Traditional Internet security is largely local: one firewall detects an attack, blocks it, logs it and moves on. The next target has to detect the same attacker again.

TaraSec turns firewalls and other security systems into sensors in a larger cooperative network. When a receiving firewall identifies malicious behaviour, the originating network can be informed. If that source unit later sends more traffic, its network can tag that traffic to tell future recipients that the unit has already been reported.

This changes the economics of cybercrime. Repeatedly malicious units become increasingly visible across networks instead of receiving a fresh start every time they contact a new target.

## Stable identity instead of changing IP addresses

IP addresses and source ports are transport information, not reliable long-term identities. NAT, roaming, DHCP and carrier networks can change them frequently.

TaraSec therefore aims to associate reports with an anonymized identity created by the unit owner or originating network:

`ownerId + owner_generated_unit_id`

The observed IP address and port are still retained because they are needed to resolve the traffic to the correct unit, especially through NAT. Once resolved, the owner/unit identity provides the stable reference used for threat assessment.

## How it works

1. A unit sends traffic to another network.
2. A firewall or other sensor identifies behaviour that appears malicious.
3. The receiving side reports the incident toward the originating network and TaraSec threat infrastructure.
4. The originating network resolves the reported IP/port to the responsible local unit when possible.
5. Future traffic from that unit can be tagged with TaraSec threat information.
6. Receiving networks use the tag as an additional security signal.
7. Elaborated threat information can be requested when the receiving side needs more detail.
8. The unit can later be remediated and reassessed instead of being permanently condemned by an old report.

## A tag is not a verdict

A properly tagged packet is not automatically dangerous. In fact, tagging can make traffic safer because the receiving side is being given information that would otherwise be hidden.

A receiving network may choose to:

- allow the traffic normally;
- increase logging or inspection;
- apply rate limits;
- isolate the session;
- require stronger authentication;
- block only specific services;
- reject the traffic entirely.

TaraSec is therefore compatible with many security models, including zero-trust approaches. TaraSec supplies additional context; it does not dictate the recipient's security policy.

## Privacy principle

The owner-generated unit identity is intended to be anonymized. TaraSec does not require the global system to know the natural person behind a unit. Linking an anonymized unit identity to personal subscriber information can remain with the ISP, enterprise or owner and be subject to the applicable legal process.

## What tagging enables

At scale, traffic tagging can support:

- global AI threat assessment based on stable unit identity;
- faster recognition of repeated malicious behaviour;
- source-side remediation instead of endless destination-side blocking;
- cooperative handling of compromised IoT devices, PCs, servers and gateways;
- safer automated responses because recipients know why traffic has been marked;
- reassessment and removal of threat status after remediation;
- cross-network accountability without requiring a single central firewall policy.

## Key idea

**Today's Internet repeatedly asks every target to identify the same malicious traffic. TaraSec lets networks share that knowledge and carry it forward with the traffic.**
