# Audi operations-agent pilot: work checkpoint

Updated 2026-10-07. Branch: feature/audi-operations-pilot.
Draft PR: https://github.com/oyst12rsas/taransvar/pull/197
Implementation checkpoint: a67ffc72278494b46fe7decddbb5dce4aeb38f2c.
This is a continuation record, NOT deployment-test evidence.

## Objective and owner decisions

Install a real model-driven maintenance/deployment agent on Audi to inspect,
update, diagnose and verify TaraSec using the shared AI Operations Manual.
Reduce repeated human command relaying. Demo nodes may receive owner-authorized
automatic changes; production must remain conservative. Do not equate a
production gateway participating in a demo with a demo-owned server.

Audi is the authorized demo pilot. Logged reboots are authorized, with a local
configuration flag, daily limit and durable pre-reboot checkpoint. Defer
disruptive work during active demos or meaningful user/test traffic. Per-minute
status reports and maintenance heartbeats do not prevent maintenance; identify
them by configured origin/destination and purpose, not broad network exemptions.
Require continuous quiet observations; missing/stale evidence must defer.

User asked for readiness based on actual tests, with the same capability list
used during development and deployment. Store sanitized commands, results,
experience and verified remedies. Do not promote unsuccessful experiments.
Dedicated Flowise operations chatflow is the working assumption, but no
endpoint/key or existing compatible chatflow has been verified.

## Audi observed state (user-supplied)

Hostname audi; Ubuntu 24.04.5; root filesystem 19G, 15G used, 3.8G available, 80%.
Checkout /home/audi/taransvar; deployed runtime /root/taransvar.
Enabled: taralink.service, tarasec-gateway.service, tarasec-ssh-honeypot.service,
worker_conntrack.service, worker_read_dmesg.service, gateway-ai timer and
manager-requests timer. Hourly assessment service itself is disabled as normal
for a timer-triggered oneshot. No new operations worker installed.

We do not have live SSH access in this session. User executes shell blocks.
Start user-facing blocks with clear and explicitly name the computer.

## Existing infrastructure and useful verified experience

Flowise VM at NetBird 100.68.163.145 and local 192.168.122.175. Docker containers
flowise and qdrant were stopped after MSI relocation/shutdown. User started them
and was instructed to set restart=always; Flowise returned HTTP 200.
Standard gateway wt-qw1 successfully obtained AI assessment aiResponse #498 and
reported it to DB at 2026-10-07 17:01 UTC. Do not infer Audi model configuration
from that success.

The old tarasec-agent-approvals worker was installed on wt-qw1, not Audi.
It is bounded_checks, not an LLM command agent. Token/nickname created; approval
service returns HTTP 403. Local assessment is produced. Node registration is
unresolved, but the new pilot intentionally has no dependency on that service.

dbserver1 diagnostic log tarasec-crontasks.log was 3.4G. Owner explicitly
authorized discarding diagnostics. Truncating it reduced disk from79% to61%
and free space from about4.0G to7.3G. Daily/maxsize20M/rotate2/compress/
copytruncate logrotate rule installed; scheduled rotation remains unverified.
Do not delete evidentiary/system logs by assuming the same authorization.

Verified traffic/statuslog SFTP archival already operates on dbserver1, receiver
192.168.122.133, user archive-upload, uploads chroot. Traffic retention changed
to7days; statuslog30days. InnoDB deletion is not filesystem reclamation.
Archive PR193 is separate, not merged by this work.

## Implemented in PR197

- docs/AI_OPERATIONS_MANUAL.md: role-based source inventory, 17 existing status
  dots, readiness stages, evidence procedure, log experience, pilot boundaries.
- misc/operations_agent.py: HTTPS Flowise JSON decision loop, bounded inventory,
  persistent task state, root-local audit, lock, locally approved hash-pinned
  executable procedures, commit/platform/deployment-test eligibility.
- Fresh complete activity-probe contract and continuous quiet gate before all
  mutations; daily reboot budget and persisted checkpoint; boot resumption.
