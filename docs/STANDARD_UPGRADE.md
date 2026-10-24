# Standard upgrade

Central AI approval waits for up to five minutes using a monotonic deadline.
The terminal first shows the browser URL, machine nickname and approval steps.
Ctrl+C during this wait postpones AI activation and continues the remaining
upgrade steps; it does not cancel the website request. Approve later and rerun
the upgrade to resume. Unattended runs do not wait for browser interaction.

Database upgrades first compare the live schema version with this checkout.
An already current schema is recorded without another database dump or migration.
When migration is needed, free space must cover a conservative size estimate and
a 256 MiB reserve before dumping. A dump is published as taransvar.sql only after
success; interrupted or failed dumps created by this run are removed. Completed
recovery backups are preserved. No migration runs after a failed backup.

At model setup, **blank selects the TaraSec system** (including at the Flowise
prediction URL prompt); **none postpones**. A custom Flowise HTTP(S) prediction
URL or direct OpenAI configuration remains available. Explicit custom HTTP uses
an insecure transport flag and prints a warning; central enrollment always uses
HTTPS without redirects. Existing complete custom connections are retained.
The default creates a node-specific AI activation request on the existing
Google/TOTP agent approval page. After approval, rerun the upgrade to retrieve
the grant, test the connection and activate the local agent. The central Flowise
key stays on the website; the node uses its existing enrollment token. Central
model configuration must be installed once by the site administrator. Missing
central configuration is reported separately from pending operator approval.

When the AI agent lacks a model connection, an interactive upgrade now offers
guided Flowise or direct OpenAI setup. API keys are entered without echo and
stored with mode 0600; existing complete settings are reused. Unattended runs
remain pending and request an interactive upgrade instead of asking for secrets.
The chosen deployment mode is retained and a non-executing model check precedes
activation. After activation, disk-pressure housekeeping runs installed logrotate
rules and, if pressure remains high, clears downloaded APT packages. It never
removes installed packages, database data or upgrade/recovery backups. Both
housekeeping operations run under the agent's existing mode, threshold, cooldown
and audit controls, independently of model-call budget.

The **AI operations agent** step detects an existing installation. A missing
agent requires installation confirmation, even in demo mode; unattended runs
skip first installation. After installation or update, the upgrader tests the
configured model connection and decision protocol without executing returned
actions, copies the selected demo/production mode into the agent policy, and
enables its recurring timer. Production upgrades still require confirmation.
Other local policy settings and action-specific readiness checks remain in force.
Missing model configuration or a failed probe is recorded as partial, pending
activation, with instructions to configure the endpoint/key and rerun. No model
credentials are printed. A standalone installer still only installs files.

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

### Require both SSH key and account password

The `ssh-auth` step checks the effective SSH policy for the invoking administrator. `AuthenticationMethods any` produces a warning and offers `yes/no/never`. This high-risk step always requires confirmation, even in demo mode; `never` can be reconsidered with `--review-declined`. An effective `publickey,password` policy skips the offer. Custom policies are reported and require manual review.

Yes asks for the administrator account, requires a set account password and confirmation of a tested key login and independent console recovery. It backs up its managed drop-in, arms a ten-minute rollback, validates SSH syntax and effective policy, then reloads SSH. The operator must test a new connection with connection sharing disabled and confirm that both the key and server account password were required. Only then is rollback cancelled. Refusal or interruption restores the previous drop-in immediately; loss of the terminal leaves the recovery timer armed. Google/TOTP opening, SSH ports and firewall rules are preserved. A private-key passphrase is not the server account password. This applies to new connections; existing sessions remain available during verification.

### Activity observer readiness and repair

The operations upgrade now verifies its configured authenticated demo collector and starts/restarts the continuous activity observer after successful verification. A missing URL or unavailable feed is explicitly reported as pending activity configuration, even if model activation succeeds. Existing minute reports and a zero-client summary do not populate this separate observer contract. No missing response is treated as an inactive demo.

