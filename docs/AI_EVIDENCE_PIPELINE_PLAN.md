# AI evidence pipeline: node agent, DB server and Flowise (plan)

Status: **draft plan, nothing implemented.**
Recommended by AI; not yet reviewed or authorized by the TaraSec team.

## Why

The node operations agent currently sees disk, memory, service state and log
*sizes* only (`operations_agent.py` snapshot, `operations_diagnostics.py`
catalog). It never sees what happened. On claude3 (2026-11-26) none of the
following reached any model:

- sshd accepted/failed logins and `MaxAuthTries` disconnects
- port-22 honeypot start and connection events
- an unconfirmed SSH port migration being rolled back by its timer
- `tarasec-ssh-approval-poll` failing with DNS (`gaierror`) and connect timeouts

Meanwhile the DB server (`dbserver1`) is meant to build the
global threat picture (botnets, false-positive clearing). It runs the same
codebase with `setup.isGlobalDbServer = 1`; nodes find it through
`setup.globalDb1ip` (Gatekeeper "Global DB 1") or legacy `DBSERVER` in
`/etc/tarasecfw.conf`. It already receives:

- the minute status JSON from `crontasks.pl` and `hackReport` rows;
- honeypot/SSH events via rsyslog over TCP/5514 (disk-backed queue), stored
  raw in `syslog`, normalized into `syslogThreat`, with higher-severity
  login/command events queued for AI assessment.

So honeypot evidence already has a path. What does not: node operational
evidence (unit failures, approval-poll DNS/timeouts, SSH window and port
migration/rollback events, real-sshd accept/fail). claude3 itself has no
`DBSERVER` line and no TaraSec rsyslog forwarding configured, so even its
honeypot events from 2026-11-26 never reached dbserver1.

## Current AI paths

| Path | Runs on | Model transport | Evidence it gets |
|---|---|---|---|
| Operations agent | node | Flowise HTTPS (or direct OpenAI opt-in) | df, unit list, log size, pressure, fixed read-only diagnostics |
| Security/approval worker | node | approval API (no model) | `ssh_security_evidence.py`: SSH/firewall/honeypot *config* |
| Gateway AI | gateway | Flowise | `syslogThreat`/`hackReport` 7-day summaries from local DB |
| Global picture | `dbserver1` | — (queues events for AI assessment) | status JSON, hack reports, rsyslog `syslog`/`syslogThreat` |

## Target shape

```
node                                DB server                        Flowise VM (GPU, Ollama)
──────────────────────              ─────────────────────            ────────────────────────
fixed collectors ─► digest ──HTTPS─► ingest + store ──► correlate ──► global chatflow
operations agent ──────────────────────────────────────────────────► local ops chatflow
        ▲                                │
        └──── verdicts / FP clearances ◄─┘  (signed, typed; never commands)
```

Principles (carried over from existing code):

1. **Digests, not raw logs.** Collectors are fixed, root-owned, take no model
   arguments, and emit bounded JSON: counts, classes, timestamps, short
   redacted excerpts. Matches the "never send arbitrary logs/configs" rule.
2. **Pseudonymous.** Same rule as `worker_soc_events.pl`: unit IDs and external
   tuples only; no owner PII, no emails, internal LAN addresses masked.
3. **Model output is never authority.** DB-server verdicts sent back to nodes
   are typed records (e.g. `false_positive{report_id}`, `botnet_member{ip,
   confidence}`) that existing local policy decides how to apply. No commands.
4. **Fail visible.** Missing digest = unknown, never healthy.

## Phase 1: node collectors (this repo, node only)

Add fixed diagnostics to `operations_diagnostics.CATALOG` and to the per-run
snapshot where cheap:

| Name | Source | Output |
|---|---|---|
| `ssh_events` | journal of ssh/sshd, tarasec-ssh-honeypot, ssh-window units, port-rollback | 24h counts: accepted/failed by source class (allowlisted / NetBird / other), MaxAuthTries hits, honeypot connections, window opens/closes, migration/rollback/confirm events with timestamps |
| `recent_failures` | journal of each failed or restarting `tarasec*`/`taralink*` unit | last ≤20 lines per unit at priority ≤ err, redacted, ≤4 KB per unit |
| `network_errors` | journal of approval-poll, agent-approvals, reporter | counts of timeout / DNS / HTTP-status failures per endpoint host, first/last seen |

Redaction helper shared with `ssh_security_evidence.py`: mask emails, tokens
(hex ≥32), private IPs; keep public source IPs only in `ssh_events` honeypot
counts (needed for global correlation).

Tests: fixture journals (`journalctl -o json` samples, including today's
claude3 sequence) → expected digests; redaction cases; size caps.

Also: install `install_operations_agent.sh` from the initial install path, not
only `standard_upgrade.py`, so new nodes get the agent in inspect mode.

## Phase 2: digest upload to DB server

- Prerequisite (done 2026-11-26, see `docs/CLAUDE_COLLABORATION.md` log): `misc/configure_global_db.sh` sets both
  `setup.globalDb1ip` and `DBSERVER`, installs rsyslog forwarding to TCP/5514
  and is now called from `install.sh`. Applied on claude3; rsyslog holds an
  established connection to the DB server on TCP/5514. dbserver1 confirmed the
  20:10:46 test line in `/var/log/tarasec/remote.log`; normalization into
  `syslog`/`syslogThreat` not yet checked.
- Do not push the new digests through rsyslog: they are structured JSON, not
  log lines, and need authentication. Instead add an endpoint next to
  `script/operationsActivity.php` (e.g. `script/nodeDigest.php`), discovered
  the same way (globalDb1ip, NetBird HTTP overlay with wt0 route check,
  otherwise HTTPS) and authenticated with the same separately enrolled
  central node key. Stores `node_digest` rows
  (node, kind, window, JSON, received_at).