- misc/install_operations_agent.sh and example JSON config: disabled,
  inspection-only installation; preserved root-owned local policy.
- Eight unit tests and CI workflow. AGENTS.md and AI_INSTALL_GUIDE.md link manual.

Model chooses approved procedures, not arbitrary root shell text. This is a
deliberate conservative foundation, not the complete adaptive agent requested.
No existing TaraSec installer has been certified deployment_tested here.
No procedure is initially eligible. No DB/app integration for new-worker status.
No live Audi, Flowise protocol or reboot acceptance run has occurred.

Validation at implementation checkpoint:
python3 tests/operations_agent_test.py ->8tests passed;
bash -n misc/install_operations_agent.sh ->passed.
GitHub Operations agent pilot checks37675743399 and Hosted gateway linking
checks37675743166 both passed. Unit tests do not certify deployment behavior.

## Unfinished work: continue before calling this deployable

1. Inspect actual demo session schemas and application paths across enabled
   demo types. Build a trustworthy central demo/activity adapter for Audi.
   Missing tables/local absence must not be interpreted as no central demo.
2. Observe meaningful traffic continuously, including forwarding; precisely
   exclude reporting/heartbeats. Test busy, quiet, stale, observer restart,
   central outage and changing activity immediately before action.
3. Provide initial useful reviewed repair procedures (logs/disk, deployed script
   synchronization, service persistence), with preconditions and rollback.
   Validate on disposable fixtures then live pilot; do not falsify readiness.
4. Configure/test a dedicated Flowise operations prediction chatflow. Credential
   stays root-local; never paste raw key into chat/Git. Current worker requires
   HTTPS and JSON text/answer containing action, reason and task_state.
5. Review worker error reporting, model context, observation timing, task
   resumption and bounded audit/output storage. Current audit/output capture
   is bounded per command but overall log and temporary output growth need work.
   Quiet samples are currently obtained only on mutation decisions; check
   continuity and timing under slow model calls before live execution.
6. Prepare concrete bootstrap and end-to-end acceptance. No automatic production
   mutation. Do not enable Audi mutations/reboots until adapter/procedures ready.
7. Update PR around final scope, run focused tests/CI, and clearly report what
   was actually deployed versus repository-only work.

## Continuation locations

Repository-backed edits are persistent in PR197. Scratch staging:
 /workspace/scratch/a66632fd35a0/audi-agent
Do not rely on scratch surviving; fetch branch files when resuming.
Read repository AGENTS.md, AI_TRAINING.md, AI_INSTALL_GUIDE.md and manual.
User explicitly requested documenting progress so resource/session limits do
not lose work. Update this checkpoint as each blocker is resolved.

## Resume checkpoint: continuous observer wiring

Added operations_activity.py, activity-probe, preserved local collector config,
and disabled-by-default observer service. Both reviewed collectors must report
fresh complete evidence; reporting exclusions must be explicitly verified.
Observer resets quiet continuity on activity, gaps, restart and boot. Worker
rechecks activity immediately before a procedure or reboot. Replaced temporary
command-output spooling with bounded in-memory prefix capture and process-group
timeout termination. Four observer tests and two command-capture tests added.
Focused validation: 14 unit tests passed (ResourceWarning treated as error),
Python compilation and installer shell syntax passed. CI updated for new files.
These are repository tests, not Audi deployment evidence. Central demo and
meaningful-traffic collectors, tested remedies and Flowise credentials remain
unconfigured; no agent deployment or reboot occurred. Overall audit-log rotation
still needs implementation. Continue unfinished work above without treating the
observer framework as a working Audi activity collector.

## 2026-10-08: Audi model accepted; adaptive diagnostics added

