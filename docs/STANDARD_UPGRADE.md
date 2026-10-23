# Standard upgrade

Run on the server being updated, from a reviewed TaraSec checkout:

```bash
cd ~/taransvar
sudo ./tarasec upgrade
```

The first run installs the `tarasec` command, confirms the local roles (database,
gateway, node; combinations allowed), and requires an explicit demo/production
choice. Role detection is a suggestion, not authorization. Keep this checkout
at the same path; the command points to it. Normal upgrades fetch `origin/main` and fast-forward a clean `main` checkout
before offering feature changes. Local edits, detached checkouts and unpublished
or divergent commits stop the automatic update. Git runs as the checkout owner
when invoked through sudo. Fetch failure stops the upgrade; it does not silently
install stale code. After fetching, the command restarts its updated launcher.
It does not certify production readiness. Use `--no-pull` to deliberately use the
current checkout offline or test a reviewed feature branch. `--plan`, `--status`
and `--help` never fetch or modify source.

```bash
sudo tarasec upgrade --no-pull
sudo tarasec upgrade --plan
sudo tarasec upgrade --status
sudo tarasec upgrade --review-declined
sudo tarasec upgrade --roles database,gateway --mode production
```

Upgrade approval follows the selected mode:

| Mode | None / low / medium risk | High or unclassified risk |
|---|---|---|
| Demo | Apply automatically for testing | Ask for confirmation |
| Production | Ask for confirmation every time | Ask for confirmation every time |

Choosing Demo is the authorization for bounded automatic test upgrades, including
new eligible components. The old `automatic_enabled` opt-in flag is ignored.
Production never reuses a previous Yes as authorization for another upgrade and
rejects `--automatic`, even if an older installation retained that flag.
Explicit **Never** choices remain excluded until `--review-declined`; **No**
postpones an interactive choice until a later run. A required mode/role change
still needs confirmation. High-risk access changes retain their live recovery
and credential prerequisites.

Built-in minimum risks are low for the Perl runtime/Gatekeeper, medium for
reviewed additive DB migrations/managed Taralink/operations-worker deployment,
medium for refreshing existing Google integration, and high for new Google
enrollment and SSH keys, closure or port/honeypot changes.
`release.json` may raise a component's risk for a particular release; it cannot
lower an access control's minimum. Unknown or invalid ratings default to high.
Security/important/routine **notice severity is urgency**, not deployment risk.
A routine notice can still concern a high-risk change requiring confirmation.
Low-risk dependents wait for required schema/runtime prerequisites; they do not
automatically bypass a high-risk or Never-declined dependency.

Database, runtime, managed Taralink and Gatekeeper deploy first, followed by the
operations worker and optional access controls. Add future bounded components to
the explicit feature registry and risk policy. Results record risk, source
revision and whether authorization was demo policy or fresh user confirmation.

The interactive command coordinates existing reviewed installers. It does not
run the broad legacy OS installer or experimental kernel compiler automatically.
Kernel builds and initial hotspot/network provisioning remain separate pending
safe verified upgrade adapters. Production disables the legacy independent DB-migration timer. The standalone
schema migrator also requires confirmation in Production; unattended timer calls
cannot bypass it. Other system package-maintenance policies are unchanged.

## Modes

`/etc/tarasec-deployment-mode` is a root-owned, world-readable policy file with
`demo` or `production`. Gatekeeper displays it. Legacy hosts without this file
remain **unconfigured**, retaining previous demo behaviour until enrollment.
Malformed/unreadable policy fails closed at guarded demo endpoints.

Production disables demo API/controllers, including direct Gatekeeper demo
includes and simulated infection requests, without disabling real infection
reporting. A production transition explicitly deploys the guarded web tree when
Gatekeeper is installed, preserving existing `dbfunc.php` credentials. It refuses
active demo sessions/infections and enabled demo workers or a configured honeypot
demo port: finish/release those demos before retrying. No history is deleted.
Static presentation assets may remain accessible; their control APIs are blocked.
Production mode is an operating policy, not a claim of production certification.

Mode changes require confirmation and do not weaken administrator authentication.
Run the upgrade on every participating server; one host's mode does not configure
the others. Existing standalone demo tools outside the web tree must be retired
or disabled separately before production use.

## Recovery and verification

Private backups, feature results and an append-only event list live under
`/var/lib/tarasec/upgrade/`. Concurrent runs are locked. A failed prerequisite or
installer stops further features and is retried by rerunning the same command.
Interrupted features remain `running`, never falsely `completed`. Backups are
made before web/runtime replacement and before configuration changes. A complete
logical database dump must succeed before the additive schema migration runs.
Restore configuration files from the printed backup path after inspecting them;
restore the SQL dump with a DBA's supervision. Restore is deliberately not
performed automatically after partially applied DDL.

