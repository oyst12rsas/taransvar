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