Run `sudo tarasec upgrade` normally. The operations step checks activity readiness before testing the model connection. It reuses existing feed settings without another prompt; when the URL is missing, the interactive upgrade includes feed enrollment in the normal flow. Enter preserves existing settings, and `none` postpones. HTTPS is the default; explicitly entered literal NetBird/loopback HTTP URLs require their corresponding verified route. See `docs/OPERATIONS_ACTIVITY_DEPLOYMENT.md` for central source-IP enrollment and secure key transfer. Missing enrollment remains an explicit operations issue, never presumed inactivity. The former `--configure-activity` option remains compatible for older instructions but is not required.

Reboot reports now distinguish a missing/stale observer, missing demo URL/key, HTTP failures, active demos, routed traffic and a quiet period still accumulating. Collector diagnostics exclude raw exception strings and credentials. Session activity and demo-feed failures are reported together rather than one hiding the other.

During every agent cycle, activity readiness is sampled before model budget checks and reboot maintenance. In demo/production maintenance modes, a configured but stopped observer is enabled/started and a stale running observer is restarted, with attempts limited to once per five minutes. A healthy observer is not restarted, so continuous quiet time is preserved. Fresh incomplete samples retry within the observer itself. Custom probes retain their owner-managed lifecycle; inspection mode does not change services. Activity-monitoring issues are reported even when no reboot is pending. This uses the installed source-IP-bound feed credentials; it does not create credentials or assume the absence of client traffic proves the absence of all demo sessions.

For systemd-managed TaraLink installations, the minute reporter skips its legacy combined kernel/link startup call when the management marker or a standard `taralink.service` unit is present. This preserves systemd ownership even during intentional service stops or an upgrade. The repository firewall script is executable so local permission workarounds are unnecessary.

The boot-time `startup.pl` uses the same systemd ownership guard as the minute reporter; neither invokes the legacy combined startup when TaraLink is managed by systemd.

Activity setup now discovers `/script/operationsActivity.php` from the existing
`DBSERVER` in `/etc/tarasecfw.conf`, without asking for a feed URL. Existing feed
configuration takes precedence. A literal NetBird address uses the existing HTTP
overlay transport with the collector's required wt0 route check; other addresses
use HTTPS. A local TaraSec database uses the loopback enrollment helper. Missing
node keys still require secure enrollment; an unknown central address or unavailable
authenticated feed remains pending and cannot establish inactivity.

Central loopback enrollment requires `setup.isGlobalDbServer = 1`; the presence
of a local TaraSec database does not establish this role. Unknown role remains
pending. On confirmed non-central nodes, the mistakenly generated loopback
setting is replaced using DBSERVER and a separately enrolled central node key;
the local loopback token is never reused as a remote credential.

For enrolled NetBird nodes using tarasec.org, activity keys are now generated
and registered automatically using the existing node identity over HTTPS. No
key-file prompt or manual transfer is needed. Update the approval host's
`ops/agent/activity.php` and the central DB web files first; see
`OPERATIONS_ACTIVITY_DEPLOYMENT.md`. Missing services remain pending.

## Core node and optional functions

The standard TaraSec core consists of tarakernel, taralink, the local MySQL/MariaDB
database, Gatekeeper and server protection (Google/TOTP administrative access and
AI maintenance). Gateway/routing, hotspot and central coordination are additional
functions; application workloads can coexist with the core.

The interactive upgrade inventories core presence and observed optional settings
before confirmation. Presence does not prove service health, functioning login
enforcement or verified forwarding. A firewall configuration file alone does not
establish a gateway role. Existing internal role keys are retained for compatibility.
This upgrader updates an existing installation; absent components require initial
setup, and existing workloads are not silently replaced.

Activity discovery reads setup.globalDb1ip, the same Global DB 1 displayed by
Gatekeeper. On a node with a local database, an unavailable or invalid Global DB 1
remains pending rather than falling back to stale DBSERVER. Protection-only legacy
installations without a local database retain their existing DBSERVER fallback.
Google enrollment precedes AI operations activation, so a new server establishes
its web identity before requesting model or activity approval.


## Daily desired-state maintenance and staged releases

