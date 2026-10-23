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

Each feature asks **yes/no/never**. Yes applies it now, no postpones it, never
suppresses subsequent prompts until `--review-declined`. Installed features
remain installed when declined; never is not an uninstall. Updates are offered
again because installed files can drift and the checkout can change. Database,
runtime, managed Taralink and Gatekeeper deploy first, followed by Google enrollment, temporary
SSH protection, public keys and the SSH/honeypot migration. Additional features
belong in the explicit feature registry in `misc/standard_upgrade.py`.

The interactive command coordinates existing reviewed installers. It does not
run the broad legacy OS installer or experimental kernel compiler automatically.
Kernel builds and initial hotspot/network provisioning remain separate pending
safe verified upgrade adapters. Existing database migration timers remain under
their current owner policy; no/never affects this command, not those timers.

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
