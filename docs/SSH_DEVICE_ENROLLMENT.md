# App-first SSH device enrollment

## Normal use

1. Open Manage → SSH in app 0.4.13 or newer. The selected gateway already determines the target server and address.
2. Enter only the Linux username on that server. No client or server dropdown is needed: the managed server is already the target.
3. Tap Request SSH registration. The request is persisted for up to 24 hours.
4. Run the installed Bash helper on the connecting device, or let its five-minute scheduled check collect the intent. It generates or reuses its dedicated local Ed25519 key and submits a signed public key. LAN WireGuard clients do not need NetBird. No per-request terminal command or pairing code is required.
5. Open https://tarasec.org/ops/agent/ and finalize the exact observed source, target server, Linux account and key fingerprint with Google and Authenticator. Multiple helpers can submit distinct candidates; approve only the intended device.
6. The gateway's existing worker installs the approved key on its next check. The app reports installed only after that worker reports completion.

An absent client helper remains explicitly waiting for the client. A submitted key remains waiting for Ops approval. An approved request remains waiting for the server until a real result is recorded. No stage implies the next one succeeded.

## Commands are available in the app

App 0.4.14 or newer includes Copy run command directly in Manage → SSH. An installed
client needs no parameters: run ~/.local/bin/tarasec-client. It reads its saved
gateways, approval service and key automatically. The scheduled check can also be
left to run normally.

Expand First-time setup, update or add this server to view/copy the complete setup
command. The app fills in the already-selected server address and adds a custom
approval-service option only when needed. No ChatGPT session or manually entered
IP/service/username arguments are needed to construct the command. Username stays
in the queued app request.

Setup downloads the standalone script, updates/installs it and adds the selected
gateway while preserving saved gateways, the existing key selection and cron jobs.
Re-running the installed client normally requires no download or install flags.
Different approval services are not silently mixed into the same saved configuration.

## One-time client installation

Download just one script; no TaraSec checkout, database, Python, gateway code or server service is installed on the client. Linux needs Bash, curl, jq, OpenSSH and a running cron service. Termux needs curl, jq, openssh and cronie, with crond running. Android may delay background work.

```bash
clear
# Machine: Acer, as the user whose SSH key will be used
curl --fail --show-error --silent --proto '=https' -o /tmp/tarasec-client.sh https://raw.githubusercontent.com/oyst12rsas/taransvar/main/misc/tarasec-client.sh &&
bash /tmp/tarasec-client.sh --install --gateway http://100.68.165.190
```

Use a writable temporary path in Termux. Supply --gateway more than once to configure multiple target gateways at installation. The client polls only those saved endpoints. Requests for an unconfigured gateway will wait until that gateway is added to the client's settings; no arbitrary endpoint from a queued request is executed or contacted.

Installation saves ~/.local/bin/tarasec-client and private user configuration under ~/.config/tarasec-client, and adds one marked cron entry without replacing unrelated jobs. Re-running installation replaces that entry idempotently. No key is created while idle. The default client-only key is ~/.ssh/id_ed25519_tarasec, created with an empty passphrase and mode 0600 for unattended polling; existing keys are not overwritten. --key may select an existing Ed25519 key with unattended access. No private key is uploaded.

Logs are in ~/.config/tarasec-client/client.log. To remove scheduling, remove the crontab line marked tarasec-ssh-client. Removing scheduling does not revoke keys already installed on servers.

## Coordinated deployment

Deploy the tarasec.org queue endpoints and API first: ops/agent/_ssh_client_queue.php, ssh-device.php, _ssh_device.php and api.php, with their existing dependencies.

Update the gateway's enrolled approval bundle from the current full taransvar checkout. The installer deploys sshClientQueue.php alongside the existing management bundle and updates the minute worker. It retains manager permissions and SSH/firewall settings.

```bash
clear
# Machine: wt-gw1
cd ~/taransvar &&
git pull --ff-only origin main &&
sudo bash misc/install_agent_approvals.sh --update-only
```

The intended server account must already be opted into the existing root-owned /etc/tarasec/ssh-key-enrollment.json policy. For a server not yet configured for key enrollment, the existing owner setup is:

```bash
clear
# Machine: wt-gw1; use its actual non-root account
sudo bash misc/install_ssh_device_enrollment.sh ubuntu
```

Install app 0.4.13 and the one-time client script after the service and gateway bundle. A source merge alone does not deploy these components. The older Python and device-initiated protocols remain compatible, but are no longer the app's primary flow.

## Identity and approval boundary

New app requests have no preselected client. The gateway takes the actual connecting address from REMOTE_ADDR and forwards it with its existing enrolled handoff credential. It accepts private LAN/VPN sources (RFC1918, CGNAT and ULA IPv6, including private mapped IPv4). Caller address claims in request bodies and forwarded headers are ignored. The request's target server remains bound to that enrolled gateway.

