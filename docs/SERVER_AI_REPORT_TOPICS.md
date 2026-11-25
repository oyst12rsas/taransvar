# Operator AI report topics

`misc/server_ai_report_client.py --local` sends one report per hourly run to the
dispatcher (`POST /reports`). The default topics are `logs` and `auth`; set
`SERVER_AI_TOPICS` in `/etc/tarasec/server-ai-report.env` (or pass `--topics`)
to change them. `disk` and `memory` still exist but are off by default. A plain
percentage needs no AI, so the same figures ride along as `context` instead.

The dispatcher must answer with exactly the topics it was sent, so it needs a
handler (prompt) for each topic before nodes switch to it.

## Installation policy

`install.sh` runs `misc/install_server_ai_report.sh --auto`. It installs the
client, runner, service and hourly timer on demo nodes (`demo` in
`/etc/tarasec-deployment-mode`, or no file yet, since unconfigured nodes keep
demo behaviour). On production nodes it changes nothing and prints the manual
command:

    sudo bash misc/install_server_ai_report.sh

A malformed mode file also skips the install. The report only runs once the node
has a TaraSec enrollment (`/etc/tarasec/agent-enrollment.json`) or a pilot
`/etc/tarasec/server-ai-report.env`.

Check what a node would send without sending it:

    sudo python3 /usr/local/lib/tarasec-operations/server_ai_report_client.py --local --dry-run

## Window and state

Each run covers the journal from the end of the previous successful submission
up to now (first run: the last hour; never more than 7 days). State lives in
`/var/lib/tarasec-server-ai-report/state.json` (root, 0600). It holds the window
end, the per-node pseudonym key, hashes of message templates already seen, and
`authorized_keys` fingerprints. The state is saved only once the dispatcher has
returned a `report_id`, so a failed run is covered again next time.

## Redaction (done on the node)

- `password=`, `token`, `secret`, `api_key`, `Authorization`, `Bearer` and
  `cookie` values become `<redacted>`. E-mail addresses become `<email>`.
  Mixed letter/digit tokens of 24 characters or more become `<token>`.
- Private, loopback, link-local and 100.64.0.0/10 (NetBird) addresses are kept.
  Public addresses become `pub-<10 hex>`: an HMAC with the node's key, stable on
  that node and not comparable across nodes.
- Usernames that are not local accounts are never sent. They appear only as
  counts or as `<not a local account>`.
- Each topic is capped at 16 KB. When entries are dropped, `"trimmed": true` is set.

## `logs` topic

Journal entries at priority warning or higher, grouped by source and message template.

| field | meaning |
|---|---|
| `baseline_run` | first run on this node: every message counts as new |
| `entries`, `distinct_messages`, `new_messages` | totals for the window |
| `firewall_log_lines` | kernel iptables LOG lines, counted but not listed |
| `read_limit_reached` | more than 20,000 entries; the rest were not read |
| `groups[]` | `source`, `unit`, `priority` (0 = emerg … 4 = warning), `template` (numbers → `N`, IPs → `<ip>`), `example` (one redacted message), `count`, `first`, `last`, `new` (template never seen on this node before) |
| `context` | `window{from,to,seconds}`, `disk_used_percent`, `memory_available_percent`, `load_1m`, `uptime_hours` |

`groups` are sorted with new messages first, then by priority, then by count.

Suggested prompt focus: what is new or recurring, which services are failing
or restarting, and the probable cause and next check. Say "nothing notable"
when that is the case.

## `auth` topic

| field | meaning |
|---|---|
| `ssh_logins[]` | successful SSH logins: `user`, `method`, `source`, `count`, `first`, `last` |
| `ssh_failures` | `total`, `local_accounts[]` (failures against real accounts), `attempts_on_unknown_names`, `distinct_unknown_names`, `top_sources[]`, `distinct_sources` |
| `ssh_not_allowed[]` | rejected by `AllowUsers`/`DenyUsers` etc.: `user`, `source`, `reason`, `count` |
| `sudo[]` | `user`, `target`, `command` (redacted, 160 chars), `problem` (e.g. `3 incorrect password attempts`, `user NOT in sudoers`), `count` |
| `su[]` | `user`, `target`, `result` (`ok`/`failed`), `count` |
| `account_changes[]` | messages from useradd/usermod/userdel/group*/passwd/chpasswd/chage |
| `authorized_keys` | `baseline_run`, `accounts[]` with `user`, `keys`, `added[]`, `removed[]` (SHA256 fingerprints; `restricted` = the key line has options such as `from=`), and `current[]` on the baseline run |
| `honeypot` | `events`, `distinct_sources` from `tarasec-ssh-honeypot` |
| `context` | as for `logs` |

Suggested prompt focus: logins from unexpected sources or methods, failures
against real accounts, new or removed SSH keys, new accounts, unusual sudo
commands or sudo failures. Routine repeated automation (for example root
running `iptables -L` every minute) is expected.

## Server-side work (dispatcher)

A full example request body is in `docs/server_ai_samples/report_logs_auth.json`.
It was built from made-up journal entries with the real collector.

1. Accept `logs` and `auth` as topic names in `POST /reports`. The current
   dispatcher answers HTTP 400 to them. Keep `disk` and `memory` working for
   nodes that set `SERVER_AI_TOPICS=disk,memory`.
2. Treat the payload as untrusted data from the node. Validate the types and
   reject a topic over 16 KB. Put the JSON into the prompt as quoted data,
   never as instructions: log lines can contain text an attacker chose.
3. Return each topic's result in the existing shape,
   `{"status": "done"|"failed", "summary": "..."}`. A short severity
   (`ok`/`notice`/`warning`/`critical`) inside or alongside the summary would
   let Gatekeeper sort nodes. The client prints whatever it gets.
4. Write prompts around the "Suggested prompt focus" notes above. `new: true`,
   priorities 0-3, failures against real accounts, logins from public
   (`pub-…`) sources, SSH key changes and account changes deserve attention.
   High-volume repeated automation does not.
5. `pub-…` pseudonyms are per node. Don't try to correlate them across nodes.
