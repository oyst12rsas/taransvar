# Demo 3 exercise isolation

Starting Demo 3 no longer closes existing exercises or deletes their assistance
requests. Each exercise keeps its controller token, participants, request category
and release events. Gateway delivery also preserves other categories and targets,
including inactive release tombstones that prevent delayed starts from reactivating
completed containment. Production requests are unchanged.

Containment is enforced per target IP/port, rather than per group. Demo 3 uses port
zero, so only one unfinished exercise may use a particular target. A conflicting
create returns `target_exercise_already_active` with a join-or-wait message. It
returns the existing session ID only when the exercise is public or the submitted
group code grants access. The app refreshes the exercise list after a failed start.
Different targets may have independent exercises. A per-target MariaDB named lock
serializes simultaneous creators without replacing another exercise.

Creating another exercise may advance the same target's existing session through
its normal due release/observation expiry. It does not force early release or
closure. Manual releases lock the session transactionally and require its controller
token. Completion stays visible until all participants leave or the existing
ten-minute observation period ends; new starts must wait until that normal closure.

## Deployment

From an updated checkout, run on the DB server and every participating gateway:

```sh
sudo bash misc/install_demo3_isolation.sh
```

Pass a nonstandard web root as the first argument if necessary. The installer
validates PHP and saves the previous two API files. No database migration or
taralink/kernel rebuild is required. Deploy gateway `partnerRequest.php` as well as
the DB endpoint: an older gateway would still delete other exercises' requests.
Rebuild/install the updated Android app for the readable conflict message and list
refresh. The backend protection also applies to older app builds.

To roll back the APIs, restore `appDemoAssistance.php` and `partnerRequest.php` from
the printed backup directory. No schema rollback is needed.

## Verification

Run `DEMO3_TEST_PORT=<disposable-MariaDB-port> php tests/demo3_isolation_test.php`.
The real API/SQL tests cover independent targets, conflicting and simultaneous
starts, shared-IP participants, controller isolation, release/leave behavior,
private group access, gateway request preservation, out-of-order deliveries and
production request preservation. Fixture names/codes are ASCII; minimal PHP test
runtimes may use the test-only `mb_strlen` fallback.

Live acceptance should confirm that a second user can join the first exercise,
creating another exercise for that same target preserves the first, and release
reaches each gateway through the normal taralink distribution path. These fixture
tests do not establish live network enforcement or Android build acceptance.