An idle check generates no key. When an intent exists, the helper announces its public key, receives a capability sealed to that source/key candidate, and signs the challenge. The source address is connection metadata, never installation authorization. Different helpers, including devices sharing one NAT address, receive separate candidates; one cannot consume the intent for the other. Once a candidate is approved, running or done, that intent stops accepting additional candidates. A new explicit app request can open another intent.

The submission creates only a pending exact-key proposal at TaraSec Ops. Its observed source and fingerprint are shown for final Google/Authenticator approval. Existing local manager/account/path/key guards remain mandatory. Old NetBird-peer-selected requests remain compatible for older apps, but the new flow requires no NetBird peer directory or NetBird administrator credential.

The updated gateway installer deploys both sshClientSource.php and sshClientQueue.php. Update the hosted service first, then the gateway bundle and installed Bash helper, then app 0.4.13. An already-installed client needs its script refreshed once for the key-announcement handshake; its saved gateways, user cron entry and existing key can be retained by reinstalling without --gateway:

```bash
clear
# Machine: Phone / Termux (or use a writable temporary path on Acer)
curl --fail --show-error --silent --proto '=https' -o "$TMPDIR/tarasec-client.sh" https://raw.githubusercontent.com/oyst12rsas/taransvar/main/misc/tarasec-client.sh &&
bash "$TMPDIR/tarasec-client.sh" --install
```

The dedicated key stays on the client. Existing server keys and authentication policy are retained. New approved source-mode enrollments also append the exact observed client host (/32 or /128) to SSH_ALLOWED_SOURCES and reconcile the existing timed gate. Enrollment never opens or extends the Authenticator window. Private configuration backups are retained under /var/lib/tarasec/ssh-key-sources. The service waits for a capable gateway worker before dispatching these operations. Older completed requests are not changed retroactively; request and approve a new registration once after deploying both components to apply source enrollment with the existing client key. Server-confirmed installation is not proof that a new SSH login was tested; use the existing temporary opening and normal host-identity checks for that first login. Revocation removes the exact key from the server account's authorized_keys.

## Verification

CI covers legacy peer binding and the no-selector flow: LAN/VPN source validation, independent source/key candidates behind shared NAT, exact-key proofs, final approval, repeated taps and polls, capability redaction and truthful stage reporting. Bash integration tests use real OpenSSH signatures and verify idle/no-key behavior, key reuse, mismatch rejection and cron preservation. The gateway bridge rejects unsupported methods and spoofed forwarding headers before credential access.

Live phone/gateway/client acceptance remains separate from source and CI checks. Verify that the new app queues an intent, the installed user's cron submits its signed key, Ops approval is required, and the gateway reports actual installation before declaring the rollout complete.

## Client progress and connection command

Updated clients save a private receipt when submitting a registration. On later
runs with no new intent, they check the existing receipt and report pending
approval, approved/running, completed or failed. A completed receipt confirms
installation only: open the timed SSH window and test a real login. Receipt
status is available for 24 hours; unavailable or older receipts direct the user
to Manage → SSH instead of claiming success. Registrations submitted before this
update have no local receipt; check their completion in the app.

After a new submission, a single-server client can connect with
`~/.local/bin/tarasec-client --connect`. This uses the saved username and private
key automatically, without changing SSH configuration or host-key verification.
Port 5822 is the standard default; `--port 4810` selects a different server port.

App 0.4.15 provides Copy connection command for completed registrations. It fills
the selected gateway and Linux account; its port field supports nonstandard
ports. Run the copied command on the device whose key was registered, with its
updated client helper. Explicit `--gateway URL --user USER` also works for older
completed registrations and avoids ambiguity when several gateways are saved.
The helper never opens the timed SSH gate itself.

Deploy the hosted receipt change before updating clients. No new gateway bundle
is required for receipt checking or the connection wrapper. Existing approvals
remain valid; no automatic SSH login or policy change is performed by a normal
client check.

## Repairing an older live SSH source filter

Some installations retain a TARASEC_SSH_SOURCE chain in front of the final SSH
accept. An earlier enrollment update persisted the source and reconciled the
timed Google gate but left that chain stale: the new host was still dropped.
Enrollment now inserts port-scoped RETURN rules for configured sources in an
existing source chain. It does not flush rules, add ACCEPT rules, create hooks,
or open/extend a timed window.

For an already approved source in /etc/tarasecfw.conf, update the gateway approval
bundle and run the root-only reconciliation once; re-enrollment is unnecessary:

```bash
clear
# Machine: wt-gw1
cd ~/taransvar &&
git pull --ff-only origin main &&
sudo bash misc/install_agent_approvals.sh --update-only &&
sudo python3 /usr/local/lib/tarasec/ssh_enrollment_source.py reconcile
```

Then open the timed SSH window and test from the enrolled device. Check source
chain counters and a packet capture if the connection still fails; a RETURN
rule preserves the remaining firewall checks and does not prove login success.