Local DB credentials in web `dbfunc.php` are preserved. Non-credential runtime
and web files are overwritten from the reviewed checkout; local customizations
must be reviewed before accepting those features. No files are deleted.

Google enrollment preserves existing identities and grants. SSH gate enablement
requires an independent console and operator confirmation of tested Google/TOTP
opening. Signing in alone does not open SSH. New connections remain subject to
the existing source restrictions and temporary window; no bypass is added.
See [Google SSH](google-ssh.md) for live acceptance and recovery checks.

Public-key installation adds one validated public key for a selected local user;
it does not disable passwords or prove a remote login. The honeypot adapter uses
the existing failsafe installer and intentionally stops with rollback armed;
verify a **new** SSH login and finish its printed checks before cancelling
rollback. It is reported as pending verification, not completed.

Repository tests do not establish working MFA, SSH recovery, live DB restoration
or production network behaviour. Those checks must be performed on each host.

## Network upgrade warnings and automatic maintenance

Every full minute heartbeat now carries `software`: the minimum **deployed**
release version across components required for this host's roles, plus individual
component revisions/results. `release.json` defines the monotonic integer release
and applicable components. Bump its version when publishing a coordinated release;
never reuse a release number for different requirements. Pulling source does not
advance installed versions. Failed/interrupted component updates report version 0
until successfully retried. This is deployment accounting, not file-integrity
attestation or independent end-to-end network acceptance.

The DB evaluates `html/upgrade-notices.json` (or the administrator's optional
`/etc/tarasec/upgrade-notices.json` override, readable by the web server). A notice
has a stable id, minimum release, affected roles, severity (`security`, `important`,
`routine`), a short note and a network-impact explanation. At most eight notices
are accepted; note/impact lengths are bounded to 192/128 bytes. Never store tokens,
private diagnostics or commands in notices. Example:

```json
{"notices":[{"id":"protocol-compatibility-2","minimum_version":2,
"roles":["gateway","node"],"severity":"important",
"note":"Update the cooperative protocol parser.",
"network_impact":"Older senders may not interoperate with updated receivers."}]}
```

Security and important notices make a unit's TaraSec upgrade dot **red** until a
fresh heartbeat reports the required deployed release. Routine notices are yellow.
An unreported version cannot count as current. Gatekeeper reevaluates notices at
view time, so notices also affect offline/legacy units immediately; heartbeat age
remains a separate indication. Unit details explain the version, required release,
note and network impact. These maintenance dots do not mark traffic infected.
No database migration is needed: version/evaluation data use the existing status
JSON and heartbeat history. The agent's upgrade issues use the existing Issues
lifecycle and history when that agent is installed.

On each node, run the standard upgrade once to install the new minute runtime
and operations worker and confirm the host mode/roles. Demo automatically handles
eligible upgrades without a separate opt-in. The operations worker must be running
under Demo maintenance with a trusted activity probe. A disabled worker or explicit
inspection-only agent policy is not silently enabled or expanded. Production
notices remain **awaiting confirmation**, with the instruction to run
`sudo tarasec upgrade`; its feature prompts provide fresh approval for each update.

Only the primary configured DB heartbeat response supplies the local advisory
receipt; secondary DBs cannot overwrite it. A receipt is valid for 180 seconds.
The agent updates without a model call in Demo mode, with matching agent policy,
a trusted root-owned launcher/checkout, quiet demo/traffic evidence and idle
interactive-session evidence. Unknown activity blocks it. Attempts are limited to
three per UTC day with a one-hour cooldown. A DB notice can request attention but
cannot specify a command, Git remote, commit, manager, feature or execution grant.
The existing registered-router/IP and private-network heartbeat trust model is
unchanged; notices are not cryptographically signed release approvals.

The fixed action is `tarasec upgrade --automatic`. It is Demo-only and fetches
normal `origin/main`, then applies eligible none-through-medium risk adapters,
including new bounded components for testing. Existing current component revisions
are skipped. Higher-risk and unknown adapters require interactive approval. This
path never changes SSH enrollment/windows/keys/ports, mode/roles or Never choices.
A high-risk prerequisite holds its dependents until that prerequisite is manually
upgraded. Production refuses this path regardless of any historical opt-in, past
Yes, or agent request. Every automatic component also rechecks Demo mode before
execution. Failed eligible components remain retryable within the same budgets.