User deployed dd1a131 on Audi, 14 tests passed, HTTPS authenticated Flowise call
and live worker inspection returned status reported. Key remains root-local.
Endpoint https://ai.taransvar.no/api/v1/prediction/9ee5a2fa-6be4-4fd8-94fb-4961e3c91532.
Unauthenticated request rejected Unauthorized internally (Flowise exposes HTTP500).
Fixed user Response Prompt literal braces with doubling for LangChain template.
Audi disk81%,3.7G free, logs5.5G,journal1.9G,MySQL6G. Gateway failed203/EXEC.
No crontasks log at expected path. Root cause unverified.
User requested agent gather diagnostics itself. Added fixed metadata diagnostics,
three-check/four-model-call reassessment loop, no mutation or quiet gate for
reads; model results kept as untrusted evidence. No full command args/log contents
sent. 17 focused tests, Python compilation and installer syntax passed locally.
Flowise prompt must allow diagnostic action; new revision not yet deployed.
Repairs, activity collectors and reboot acceptance remain unfinished.

## 2026-10-08 owner policy expansion

Owner explicitly requested experimental automatic demo commands and conservative
production emergency resource relief. Added action command for demo+execute+
allow_experimental_commands, bounded argv/timeout/capture, pending checkpoint,
reason/expected-result/recovery record, results available on next scheduled run.
Still quiet-gated; no trustworthy Audi collectors yet, so commands defer. Root
execution is not a sandbox and script reboots cannot be comprehensively prevented.
Production never receives generic command execution. Typed resource actions
require measured thresholds, enabled policy, explicit disposable logs/services;
core services protected. delete_log truncates authorized diagnostics in place.
RFA adapter is configurable but not supplied/verified. Deployment profile metadata
does not remove demo parts yet. Added2MiB/two-backup audit rotation.
24 local tests passed with ResourceWarning error, Python compilation and shell
syntax passed. New behavior not deployed on Audi. Current findings: rotated
syslog/kernel logs total~2.2GB plus active~1.28GB; gateway firewall.sh exists664,
not executable. Diagnostic model loop tested live by user. Cleanup/repair not run.

## 2026-10-08 model request reconciliation and API trace

Audi deployed7bb3a4;29 tests passed. Model still reports gateway failure rather
than progressing. Direct Flowise chat synthetic marker test returned correct
bash -n argv and preservedmode664; verified:false malformed task-state list.
Current upstream Flowise source keeps original question for final model, so
rephrase evidence-loss suspicion was unproven. Installed version not inspected.
Reconciled contradictory manual rules. New shared operations_prompt.py makes
explicit current owner policy authoritative, and preserves evidence/action schema.
Added schema validation/correction within bounded four-call loop. Added nonexecuting
operations_model_probe.py using root-local saved credentials and same prompt builder,
syntheticmarkerAUDI_TRACE_API_02 and exact harmless expectedargv. No action dispatch.
34 local tests, compile/shell syntax passed. Live API probe not yet run; no credentials
accessible to this session. UploadedFlowisePDF still older; source manual reconciled.
Missing quiet collectors, demo removal and RFA adapter still unresolved. Timerdisabled.


## Report-loop regression (2026-10-08)
Authenticated schema probe passes. Real worker still gives empty gateway-failure reports. Added bounded progress feedback for demo-authorized failed/non-executable gateway evidence; requests useful check or concrete prerequisite with next_check. Grants no permissions; quiet guards unchanged. Added --progression synthetic non-executing API probe. Forty local tests pass. Audi model progression remains unverified until probe is run.


## Vague next-check regression (2026-10-08)
Audi real worker diagnosed gateway and large_logs then returned 'After diagnosing the log sizes.' No action executed. Removed nonempty next_check/pending escape from known failed non-executable startup report feedback. Reports now require structured prerequisite kind/detail/next_check; local authority and quiet gates still govern actions. Recent diagnostics persist for five minutes on same boot in report/stall/defer states; invalidate after commands, resources, procedures or reboot. Exact vague report and cache expiry/invalidation regressions covered: 46 tests pass. Probe now accepts report only through structured prerequisite feedback, not arbitrary next_check. Model behavior still requires live verification; no Audi repair claimed.


## Probe schema retry parity (2026-10-08)
Audi progression probe raised ValueError for non-string goal/next_check before returning a result. Worker already retries schema failures; probe now requests schema correction within same four-call budget, prints fixed validation feedback and reports failure with returned decision if exhausted. No dispatch added. Regression tests cover invalid-then-valid and four invalid replies. 48 tests pass; live progression not yet verified.