- Node coordinator uploads `ssh_events` + `network_errors` every 5 min,
  `recent_failures` on change. Missed uploads are skipped, not queued.
- Retention: 30 days raw digests; aggregates kept longer.

## Phase 3: global correlation on the DB server

Deterministic first, model second:

1. SQL/scripted correlation over `hackReport`, `syslogThreat`, honeypot counts:
   same source hitting ≥N nodes' honeypots, synchronized timing, shared
   user-agent/SSH client banners → candidate botnet clusters.
2. False-positive candidates: reports whose source later appears in an
   allowlist, completed an approved SSH login, or is a registered partner.
3. Send *candidates plus evidence summaries* to a Flowise "global" chatflow
   for ranking/explanation. Model output stored as advisory verdicts with
   reasons; promotion to an enforced block or FP clearance follows existing
   DB policy (thresholds or operator approval).

## First live test case: owner self-lockout false positive

Scenario: the owner forgets to open an SSH window with Google, connects to the
old/closed port, hits the port-22 honeypot, and is tagged. The correct outcome
is known in advance, so it is the cleanest end-to-end test of FP clearing.

What exists today:

- `tarasec_ssh_honeypot.py` emits `tag_host` (severity 7) on *any* credential
  attempt on a normal honeypot port, as JSON to the journal.
- `appDemoSshSession.php` has demo-only states `cleared` /
  `owner_clear_required` and marks the `hackReport` "gateway release required".
- `confession.php` lets an owner *confirm* their unit caused traffic
  (`ownerConfirmedTime`); it does not clear anything.
- There is no general owner-initiated or evidence-based clearing for a real
  (non-demo) tag. To verify: exactly which local block/ipset a `tag_host` event
  ends up in on a node, and how that reaches `hackReport` and the DB server.

Proposed clearing levels, set by the server owner in local policy and shown/
changed in the app (Manage → Security, behind the existing fresh Google + TOTP
management session):

| Level | Name | Clearing rule |
|---|---|---|
| 0 | Automatic | Tag on source S is cleared when S completes a successful login on the real management port inside an approved Google/MFA window within N minutes (default 30) of the tag, and S is in `SSH_ALLOWED_SOURCES`. |
| 1 | App confirm (default) | Tag is listed in the app as "possibly you"; owner clears it with fresh Google + TOTP. Evidence from level 0 is shown as a hint, not applied. |
| 2 | App confirm + delay | As 1, but clearing takes effect after a cool-down (e.g. 15 min) and is cancellable from the app/Ops page. |
| 3 | Central only | No local clearing; the tag goes to the DB server, which clears only after correlation (Phase 3) or operator review. |

Rules regardless of level:

- A clearing is a typed record (`source`, `report_id`, `level`, `evidence`,
  `cleared_by`: `auto`/`owner`/`central`), stored locally and uploaded with the
  Phase 2 digest. The DB server marks the matching `hackReport` as an
  owner-cleared false positive and excludes it from botnet clustering and from
  partner notifications. Every clearing is labelled training data for Phase 3.
- Clearing removes the tag/block only; it never opens SSH or extends a window.
- Commands typed into the honeypot shell (`ssh_command_input`, severity 8) are
  never auto-cleared at any level.
- Repeated self-lockouts from the same source are reported to the owner (likely
  a client config problem, e.g. too many keys offered → `MaxAuthTries`).

Live test script (owner workstation → `claude3`):

1. Without opening a window, `ssh -p 22` to `claude3` and enter a
   password → expect `tag_host` and a block of the workstation's address.
2. For each level: perform the clearing path; verify block removed, clearing
   record written, digest uploaded, DB `hackReport` marked, and the event not
   counted in correlation.
3. Negative: a different source logging in must not clear the workstation; a login
   outside a window must not count; a shell command in the honeypot must not
   auto-clear.

Recovery during testing: the owner keeps an independent console (hypervisor) and an existing SSH session open, as for the port migration.

## Where node model calls go: decision

| Option | Pros | Cons |
|---|---|---|
| A. Node → Flowise directly (today) | Already works; DB not in model path; local ops survive DB outage | Flowise key on every node; no central record of what nodes asked |
| B. Node → DB server → Flowise | One credential holder; DB can enrich prompts with global context | DB becomes a hard dependency for local ops; more load on DB |

Recommendation: **keep A for local operations, add B only for global
questions.** Evidence digests go to the DB server (Phase 2) regardless; the DB
server alone talks to the global chatflow. Revisit B if per-node Flowise keys
become a management problem.

## Open questions

- Confirm the operations-activity central node key is the right credential to
  reuse for digest upload (vs. a separate key per purpose).
- Flowise: one chatflow per purpose (local ops, global) or one with routing?
- `tarasec-ai.service` on `dbserver1` fails with Flowise HTTP 500 (reported
  2026-11-26); the existing AI assessment path must work before Phase 3.
- Which Ollama model on the GPU VM, and does it reliably return the JSON
  action protocol? (`operations_model_probe.py --progression` answers this.)
- Retention and access policy for `node_digest` (contains public attacker IPs).
- Should FP clearances be pushed to nodes automatically or require operator
  approval for the first release?

## Verification before declaring any phase done

Live, not CI-only: trigger a honeypot hit, a failed login and an unconfirmed
port migration on a test node; confirm the digest shows each, the DB server
stores it, and correlation output references it. Confirm no email, token or
private address appears in any uploaded digest.
