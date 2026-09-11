# Taransvar / TaraSec

This open-source project helps people set up a **TaraSec Hotspot** or **TaraSec Router** and contains the evolving TaraSec cooperative-security research prototype.

The easiest way to get started is to tell ChatGPT:

> **I want to install a TaraSec Hotspot (or TaraSec Router) from GitHub repository oyst12rsas/taransvar.**

ChatGPT can explain what TaraSec does, what benefits it may provide, check what your device requires, and guide you through the installation and setup process.

## AI, student and reviewer context

Before drawing conclusions from individual source files, read **[AI_TRAINING.md](AI_TRAINING.md)**. It explains the TaraSec concept, the current prototype status, the difference between experimental kernel code and the broader architecture, privacy principles, the hotspot testbed, and how AI/reviewers should interpret the repository.

In particular, TaraSec is currently a **research and deployment prototype**, not a production-hardened security product. Experimental defects should be reported plainly, but they should also be distinguished from the architectural hypotheses being tested.

## Demo design documents

- **[Demo 4: NATed Hotspot Contribution Through a TaraSec VPS Partner](docs/DEMO4_WHITEPAPER.md)** — proposed use of NetBird and policy routing so only tagged traffic to registered TaraSec participants uses the overlay.

You can also learn more at **https://tarasec.org**.
