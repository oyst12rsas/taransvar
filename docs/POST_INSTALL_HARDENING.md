# Secure the server right after installation

Recommended by AI; not yet reviewed or authorized by the TaraSec team.
Each step was run and tested on a disposable Ubuntu 24.04 node (2026-11-24).

A fresh install usually leaves SSH on port 22, open to everyone, with password
login. Do these steps **in order, as soon as the installer finishes**. Each step
adds a layer on top of the previous ones; none replaces another. At the end:

| Layer | Effect |
|---|---|
| SSH key **and** password | A stolen password or a stolen key alone is not enough |
| Source restriction | Only your own address(es) can reach the admin SSH port |
| Google + Authenticator gate | The admin port is closed until you approve a short opening |
| Private port + honeypot | Port 22 and decoy ports answer with a honeypot that refuses every login and reports the attacker |

## Before you start: keep a way back in

* **Recovery console.** Have VM/serial/local console access that does not use
  SSH (for example the hypervisor console). If an AI assistant runs on that
  console, it can undo a mistake for you.
* **Session A.** Open one SSH session and leave it open the whole time. Changing
  sshd or the firewall does not drop connections that are already established.
* **Session B.** After every change, test with a **fresh** connection. Close
  session A only after session B works under the new settings.

Every live change below is made with a rollback timer: if you do not confirm, it
undoes itself.

## 1. Install your SSH key

On your own computer (not the server):

```bash
ls ~/.ssh/*.pub || ssh-keygen -t ed25519      # set a passphrase
ssh-copy-id -i ~/.ssh/id_ed25519.pub -o PubkeyAuthentication=no ADMIN_USER@SERVER
ssh -o PasswordAuthentication=no -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519 ADMIN_USER@SERVER
```

The last command must log you in without the account password. On the server,
`sudo journalctl -u ssh | grep 'Accepted publickey'` proves the key was used.

Common problems:

* `Too many authentication failures`: your client offered several other keys
  first and hit `MaxAuthTries`. Add `-o IdentitiesOnly=yes -i <key>`.
* `Invalid user ...` in the server log: ssh used your *local* user name. Use
  `ADMIN_USER@SERVER` or set `User` in `~/.ssh/config`.

## 2. Require key and password, no root login

Only after step 1 works. On the server:

```bash
sudo cp misc/sshd-hardening.conf.example /etc/ssh/sshd_config.d/10-tarasec-hardening.conf
sudoedit /etc/ssh/sshd_config.d/10-tarasec-hardening.conf   # set AllowUsers
sudo sshd -t
sudo systemd-run --unit=tarasec-sshd-rollback --on-active=10min \
  /bin/bash -c 'rm -f /etc/ssh/sshd_config.d/10-tarasec-hardening.conf; systemctl reload ssh'
sudo systemctl reload ssh
```

The `10-` prefix matters: sshd uses the first value it reads, so a later file such as
`50-cloud-init.conf` cannot turn passwords back on.

Test from a fresh session: the key is accepted **and** the password is asked.
`ssh -o PubkeyAuthentication=no ADMIN_USER@SERVER` must fail with
`Permission denied (publickey)` and never reach a password prompt. Then keep the change:

```bash
sudo systemctl stop tarasec-sshd-rollback.timer
```

Key only (no password) is a valid owner choice; edit the two lines described
in the example file. `sudo` still asks for the account password either way.

## 3. Allow admin SSH only from your address

Set the addresses in `/etc/tarasecfw.conf` (comma-separated IPv4/IPv6 or CIDR,
for example your NetBird address):

```ini
SSH_PORT=22
SSH_ALLOWED_SOURCES="100.64.0.10"
```

Install the filter. It drops other sources on both IPv4 and IPv6 and passes
allowed ones on to the next layers (it never accepts on its own, so it cannot
bypass the Google gate). A timer reapplies it at boot and every two minutes
because NetBird rebuilds INPUT. It follows `SSH_PORT` when the port changes.

```bash
sudo install -m 0755 misc/ssh_source_filter.sh /usr/local/sbin/tarasec-ssh-allow.sh
sudo install -m 0644 misc/tarasec-ssh-allow.service misc/tarasec-ssh-allow.timer /etc/systemd/system/
sudo systemd-run --unit=tarasec-source-rollback --on-active=10min /bin/bash -c \
  'systemctl disable --now tarasec-ssh-allow.timer; for T in iptables ip6tables; do $T -S INPUT | grep -- "-j TARASEC-SSH" | sed "s/^-A /-D /" | while read -r r; do $T $r; done; done'
sudo systemctl daemon-reload
sudo systemctl enable --now tarasec-ssh-allow.service tarasec-ssh-allow.timer
sudo iptables -S TARASEC-SSH; sudo ip6tables -S TARASEC-SSH
```

Test a fresh login from your address, then
`sudo systemctl stop tarasec-source-rollback.timer`.

## 4. Google + Authenticator gate

1. In session A run the enrollment helper and type your Google email when asked
   (only a SHA-256 hash of it is sent):

   ```bash
   sudo python3 /usr/local/lib/tarasec/enroll_google_manager.py
   ```

2. Sign in at https://tarasec.org/ops/agent/ with that Google account, set up
   Authenticator, and check that your server's nickname is listed.
3. Enable the gate as described in [google-ssh.md](google-ssh.md#enable-and-verify)
   (`SSH_GOOGLE_REOPEN_ENABLED=yes`, then `ssh_google_window.py reconcile`).
4. Test: a fresh ssh is refused; approve **Open SSH temporarily** with your
   Authenticator code; a fresh ssh works (key + password); after the window
   expires a fresh ssh is refused again while open sessions stay connected.

## 5. Private port and honeypot on 22

Needs a working step 4. In session A:

```bash
cd ~/taransvar && git pull --ff-only
sudo python3 misc/ssh_port_setup.py
```

* **Port prompt:** type your own port in 1000–5000 and write it down. If you
  press Enter, a random port is chosen and not shown; find it later with
  `sudo grep ^SSH_PORT= /etc/tarasecfw.conf`.
* **Decoys:** Enter keeps `22,1000-5000`. Your real port is excluded automatically.
* **Console prompt:** Enter (= no) arms a ten-minute automatic rollback that you
  cancel by confirming from a new login on the new port.

Afterwards: port 22 and the decoys reach the honeypot (`reject-all`: every
password is refused, every attempt is logged). While the gate is closed, your
real port is redirected to the honeypot too, so to an attacker it looks like
another decoy. Set `Port` in your `~/.ssh/config`, open the gate, and log in.

**Host key warning.** The honeypot has its own host key. If ssh asks you to
accept an unknown key for a server you already use, answer **no**: you are
talking to the honeypot (usually because the gate is closed). Compare against
the real fingerprint, shown on the server with
`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`. If you accepted a honeypot
key, remove it with `ssh-keygen -R "[SERVER]:PORT"`.

See the honeypot log: `sudo journalctl -u tarasec-ssh-honeypot.service -o cat | grep '^{'`.

## Next steps

* Automatic security updates: `sudo apt install unattended-upgrades`.
* Login report and app alerts: `misc/install_ssh_login_report.sh`.
* Review other open ports (`sudo ss -ltnp`), for example the web server on 80.
* Protect the Google account with a passkey or hardware key.
* Continuing priorities: https://tarasec.org/safety/#hardening-priorities