The opt-in standing daily policy supersedes the old per-step risk prompts on an
enrolled node, in both demo and production. It is separate from model-driven AI
operations: no model call or model budget is required. It retains assigned roles,
activity/session checks, backups and existing SSH reopening requirements.

Enroll the central DB server once, from the reviewed checkout:

```sh
sudo tarasec upgrade --enable-daily
```

This installs the root-owned controller and timers. On the central server it also
bootstraps the small management-plane web update and creates a paused rollout
policy. Open **Setup → Upgrade rollout** in central Gatekeeper as an administrator,
select test-unit IPv4 addresses, then save/resume. Roquefort can be the first test
unit; selecting test units is explicit, never inferred from the hostname or demo
mode. Other nodes use the same one-time enrollment command. Unassigned units must
first receive an explicit `--roles` assignment. Existing roles are reused; future
changes require `--enable-daily --assign-roles --roles ...`.

The daily window is **03:00–03:10 Europe/Oslo**, including daylight-saving changes.
Units which were off catch up through the persistent timer. Busy units defer,
rather than disrupting demos, forwarded traffic or recently active sessions.
A small root health checker runs every minute, independent of the model, and
checks eligible services, schema and local Gatekeeper HTTP response. These bounded
checks do not establish end-to-end tagging correctness or replace local testing.

Release task states live in `release.json.managed_tasks`:

| State | Automatic behavior |
| --- | --- |
| `ready` | Eligible for the staged normal routine. Install missing supported components or update changed ones. |
| `being_tested` | Run only on the centrally designated test computers. Other units skip it and show a red task flag if work is outstanding. |
| `requires_manual_installation` | Do not run automatically; show outstanding work with a red upgrade dot. |
| `requires_local_testing` | Do not run automatically; show outstanding local testing with a red upgrade dot. |

An absent or unsupported task is never converted into a command. New task IDs
need a reviewed typed adapter; unknown IDs are flagged for manual installation.
An unchanged, verified working component is not reinstalled. The controller uses
component content fingerprints, recorded source revisions and effective checks;
a downloaded source revision alone does not count as an installed release.
A `verified` rollout receipt covers eligible tasks only: withheld tasks remain red.
Initial database credentials, administrator public keys, recovery verification
and workload-specific integrations can be real prerequisites. They are flagged
without terminal prompts. For example, an existing Cowrie listener is not replaced,
and a ready taralink task cannot silently install an experimental kernel module.

The central service pins exact Git revisions. Only test units may nominate a new
candidate. Fleet units receive the previous promoted revision until the new one
has accumulated **24 continuous hours** of fresh, healthy test-unit reports and
fresh post-deployment health evidence. The next daily fleet window deploys it.
Pending setup, missing/stale reports, stale health samples or wrong-generation
receipts cannot promote a candidate. A test failure latches a hold; automatic
retry cannot clear it. **Discard failed candidate and test latest** explicitly
resets the generation and starts testing again. **Pause all upgrades** prevents
subsequent dispatch, but cannot undo an installer already running.

Scheduled fetching does not execute newly fetched `main`. The fixed installed
controller asks for its gate first and exports only a pinned revision which is an
ancestor of the assigned repository's fetched main. Archive links/devices are
rejected. An eligible verified runtime update refreshes the installed controller.
The older notice-driven automatic adapter is disabled on enrolled units. Releases
with a managed task catalog also refuse the legacy automatic selector on
unenrolled units and flag one-time enrollment as required, so no old automatic
path can bypass staging. Explicit manual behavior remains for unenrolled nodes.
Failed installer output is retained in a bounded root-only diagnostic file; raw
output and credentials are not published through the status report.

Deployment status is reported through the existing minute report. The TaraSec
upgrade dot and unit issue details show withheld tasks, pending prerequisites and
failed checks. Use the central web page for pause/reset instead of logging into
individual units. This release has automated regression coverage; live test-unit
enrollment, complete observation-period promotion and real fleet deployment still
require deployment verification. No units are enrolled merely by merging code.
