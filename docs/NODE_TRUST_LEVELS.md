# Node trust levels (design note)

**Status: proposed. Not implemented.** This note records the intended trust model
for newly installed nodes, so the plan is not mistaken for current behaviour.
Each section marks what exists today and what still has to be built.

## Goal

A new server should be able to install, self-register and join the encrypted
management network without bothering the person installing it. It starts at the
lowest trust level, like the first step of a certification program, and is
raised later by approval.

## Current behaviour (verified on main, 2026-11-23)

- **No trust levels exist.** Sender trust is all or nothing and keyed on the
  sender's IP address.
- **DB server:** `html/script/requestAssistance.php` accepts threat tags only
  from a sender whose IP is in the `partnerRouter` table
  (`senderIsRegisteredPartner`). Everyone else gets `403 unregistered partner`.
  An unregistered node therefore cannot contribute tags at all, on demo or
  production systems.
- **Routers and servers:** `html/script/report.php` accepts hack reports only
  from a known partner router or a configured global DB server.
  `html/script/partnerRequest.php` accepts only configured global DB servers.
- **Infection clears (#328):** `taralink/module_hack_reports.c` honours a remote
  clear only when it comes directly from a configured global DB with an explicit
  `CLEAR:` reason. Forwarded partner observations with severity 0 are evidence
  only, never a clear.
- **Registration:** installers self-register with `enroll_google_manager.py
  --auto` (no secret, optional Google email sent as a SHA-256 hash). The
  tarasec.org endpoint `ops/agent/enroll.php` still rejects this with HTTP 403
  and accepts a new node only with a one-time owner secret.
- **Encryption:** each node joins NetBird automatically at install. WireGuard
  keys are generated and exchanged without user steps, and new peers land in an
  isolated bootstrap group (`html/script/hotspotNetbirdEnroll.php`).

## Proposed levels

| Level | How a node gets there | What its reports may do |
|---|---|---|
| **Pending** | Self-registration at install (node token, optional email hash) | Threat tags may trigger blocking on **demo** routers and servers only |
| **Approved** | A TaraSec operator approves the node on the DB server | Tags may influence enforcement in production, subject to each receiver's local policy |
| **Owner-verified** | Owner links the node to a Google account (one-time secret today; see below) | Same as Approved, plus owner management in the app |

## Rules

1. **Pending nodes can only add protection.** Their tags may cause blocking on
   demo routers and servers.
2. **Anything that reduces protection needs approval.** A pending node can't
   whitelist a source, clear an infection, lift a block or override a local
   rule.
3. **Absence is not permission.** A missing tag, or severity 0, from a pending
   node must never count as permission to allow traffic.
4. **Production requires approval.** The DB server must approve a node before
   its tags can influence enforcement outside demo systems.
5. **Receivers decide.** The receiving router or server always applies its own
   configured policy, whatever level the sender has.
6. **No self-promotion.** A node can't grant itself demo or production
   privileges. Its level is recorded on the DB server, not claimed in its
   requests.

## Linking the installer's Google account

The installer runs on the server, not in the browser, so tarasec.org cannot see
which signed-in Google session belongs to a new node. Something has to link
them:

- **One-time secret (exists):** the owner signs in at
  `https://tarasec.org/ops/agent/install.php`, generates a secret and pastes it
  at the server console. The server derives the verified owner from it.
- **Device code (proposed):** the installer prints a short URL with a code. The
  owner opens it while signed in with Google, and the server binds that session
  to the node. Nothing is typed into the server.
- **Typed email (current `--auto`):** only a claim. Its hash identifies who
  *should* manage the node, but it is not verified until that account signs in.

## To build

- [ ] `ops/agent/enroll.php` (tarasec.org, not in this repository): accept
  self-registration without a secret and record the node as **pending**.
- [ ] DB server: store a trust level per node and an operator approval action.
- [ ] DB server: identify senders by node credential, not only by IP address,
  so a level stays attached to the node when its address changes.
- [ ] `requestAssistance.php`: accept tags from pending nodes, mark them, and
  distribute them to demo receivers only.
- [ ] Receivers: refuse whitelist, lift-block, clear and override requests that
  originate from a pending node; treat severity 0 or a missing tag as no
  information.
- [ ] Optional: device-code flow for linking the owner's Google account.
- [ ] Tests for each rule above, especially rules 2, 3 and 6.

## Open questions

- Who approves a pending node, and from where: the Ops page or the app?
- Should a pending node's tags expire if it is never approved?
- Which "demo" receivers qualify: those with a demo configuration
  (`gatewayDemoConfiguration`), or an explicit opt-in flag?
