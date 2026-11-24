# Let an AI manage your server, and take it back at any time

This guide shows how to give an AI assistant (here Claude Code) root access to a
Linux server, such as a TaraSec router, while you stay the owner. You can remove
the AI at any moment, and then check that it has no way back in.

It was first written and tested on a TaraSec router VM, but the core steps work
on any Ubuntu/Debian machine. TaraSec-specific checks are added automatically
when TaraSec is installed.

## How it works

| | Your **owner** account | The **AI** account (`claude`) |
|---|---|---|
| Who knows the password | Only you | Nobody needs it |
| Root access | Always (sudo group) | Only while one file exists: `/etc/sudoers.d/90-ai-operator` |
| Removed by | — | `revoke`, or simply deleting that file |

`claude/ai_operator_access.sh` has four commands:

- **grant**: gives the AI root through that one file, optionally for a limited
  number of hours. It refuses to run if you don't have your own owner account,
  so you can't lock yourself out.
- **revoke**: removes the file, ends all of the AI's sessions (including root
  shells it started), and with `--lock` also locks the AI account and moves its
  credentials (SSH keys, Claude and GitHub logins) into a root-only folder.
- **audit**: lists anything that could still give the AI access afterwards:
  extra sudo rules, admin-group membership, a second root account, SSH keys,
  leftover root processes, and every important file changed while the AI had
  root. On TaraSec nodes it also checks the Google manager list, the approval
  service address, the node credential and NetBird settings.
- **status**: shows whether a grant is active, when it expires and which owner
  accounts exist.

**Be realistic about root.** While the AI has root it can change anything,
including this script. The audit catches the usual ways to keep access, but no
check can prove a machine is untouched after someone else had root. If you need
certainty, reinstall the machine and restore your configuration. This setup is
for machines where that risk is acceptable, such as a router with no secrets.

## 1. Before you start

You need **console access** that doesn't depend on SSH: a physical keyboard
and screen, or your VM provider's web console. Check that it works now.

## 2. Create your own owner account

Log in on the console with your current admin account and run:

```bash
sudo adduser owner            # choose a strong password; never give it to the AI
sudo usermod -aG sudo owner
```

Log out, log in on the console **as `owner`**, and check that `sudo -v` asks
for your password and succeeds. From now on, do all owner steps as `owner`.

If the AI runs under your old admin account, give the AI its own account
instead: `sudo adduser --disabled-password claude`. If the AI already uses
`claude` (as on the test VM), keep it; `grant` removes it from the sudo group
so the grant file becomes its only root access.

## 3. Get a trusted copy of the script

Run the script from a copy the AI account cannot edit. A root-owned clone works:

```bash
sudo git clone https://github.com/oyst12rsas/taransvar.git /opt/tarasec-owner
sudo git -C /opt/tarasec-owner log -1 --format='%H %s'   # note the commit you reviewed
alias ai-access='sudo bash /opt/tarasec-owner/claude/ai_operator_access.sh'
ai-access status
```

As root, the script refuses to run if its own file, or any folder above it, can
be written by other users.

## 4. Grant access

```bash
ai-access grant              # until you revoke
ai-access grant --hours 8    # or revoke automatically after 8 hours
```

The output names the owner accounts that keep root, and how to revoke.

Then start the AI as the `claude` user, for example in the repository checkout:

```bash
sudo -iu claude
cd ~/taransvar && claude
```

Tell it what you want done. Contributor rules in `AGENTS.md` ask it to inspect
first, check recovery access before touching SSH or the firewall, prepare a
rollback, and report what it verified and what it didn't.

## 5. Revoke access

As `owner`:

```bash
ai-access revoke             # remove root, end the AI's sessions, then audit
ai-access revoke --lock      # also lock the account and quarantine its logins
```

**If you don't trust the script any more** (the AI had root, so it could have
changed `/opt/tarasec-owner`), these commands do the essential part by hand:

```bash
sudo rm -f /etc/sudoers.d/90-ai-operator
sudo gpasswd -d claude sudo 2>/dev/null
sudo loginctl terminate-user claude; sudo pkill -KILL -u claude
sudo usermod -L -e 1 claude   # optional: lock and expire the account
```

Then make a **fresh** clone to a new folder and run the audit from it:

```bash
sudo git clone https://github.com/oyst12rsas/taransvar.git /opt/tarasec-audit
sudo bash /opt/tarasec-audit/claude/ai_operator_access.sh audit --since "2026-11-24 10:00"
```

`--since` is when you granted access. Without it the audit uses the time
recorded by `grant`.

## 6. Read the audit

Each line starts with a level:

- **FAIL**: the AI may still have root. Fix it; the line says how.
- **REVIEW**: something changed or exists that you should recognise, such as a
  file the AI edited while doing its job, an SSH key, a TaraSec manager. Most
  will be legitimate work; confirm each one.
- **INFO / OK**: for your information.

Add `--deep` to also compare installed package files with their checksums
(`dpkg --verify`). It takes longer and only catches changed packaged files.

The audit exits with 0 when there are no FAIL lines.

## 7. Give access again later

If you used `--lock`, unlock first, then grant:

```bash
sudo usermod -U -e '' claude
ai-access grant
```

Quarantined credentials are kept in `/var/lib/ai-operator/quarantine-*`. Move
them back to the AI's home only if you still trust them; logging the AI in
again is cleaner.

## TaraSec nodes

TaraSec's SSH protections (SSH closed by default, timed reopening after Google
sign-in and TOTP approval, the honeypot, tagging and blocking) guard against
logins **from outside**. An AI running in a session on the node itself is not
affected by them; its root access is controlled only by the grant above. After
revoking, the TaraSec part of the audit checks the settings an AI could use to
come back through those protections:

- `/etc/tarasec/access.conf`: Google managers who can approve SSH openings.
- `/etc/tarasec-server-manager.conf`: which approval service the node trusts.
- `/etc/tarasec/agent-node.token` and `agent-enrollment.json`: node identity.
- `/etc/netbird/config.json`: the encrypted management network.

## Files in this folder

| File | Purpose |
|---|---|
| `setup.md` | This guide |
| `ai_operator_access.sh` | grant / revoke / audit / status |
| `checks.d/tarasec.sh` | TaraSec-specific audit checks; add more `*.sh` checks here |
| `tests/test_ai_operator_access.py` | Tests on a fake root, no root needed: `python3 -m unittest discover -s claude/tests` |

Claude-specific scripts and settings live in this folder. Improvements found
while an AI manages a node go into the shared TaraSec code and documentation
(see `AGENTS.md`), so every node benefits.

## Test status

| Step | Status |
|---|---|
| Unit tests on a fake root (grant, revoke, lock, audit, TaraSec checks) | Passing |
| Live grant → revoke → audit on the test VM | Not yet run |
| Expiry timer (`--hours`) on a live host | Not yet run |
| Manual emergency revoke | Not yet run |
