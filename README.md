# Taransvar / TaraSec

This open-source project helps people set up a **TaraSec Hotspot** or **TaraSec Router** and contains the evolving TaraSec cooperative-security research prototype.

The easiest way to get started is to tell ChatGPT:

> **I want to install a TaraSec Hotspot (or TaraSec Router) from GitHub repository oyst12rsas/taransvar.**

ChatGPT can explain what TaraSec does, what benefits it may provide, check what your device requires, and guide you through the installation and setup process.

## AI, student and reviewer context

Before drawing conclusions from individual source files, read **[AI_TRAINING.md](AI_TRAINING.md)**. It explains the TaraSec concept, the current prototype status, the difference between experimental kernel code and the broader architecture, privacy principles, the hotspot testbed, and how AI/reviewers should interpret the repository.

In particular, TaraSec is currently a **research and deployment prototype**, not a production-hardened security product. Experimental defects should be reported plainly, but they should also be distinguished from the architectural hypotheses being tested.

## Deployment improvements

AI sessions should implement and test routine improvements within the agreed deployment scope, use failures to fix shared routines, and contribute verified changes. See [AGENTS.md](AGENTS.md) for the workflow and access/recovery boundaries.

## NetBird and TaraSec service connectivity

NetBird is the encrypted overlay used by the current TaraSec deployment for communication between routers, nodes and DB servers: administration, status/security reporting, coordination and access to shared services. Demo 4 also uses it for selective partner routing; it is not NetBird's only TaraSec role.

Local AP networking, DHCP/NAT and openNDS create the captive-portal hotspot. NetBird does not create that hotspot and must not replace the ordinary customer Internet uplink.

For a router or hotspot joining the current TaraSec network, include NetBird enrollment. An alternative transport is acceptable only when explicitly configured and verified to reach the required TaraSec services. A working local portal alone does not establish cooperative-security connectivity.

See **[AI_INSTALL_GUIDE.md](AI_INSTALL_GUIDE.md#netbird-and-tarasec-service-connectivity)** for enrollment and completion checks.

## Demo design documents

- **[Demo 4: NATed Hotspot Contribution Through a TaraSec VPS Partner](docs/DEMO4_WHITEPAPER.md)** — proposed use of NetBird and policy routing so only tagged traffic to registered TaraSec participants uses the overlay.

You can also learn more at **https://tarasec.org**.