Successful command exit leaves **verification pending**. The next successful
heartbeat/DB assessment must confirm the installed release before the agent marks
the issue solved. A stale/missing/invalid receipt never clears an issue. New DB
notices, changed network compatibility and unsupported target releases remain
visible; operators can inspect `tarasec upgrade --status` and Gatekeeper Issues.
Backups and results are kept locally; raw upgrade output is not sent to a model.
A failed/partial upgrade stays red and is retried within the same policy/budget.

### Existing Google login during upgrades

The upgrader detects both legacy Gatekeeper configuration
(`/etc/tarasec/gatekeeper-google.php`) and existing node enrollment/identity. It
reports an existing setup as a **refresh**, not a new Google login installation.
This refresh is medium risk and runs without an upgrade prompt in Demo mode;
Production still confirms each update. The Google client configuration, manager
grants, node token and SSH policy are retained. Gatekeeper-only login does not
enroll the DB server as a node manager. Existing approval workers are refreshed
with `--no-enrollment --update-only`, preserving active/inactive scheduling.
If existing configuration disappears before execution, an unattended refresh
stops rather than falling back to enrollment. A new Google manager enrollment
remains high risk and requires explicit confirmation and account details.

An existing node token alone does not prove the separate approval worker is
configured. If worker access/manager policy or its public nickname is missing,
the Google refresh reports the exact missing prerequisites, leaves that worker
unchanged, and continues other upgrades. A successful Gatekeeper refresh remains
separate from worker setup. The Google feature is recorded as partial, with its
worker version unverified; `--status` and the heartbeat retain the warning, and
Gatekeeper displays a yellow configuration warning after required releases are
current. It does not infer manager grants, create enrollment, enable a service or
claim that an incomplete worker was upgraded. Actual installer failures after
prerequisite validation still stop the upgrade.

### Help at access-control prompts

Before asking for approval, access-control steps explain the checks in context.
The SSH-window step displays this server's configured public nickname and its
approval-service page, explains Google/TOTP opening and independent VM/physical
console recovery, and tells the operator when to type VERIFIED. Signing in alone
does not open SSH.

The administrator key belongs to the computer used to connect **from** (for
example Acer), while the target username belongs to the server. On the
administrator computer, run `ls ~/.ssh/*.pub`, then `cat` the chosen `.pub` file.
Paste the complete public-key line at the server's prompt; file transfer is not
required. Alternatively enter a public-key file path already present on the
server. A `~/` path is expanded against the selected server account's home, even
under sudo. Private keys and multi-key files are rejected; the displayed
fingerprint can be compared with `ssh-keygen -lf` on the administrator computer.
If no key exists, create one on that computer with `ssh-keygen -t ed25519`.
Adding a public key leaves password policy unchanged; verify a new SSH login.

### Input help and correction

Help is repeated at the actual SSH-window/public-key prompts, after the feature
approval, so operators do not need to find earlier terminal output. Enter `?` or
`help` for the instructions again. Verification words are case-insensitive but
must spell VERIFIED correctly: a typo retries and never counts as confirmation.
Enter `no`, `skip`, or a blank verification answer to postpone the step and
continue the remaining upgrades. Mode, username, key-file/key-content and port
input can be corrected without restarting the whole upgrade. A blank key/account
answer postpones key installation. Ctrl+C deliberately stops the run.

Verification and key validation happen before changing SSH access or installing
a key. The honeypot recovery check now also precedes writing its port settings.

### Refreshing an existing SSH honeypot

The upgrader compares the effective sshd port and live listener with the desired
admin port and existing honeypot installation. A refresh uses `--refresh-only`: it
revalidates readiness, replaces honeypot files and restarts only an already active
honeypot. It does not rewrite sshd, open temporary firewall access, arm/cancel
rollback or replace a recovery snapshot. An inactive honeypot remains inactive.
An explicit first deployment can start the requested honeypot while leaving an
already configured admin SSH listener intact. Only a real port migration uses
the two-port transition and console verification.

An already armed rollback timer blocks all new installer runs until recovery is
verified from the independent console. Restore its saved state with
`sudo systemctl start tarasec-ssh-rollback.service`, verify the service result,
expected SSH/honeypot listeners, firewall policy and a new authorized SSH login.
Only after successful verification cancel the leftover timer; never replace an
unverified recovery snapshot by rerunning migration. Listening sockets alone do
not prove that the Google gate is enforced or a new login works.

Migrations refuse a configured or observed Google SSH gate rather than inserting
temporary ACCEPT rules that could bypass it. Non-gated migrations arm rollback
before stopping the honeypot or changing firewall access. Listener checks consume
all output (avoiding pipefail/SIGPIPE false failures) and wait for startup. An
effective-port mismatch or socket ListenStream override still requires console
diagnosis; a failed listener check is not automatically treated as success.
