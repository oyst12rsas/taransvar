# AI agent collaboration protocol (Claude Code and Codex)

Several AI coding sessions work on Taransvar from different hosts: Claude Code
sessions and a Codex CLI session. They cannot message each other directly, so
they coordinate through this repository and rsyslog. The same rules apply to
every agent, whichever tool it runs in.

## Participants

| Host        | Agent       | NetBird IP    | Role                                                 |
|-------------|-------------|---------------|------------------------------------------------------|
| `dbserver1` | Claude Code |               | Receives rsyslog, analyses threat intel, DB/taralink |
| `claude3`   | Claude Code | 100.68.214.63 | Sensor / honeypot, sends threat intel via rsyslog    |
| `flowise`   | Codex CLI   |               | Flowise: chatflows and the API `tarasec-ai.service` calls |
| `ollama`    | (none)      |               | Ollama: runs the local models Flowise uses          |

The Codex session on `flowise` owns the AI side, `ollama` included: chatflows,
model selection and the Flowise API that `tarasec-ai.service` on `dbserver1`
calls. Report Flowise errors seen from `dbserver1` (for example HTTP 500) as a
`claude-collab` issue for `flowise`, with the time window and the request type,
but not the chatflow ID. `flowise` reports model or chatflow changes that alter
the response format the same way, because `dbserver1` parses those responses.

This repository is **public**. IP addresses and hostnames are not treated as
secrets: servers must be properly secured, and break-in attempts are detected
and tagged by TaraSec. Never commit or post credentials: API keys, tokens,
passwords, private keys, Flowise chatflow IDs, or personal data. Scrub those
from log excerpts before sharing; keep excerpts short and cite the time window.

## Channels

1. **rsyslog (raw data):** sensors send TCP/5514 to the DB server. Lines land in
   `/var/log/tarasec/remote.log` with the real peer in `TARASEC_SENSOR_IP=`.
   Prefix test traffic with `TARASEC_TEST`.
2. **GitHub issues (requests and findings):** label `claude-collab`. The title
   starts with the sending host, e.g. `[claude3] SSH brute force from 203.0.113.0/24`
   or `[flowise] Chatflow switched to a new model`.
   Point to the log evidence (time window and a grep pattern) instead of pasting
   large dumps.
3. **Branches/PRs (code changes):** branch names start with the host, e.g.
   `dbserver1/fix-...`, `claude3/feat-...` or `flowise/fix-...`. Merge through a PR. Never push to
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
- 2026-11-27: Added `flowise` (Codex CLI) and `ollama` as participants.
  Open for `flowise`: the Flowise HTTP 500 seen by `tarasec-ai.service` above.
