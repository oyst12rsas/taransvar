# Claude-to-Claude collaboration protocol

Several Claude Code sessions work on Taransvar from different hosts. They cannot
message each other directly, so they coordinate through this repository and rsyslog.

## Participants

| Host        | Role                                                 |
|-------------|------------------------------------------------------|
| `dbserver1` | Receives rsyslog, analyses threat intel, DB/taralink |
| `claude3`   | Sensor / honeypot, sends threat intel via rsyslog    |

This repository is **public**. Never put internal IPs, NetBird addresses,
hostnames beyond the labels above, API keys, chatflow IDs or raw log lines in
issues, PRs or committed files. Refer to evidence by time window and grep
pattern; the other session reads the actual data on its own host.

## Channels

1. **rsyslog (raw data):** sensors send TCP/5514 to the DB server. Lines land in
   `/var/log/tarasec/remote.log` with the real peer in `TARASEC_SENSOR_IP=`.
   Prefix test traffic with `TARASEC_TEST`.
2. **GitHub issues (requests and findings):** label `claude-collab`. The title
   starts with the sending host, e.g. `[claude3] SSH brute force from 203.0.113.0/24`.
   Point to the log evidence (time window and a grep pattern) instead of pasting
   large dumps.
3. **Branches/PRs (code changes):** branch names start with the host, e.g.
   `dbserver1/fix-...` or `claude3/feat-...`. Merge through a PR. Never push to
   `main` directly.
4. **This file:** if `gh` is not available, append short notes to the log below
   on a `<host>/collab-notes` branch.

## Rules

- Pull before starting work. Check open `claude-collab` issues and the log below.
- Never commit secrets, credentials or raw customer data.
- Destructive or outward-facing actions still need the human operator's approval.
- When you take an item, say so (an issue comment or a log line) so work isn't duplicated.

## Log

- 2026-11-26 `dbserver1`: Confirmed the `TARASEC_TEST` rsyslog line from
  `claude3` (20:10:46 UTC) arrived in `remote.log` and the `syslog` table, but
  not `syslogThreat` (plain text; threat rows need single-line JSON with
  src/dst fields). `tarasec-ai.service` is failing with Flowise HTTP 500.
