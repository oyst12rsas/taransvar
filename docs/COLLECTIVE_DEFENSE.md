# Collective defense: the whole TaraSec chain

Recommended by AI; not yet reviewed or authorized by the TaraSec team.
Status figures were observed on a test node on 2026-11-24.

This document is for security professionals meeting TaraSec for the first time.
It explains what is new compared with tools you already know, the complete chain
from one blocked attempt to network-wide protection, the security level each node
must reach for the chain to work, and what is implemented today.

## 1. Why TaraSec is different

You already know the building blocks: firewalls and IPS, fail2ban, honeypots,
SIEMs, shared blocklists (Spamhaus, AbuseIPDB, CrowdSec), threat-intelligence
feeds (STIX/TAXII), ISP abuse desks, and upstream filtering (RTBH, BGP FlowSpec).
Each of these has the same limit: **the knowledge stays where the attack was
detected, or travels only as a list of IP addresses.** The next target starts from
zero, or from an IP list the attacker escapes by changing address.

TaraSec adds three things none of them combine:

1. **The report goes back to the source network.** The network that *sent* the
   traffic is the one that can find the actual device behind NAT, DHCP or a carrier
   address. TaraSec gives it a machine-readable report instead of an abuse email.
2. **The warning travels with the attacker's future traffic.** The source network
   can tag later traffic from that unit, so every receiver knows "already reported"
   without having to detect the attack again. Identity is
   `ownerId + owner-generated unit id`, not an IP address, so it survives address
   changes. (Tags carried in packets are experimental; see `AI_TRAINING.md`.)
3. **The receiver keeps its own policy.** A tag is information, not a verdict. Each
   network decides whether to allow, log, rate-limit, require stronger authentication
   or block.

| You know | What it does | What TaraSec adds |
|---|---|---|
| fail2ban / local firewall | Blocks an attacker on one host | Every participating node learns from that one block |
| Honeypot networks (DShield and similar) | Collect attack data for analysis | Detection triggers enforcement and source notification in near real time |
| CrowdSec / AbuseIPDB / Spamhaus | Share IP reputation | Resolves IP to a stable unit at the source; the source network can act and tag |
| Threat-intel feeds | Indicators for defenders | The *sending* network takes part, not just defenders |
| ISP abuse desks | Human, slow, per-incident | Machine-to-machine, cumulative across many reports |
| RTBH / FlowSpec / scrubbing | Filter traffic upstream | Moves the *information* upstream to whoever controls the device |

The short version: **today every target has to identify the same attacker again.
TaraSec lets one detection protect everyone and reach the network that can stop it.**
See also [Traffic tagging](factsheets/tagging.md) and
[Assistance request](factsheets/assistance-request.md).

## 2. The goal: attacking TaraSec networks must cost more than it gains

Most attacks are cheap and done at scale: scanners, brute force, botnets. Attackers
go where effort pays. If one attempt against *any* TaraSec node means that address,
VPS or infected device is reported, tagged, blocked across the network and pushed
toward clean-up at its source, then attacking TaraSec networks becomes a losing
investment, and attackers move to easier targets.

That deterrence only works if **almost every node is at the elevated security level**
(section 4). One weak node is a free practice target and undermines the message that
trying leads to losing infrastructure. Until many nodes take part, each node mainly
gains local protection and shared data; deterrence is the payoff at scale.

## 3. The chain, step by step

```
attacker ──► node honeypot / firewall
              │ 1 detect
              │ 2 classify (severity, tag_host)
              ▼
            3 report ──► DB server (syslogThreat)
                           │ 4 tag the source
              ┌────────────┼──────────────────────┐
              ▼            ▼                      ▼
     5 block locally   6 share with partners   7 notify source network
     (tarakernel)      (they block / watch)    (resolve to unit, tag future
                                                traffic, assistance request)
                           │
                           ▼
                    8 expire or reassess after clean-up
```

