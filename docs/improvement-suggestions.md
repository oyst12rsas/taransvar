# TaraSec / Taransvar improvement suggestions

This document is the review backlog for ideas that arise during installation, field testing, app work, security review, payment integration, hotspot deployment, and protocol experiments.

The purpose is to keep potentially useful improvements out of transient chat history and make them easy to review before they become product decisions.

## How to use this list

Each suggestion should have:
- **Status**: proposed, investigating, accepted, implemented, rejected, or deferred.
- **Area**: hotspot, app, protocol, DB, payments, privacy, operations, etc.
- **Why**: the problem/opportunity it addresses.
- **Suggested change**: concise implementation direction.
- **Risk / trade-off**: compatibility, privacy, security, operational or UX concerns.
- **Decision needed**: whether the project owner needs to choose between alternatives.
- **Origin**: installer/field test, user suggestion, code review, research, etc.

Low-risk bug fixes and obvious implementation errors do not need to wait for review; they should normally be fixed directly and noted in the relevant commit/PR. This backlog is primarily for improvements that may affect design, behavior, architecture, privacy, incentives, or future compatibility.

---

## Open suggestions

### IMP-001 — Separate technical hotspot registration from owner claiming
- **Status:** accepted / implementation in progress
- **Area:** hotspot, DB, identity
- **Why:** A hotspot should be able to come online and receive a stable identity without requiring the owner to complete personal/commercial details during installation.
- **Suggested change:** Generate a local cryptographic hotspot identity, register its public identity automatically with the global DB, then allow the owner to claim/enrich the hotspot later.
- **Risk / trade-off:** Requires secure recovery/rotation procedure for lost hotspot credentials.
- **Decision needed:** Define owner-claim verification flow later.
- **Origin:** hotspot installer design.

### IMP-002 — Heartbeat must not be required for local hotspot operation
- **Status:** accepted / implementation in progress
- **Area:** hotspot, operations
- **Why:** Global DB outages or Internet interruptions must not take down local hotspot service.
- **Suggested change:** Registration and heartbeat are best-effort; failed heartbeats retry later via systemd timer while captive portal/networking continue normally.
- **Risk / trade-off:** Global map/status may temporarily show a hotspot offline or stale.
- **Decision needed:** Choose stale/offline thresholds for the global map.
- **Origin:** resilience review.

### IMP-003 — Owner-controlled geographic precision
- **Status:** accepted / implementation in progress
- **Area:** hotspot, privacy, mapping
- **Why:** A global hotspot map is valuable, but owners may be willing to share different levels of location detail.
- **Suggested change:** Store supplied location precision separately from permitted public-map precision. Support none, country, region, city, postcode, approximate, and exact.
- **Risk / trade-off:** Exact public coordinates can reveal home/business location; UI must make this explicit.
- **Decision needed:** Decide default public precision after owner supplies a location; safest default is none until explicitly selected.
- **Origin:** hotspot mapping discussion.

### IMP-004 — Do not infer exact owner location silently
- **Status:** proposed
- **Area:** privacy, mapping
- **Why:** IP geolocation or device-derived position may be useful operationally but can create privacy surprises if treated as owner-provided location.
- **Suggested change:** Keep observed/inferred network geography separate from owner-confirmed geography. Never publish inferred coordinates merely because the hotspot contacted the DB.
- **Risk / trade-off:** Slightly less complete map until owners opt in.
- **Decision needed:** Whether coarse inferred country/region may be used internally for deployment analytics.
- **Origin:** privacy review.

### IMP-005 — Map only active/recently-seen hotspots by default
- **Status:** proposed
- **Area:** mapping, operations
- **Why:** A map filled with abandoned installations would be misleading.
- **Suggested change:** Public map defaults to hotspots seen within a configurable period, with historical/offline deployments available separately.
- **Risk / trade-off:** Intermittently connected hotspots may disappear temporarily.
- **Decision needed:** Choose default online/recent window.
- **Origin:** registry design review.

### IMP-006 — Capability discovery in hotspot registry
- **Status:** accepted / implementation in progress
- **Area:** protocol, hotspot, app
- **Why:** TaraSec peers should know which hotspots support tagged traffic, Research, payments, captive portal, and future protocol versions.
- **Suggested change:** Heartbeats publish versioned capability metadata so peer discovery does not depend solely on IP/port assumptions.
- **Risk / trade-off:** Capabilities are claims until exercised/verified.
- **Decision needed:** Later define signed/verified capability status for peer-to-peer use.
- **Origin:** tagged-traffic/NAT discussion.

### IMP-007 — Registry credential rotation and recovery
- **Status:** proposed
- **Area:** security, operations
- **Why:** Hotspot disks can fail, devices can be replaced, and credentials may need revocation.
- **Suggested change:** Add an authenticated rotate/recover flow that does not allow a duplicate registration to silently replace an existing hotspot identity.
- **Risk / trade-off:** Recovery must balance usability against hotspot hijacking risk.
- **Decision needed:** Define recovery proof: owner account, existing private key, administrator approval, or combination.
- **Origin:** security review of automatic registration.

### IMP-008 — Separate private location from public map representation
- **Status:** proposed
- **Area:** privacy, mapping
- **Why:** Even if an owner supplies exact coordinates for dispatch/support, they may want only city-level visibility publicly.
- **Suggested change:** Generate/publicly return a representation based on `publicLocationPrecision`, not raw stored latitude/longitude. Approximate map points should be deliberately coarsened rather than exposing exact coordinates to the frontend.
- **Risk / trade-off:** Requires server-side map serialization logic.
- **Decision needed:** Define approximate/coarsening radius by precision level.
- **Origin:** mapping implementation review.

### IMP-009 — Installer diagnostic bundle
- **Status:** proposed
- **Area:** hotspot, support
- **Why:** New hotspot owners should not need to understand Linux networking to report installation failures.
- **Suggested change:** Installer writes a concise redacted diagnostic report covering interfaces, AP capability, services, routes, DHCP, openNDS and registry status, excluding secrets/private keys/tokens.
- **Risk / trade-off:** Must carefully redact public/private credentials and personal information.
- **Decision needed:** Whether diagnostics may be uploaded automatically with explicit owner approval.
- **Origin:** installer simplification work.

### IMP-010 — Treat field-install discoveries as generic fixes
- **Status:** accepted
- **Area:** development process
- **Why:** Problems discovered by one installer should improve the installer for everybody rather than becoming machine-specific workarounds.
- **Suggested change:** Prefer auto-detection, idempotent configuration and actionable diagnostics; fix obvious low-risk installer bugs directly, while putting design-impacting changes in this backlog for review.
- **Risk / trade-off:** Automatic fixes must remain conservative where hardware/network intent is genuinely ambiguous.
- **Decision needed:** None for ordinary bug fixes.
- **Origin:** deployment workflow discussion.

---

## Review convention

When an item is reviewed, change its status and add a short **Decision** line with the date/choice. If implementation becomes substantial, link the corresponding GitHub issue or PR from the item rather than letting this document become a detailed engineering specification.
