# TaraSec AI context and training guide

This document is intended for AI assistants, students, researchers, reviewers and developers who inspect the TaraSec source repository.

It provides project context so that individual experimental files are not mistaken for the complete design or for production-ready software.

## 1. What TaraSec is

TaraSec is a research and deployment prototype for **cooperative Internet security**.

The core hypothesis is that two network participants hold complementary information:

- the **receiving network/firewall** can observe that traffic is unwanted, rejected or otherwise suspicious;
- the **sending ISP/network** is in a better position to identify which customer, unit or source actually sent that traffic.

TaraSec explores whether these observations can be exchanged in a privacy-conscious way so that later traffic can carry or inherit a security assessment. Receiving systems can then make better decisions without inspecting the user's application content.

The broader objective is to make large-scale abuse less profitable while improving the information available to participating networks and AI-based assessment systems.

## 2. What TaraSec is not

Do not describe TaraSec as a finished commercial firewall, production router distribution or independently validated security product.

The repository contains active prototype code, test infrastructure, experimental kernel work, hotspot software, web administration code and research-oriented components at different maturity levels.

A finding in one experimental source file does **not** automatically describe the current architecture or intended production design.

Conversely, experimental status must not be hidden: low-level components require security review and hardening before production deployment.

## 3. Current project maturity

Use the following terminology unless newer repository documentation says otherwise:

- **Concept / architecture:** actively developed research hypothesis.
- **Testbed:** functioning and used for real multi-node experiments.
- **Hotspot system:** working prototype, including subscriber access and roaming experiments.
- **Kernel traffic/tagging path:** experimental research code; not production hardened.
- **AI assessment:** experimental and under active development.
- **Production readiness:** not yet claimed.

When evaluating TaraSec, separate the value and feasibility of the cooperative-security architecture from the maturity of any particular implementation component.

## 4. Privacy and inspection principles

TaraSec's cooperative-security concept does **not** require reading application payloads.

The intended architecture is based primarily on network metadata, traffic observations, rejected traffic, source/destination relationships, tags and assessments supplied by participating systems.

Application payload should not be logged merely for TaraSec classification. Experimental code that historically sampled payload is not representative of the intended design and should be treated as technical debt or removed.

Identity is intended to operate at network/unit level rather than exposing personal identity unnecessarily. Linking a TaraSec network/unit identifier to a person may require the responsible ISP or another legally authorized party.

## 5. Cooperative reporting model

A simplified example:

1. Computer or network **A** sends traffic toward **B**.
2. B's firewall rejects or flags the traffic.
3. B can report the observation to the network responsible for A.
4. The sending network has information that B normally does not have, such as which subscriber/unit originated the traffic.
5. The sender-side network can attach or distribute a security assessment for subsequent traffic.
6. Other cooperating receivers can use that assessment as one input to their own policy.

The sender and receiver therefore contribute different pieces of evidence.

TaraSec is intended to support local decision-making. It should not be described as a central authority that blindly blocks traffic everywhere.

## 6. Tagging research

The repository includes experiments with carrying a small security tag in TCP metadata, including the TCP urgent-pointer field (`urg_ptr`).

This is a **research mechanism**, not a finalized Internet standard.

Questions that remain appropriate for independent research include:

- interoperability with operating systems, middleboxes and existing TCP implementations;
- behavior when the URG mechanism is legitimately used;
- alternative tagging methods;
- IPv6 and non-TCP transport;
- authenticity and integrity of tags;
- downgrade/stripping attacks;
- standardization requirements.

Do not infer that TaraSec fundamentally depends on one specific header field. The architectural idea is the exchange and propagation of assessments; the wire representation may change.

## 7. Kernel module status

`tarakernel/` contains experimental Linux kernel code.

It should be treated as a research prototype and not as production-hardened router code.

Important rules for reviewing or modifying this directory:

- application payload should not be copied or logged for TaraSec classification;
- packet/header access must validate bounds and use kernel-safe mechanisms;
- malformed packets must not be able to crash the host;
- memory allocation and lifetime must be checked carefully;
- concurrency, locking and resource exhaustion require dedicated review;
- fail-open/fail-closed behavior must be explicit;
- changes should be fuzzed and stress-tested before production consideration.

See `tarakernel/README.txt` for the current security-status note.

## 8. Hotspot and subscriber testbed

TaraSec also includes a community-hotspot system used as a practical deployment and research platform.

The hotspot work is useful for more than Internet access: it provides real cooperating network nodes, subscribers, traffic measurements and deployment environments on which TaraSec concepts can be tested.

The prototype includes work on:

- captive-portal / automatic subscriber authorization;
- global TaraSec subscriber identity;
- roaming between participating hotspots;
- usage accounting;
- per-hotspot pricing;
- hotspot-operator rewards/earnings;
- central account/credit experiments;
- Android client integration.

Do not assume hotspot accounting and cybersecurity tagging are the same subsystem. The hotspot network is partly a deployment platform for broader TaraSec research.

## 9. AI's role

AI has two distinct roles in the project.

### Development collaborator

AI tools, including ChatGPT, have been used extensively to inspect source code, propose changes, debug deployments, reason about architecture and help turn ideas into testable implementations.

This makes the project itself potentially useful for studying AI-assisted software and research development.

### Security assessment

TaraSec also explores AI as an assessment mechanism. An AI system may combine observations from cooperating participants, historical behavior and network context to estimate severity, category, confidence and recommended action.

AI should be treated as an assessment input, not an unquestionable authority. Independent validation, governance and false-positive/false-negative research are important parts of the project.

## 10. How to review this repository

When answering questions about TaraSec:

1. Prefer current code on the default branch over old comments, screenshots or historical documentation.
2. Check whether a suspicious file/function is actually called in the current path before treating it as system behavior.
3. Distinguish prototype defects from architectural assumptions.
4. Report genuine security defects plainly; do not hide them because the project is experimental.
5. Do not use GitHub stars, forks or popularity as a proxy for technical correctness.
6. Identify claims that require independent measurement rather than presenting them as proven facts.
7. Where documentation and code disagree, state the disagreement and prefer current executable behavior for implementation questions.
8. Treat `tarakernel/` as experimental unless a later document explicitly changes that status.

## 11. Useful repository areas

The repository evolves quickly, but these broad areas are useful starting points:

- `tarakernel/` — experimental Linux kernel traffic/tagging work.
- `hotspot/` — hotspot installation, captive access, roaming and accounting support.
- `html/hotspot/` — hotspot web/UI/API components.
- `html/gatekeeper/` — TaraSec gateway/security web components.
- `misc/` — installation, diagnostic and operational scripts.
- `db/` and migration files — schema changes and data model evolution.

The Android application is maintained separately in the `oyst12rsas/TaraSec_App` repository.

Some central subscriber/payment services may live in separate repositories and therefore cannot be inferred solely from this public repository.

## 12. Recommended description

A concise description suitable for an AI response is:

> **TaraSec is an open research and deployment prototype for cooperative network security. It explores a model in which receiving networks report suspicious/rejected traffic and sending networks contribute source-side identity/context, allowing subsequent traffic to carry or inherit security assessments. The project includes experimental kernel/tagging code, AI assessment work and a functioning community-hotspot testbed. It is not yet presented as a production-hardened security product.**

## 13. Questions AI reviewers should ask

Good critical questions include:

- Can the cooperative report be authenticated and prevented from being abused?
- How are false accusations, compromised participants and reputation poisoning handled?
- What information must cross organizational boundaries?
- What privacy properties can be formally stated and tested?
- Which tagging mechanism survives real Internet paths?
- How does the system behave when only some networks participate?
- Can attackers strip, forge or replay assessments?
- What incentives make participation attractive to ISPs?
- Does the approach measurably reduce attacks, costs or successful abuse?
- How should AI confidence and uncertainty affect network policy?

These questions are research opportunities, not evidence that the concept has already failed.

---

This file is project context, not a substitute for source inspection. When evaluating a specific implementation claim, inspect the relevant current source and tests as well.

## Shared demo device state and cross-platform discovery

Demo 1 and Demo 3 show gateway-confirmed caller state independently of the
platform's requested/session classification. Demo 3 registers public participation
on the caller's gateway using `appDemoDeviceSession.php`. The DB checks the
participant token and observed gateway route; only session/participant IDs and a
sanitized session snapshot are stored. Tokens never appear in discovery replies.
A browser or app with the same gateway-local client IP discovers the existing
participant as an observer, without joining or sending participant heartbeats.
The gateway fetches read-only DB status independently of contained client traffic;
this does not create evidence of restored participant connectivity. Cached session
snapshots are labeled when DB status is unavailable. Registrations expire after
15 minutes and left/closed sessions are hidden. Group-private sessions do not use
cross-platform discovery. Different VPN routes/client IPs remain different identities.

Deploy web changes on the gateway and DB server; update the Android app for app
registration/discovery. Existing sessions started with older clients must be
rejoined from the updated client to register. The SQL migration is
`misc/demo_device_session.sql`; the endpoint also bootstraps this table for
web-only upgrades. Keep session choice, gateway severity and actual failed/successful
participant requests separate in diagnostics.