## Read-only progression and central activity collectors (2026-10-08)
Added exact observed gateway bash -n command exemption from quiet gate under existing demo authorization; all other commands retain guards. Snapshot includes actual observer sample before model call. Historical blockers and next_check omitted from prompt. Added schema example and separate synthetic quiet/unknown probes. Central operationsActivity.php authenticated per source IP and dedicated token checks all central Demo2/3/4/5 sessions and active DEMO infections, blocks all pilots globally, missing schema fails closed. Node collector uses IPv4+IPv6 FORWARD counters; local minute reports excluded by direction, not blanket DB endpoint exemptions. Reset/reboot/gap/rules change remains unknown. Added feed installer, node configurator, deployment/coverage docs. 55 Python tests pass and shell syntax passes. PHP unavailable locally; installer lints before deployment. No live PHP/DB/HTTPS/counter tests or Audi repairs claimed. Demo1 open screens before registration/traffic and unrelated local applications not comprehensively observed; keep autonomous reboot disabled until coverage verified.


## Direct structured model isolation (2026-10-08)
Both Flowise unknown and synthetic verified-quiet probes still report startup failure rather than select command; repeated schema errors remain. Added opt-in direct OpenAI Chat Completions transport with strict decision schema, identical build_prompt input, explicit same-model configuration and separate root-local direct probe key/config. No credential reuse of Flowise key; no live worker switch. Refusals/incomplete responses fail closed, no provider fallback. Shared worker local guards unchanged. Configurator and transport installed by existing installer; --direct flag selects only separately configured probe. 60 tests pass including strict payload/parsing/provider dispatch. No direct API test possible without user's key; live comparison remains pending. Official API structured-output guide consulted; strict schema addresses shape, not correctness. Documentation explains branch diagnosis and owner-configured eventual provider switch.


## Evidence contradiction and manual reconciliation (2026-10-08)
Audi direct gpt-4o-mini strict-schema probe returned quiet_time prerequisite despite synthetic quiet_for_seconds=600. Thus Flowise bypass alone does not fix stall. Found stale installed manual claims collectors unimplemented and all commands quiet-gated. Corrected these claims and exact observed syntax-check exemption; live collector acceptance still required. Added explicit current gate assessment after manual and correction feedback after reference; fresh complete false/false sufficient duration means quiet satisfied. progress_feedback rejects quiet_time prerequisite contradicted by that evidence. Synthetic fixture now has checked_at and duration at least configured threshold; task distinguishes non-dispatch test from disabled execution policy. 66 tests pass including exact contradictory prerequisite, stale/future/incomplete/active/short/nonfinite evidence. Real dispatch still rechecks local observer; no synthetic state written to live observer. Live model rerun pending; no repair claimed.


## Completed diagnostic probe parity (2026-10-08)
Direct fixture returned diagnostic gateway_startup with an invented executable argv despite already-supplied result. No action executed. Probe now uses live worker diagnostic_feedback to challenge repeated/unknown/exhausted diagnostic requests within existing four-call budget. Unadvertised fresh diagnostic also gets feedback rather than dispatch. Unit regression repeated diagnostic then command passes; total 67 tests. No claim model repaired or autonomous behavior proven; live re-run remains pending.


## Enforce available catalog in direct schema (2026-10-08)
Audi model continued generating completed gateway_startup diagnostic after bounded feedback. Replaced static direct diagnostic/procedure choices with per-turn constraints from worker remaining catalog and eligible registry. Empty catalogs remove respective action enum and make name field null-only. Probe explicitly advertises neither; repeated diagnostic cannot be generated by compliant strict direct output. Does not force a command or remove legitimate report; safety dispatch unchanged. Flowise still uses local validation/correction because its transport cannot enforce this direct schema. 69 tests pass; live direct schema compatibility and progression pending. This is constraint enforcement, not evidence of gateway recovery.