| Step | What happens | Component |
|---|---|---|
| 1. Detect | Login attempt on the honeypot (port 22, decoys, closed real port) or rejected firewall traffic | `tarasec_ssh_honeypot.py`, `firewall.sh` log chains |
| 2. Classify | Any password attempt: severity 7, `action: tag_host`; typed commands: severity 8 | Honeypot event JSON |
| 3. Report | Events forwarded to the node's DB server | `setup_backoffice_ai.sh sensor` (TCP/5514), taralink (UDP/514) |
| 4. Tag | Stored as a threat and linked to the source unit | `syslogThreat`, `hackReport` tables |
| 5. Block locally | Tagged source dropped on this node, including the real SSH port | tarakernel, taralink |
| 6. Share | Partner nodes receive the tag and apply their policy | partner feed, NetBird |
| 7. Notify source | Source network resolves to a unit, tags its later traffic, can receive an assistance request | tagging, assistance request |
| 8. Expire | Blocks expire; a cleaned unit is reassessed instead of condemned forever | DB policy |

## 4. The elevated security level (every node)

The chain is only as strong as its weakest node. A node is at the elevated level when
**all** of these are verified on the node itself, not just configured:

1. Admin SSH needs key **and** password; no root login.
2. Admin SSH is reachable only from owner-chosen addresses.
3. The admin port is closed until a Google + Authenticator approval opens a short window.
4. Real SSH is on a private port; port 22 and decoy ports run the honeypot.
5. **Honeypot events reach the DB server** (step 3 works end to end).
6. Tagged sources are blocked locally (step 5 works).
7. tarakernel rebuilds for new kernels (DKMS), and protection comes back after reboot.
8. A recovery console exists, and the node reports its actual level.

Steps 1–4 and 7 are in [Post-install hardening](POST_INSTALL_HARDENING.md) and `install.sh`.

## 5. What works today (test node, 2026-11-24)

| Step | Status | Evidence |
|---|---|---|
| 1. Detect | Works | Honeypot logged every test attempt, including on the closed real port |
| 2. Classify | Works | Attempts emitted as severity 7 `tag_host` |
| 3. Report | **Not connected** | `DBSERVER` unset, so events stayed in the local journal |
| 4. Tag | **Nothing arrives** | `syslogThreat`, `hackReport`, `fw_rejected` had 0 rows |
| 5. Block locally | Untested | tarakernel loaded and taralink running, but no tag reached them |
| 6. Share | Untested | Depends on 3–5 |
| 7. Notify source | Research prototype | Packet tagging is experimental (`AI_TRAINING.md`) |
| 8. Expire | Untested | |

The installer and checklist treated "honeypot listening" as done, so the missing
link was invisible. A node that detects but does not report is **not** at the
elevated level and must say so.

## 6. Risks to design for

* **Report poisoning.** Once reports cause blocking, fake reports become an attack
  (getting innocent addresses blocked). A completed TCP honeypot session is hard to
  spoof; UDP/514 and UDP/5551 accept anything. Reports need authentication,
  per-source limits and expiry.
* **False positives.** A mistyped password on a decoy port tags the source. Owners'
  own addresses should be recognized, and every block must expire or be releasable.
* **Address rotation.** Blocking an IP costs an attacker little. The real cost comes
  from the source network acting on the unit (step 7) and from speed: the tag must
  spread before the attacker reaches the next node.
* **Honeypot exposure.** The honeypot parses attacker input on public ports and
  currently runs as root. It should run unprivileged.

## 7. Roadmap

1. Prove the full chain on one node: hit from a non-owner source, row in
   `syslogThreat`, tag, local block on the real port too, partner block, expiry.
   Fix every shared routine that fails on the way.
2. Make the elevated level the installer's default, with reporting connected, and
   require an explicit choice to leave anything out.
3. A node self-check that reports its verified level (step by step) to the app and
   to partners, as the basis for partner certification.
4. Authenticate reports and rate-limit them; run the honeypot unprivileged.
5. Roll out to existing nodes and publish how many are at the elevated level.
