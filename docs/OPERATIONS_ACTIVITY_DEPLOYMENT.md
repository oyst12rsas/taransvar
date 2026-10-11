# Operations activity pilot

The demo pilot authorizes experimental commands through owner policy. Only the
exact `/usr/bin/bash -n PATH` or `/bin/bash -n PATH` form on the regular gateway
script identified by `gateway_startup` bypasses quiet-time gating. This checks
syntax without executing the script. Other commands, including chmod and service
restarts, still require the continuous observer. The model sees observer evidence
before choosing actions; execution rechecks it. Old blockers/next_check are omitted
from the historical task state passed to the model; goals and other evidence remain.

Install the usual operations agent revision on Audi. The installer installs both
collector executables but preserves configuration and does not enable timers.

On the central DB/web server, from this revision's repository checkout:

```
sudo bash misc/install_operations_activity_feed.sh 100.68.153.251 /ACTUAL/TARASEC/WEBROOT
```

Use the actual observed source IP of Audi's HTTPS request, not a guessed NAT or
proxy address. The script creates a root-only node key and a root/www-data-readable
hash registry. Transfer the key securely to Audi; never paste it into chat. Configure
an HTTPS route to `/script/operationsActivity.php`. The endpoint trusts REMOTE_ADDR,
not forwarded client headers, and requires both the node token and matching source
address. If a proxy changes the source address, establish the correct trusted web
server source mapping before enrollment; do not disable source checks.

On Audi, from this revision's checkout:

```
sudo bash misc/configure_operations_activity.sh https://DB-HOST/script/operationsActivity.php /SECURELY/TRANSFERRED/NODE.key
sudo /usr/local/lib/tarasec-operations/activity-probe
```

The feed requires the central DB role and all current Demo 2, 3, 4 and 5 session
tables plus active DEMO infection records (Demo 1). Any active session blocks all
pilot nodes, intentionally conservative. Missing schema/auth/network remains unknown;
it never becomes an empty session list. Demo 1 has no central session lease: its
active DEMO infection markers and actual forwarded traffic are observed. This
cannot identify a merely open app screen before registration or traffic begins.
New demo mechanisms must extend the feed before claiming coverage.

The traffic collector sums IPv4 and IPv6 FORWARD counters. Locally originated
minute reports and observer requests do not enter FORWARD, so no blanket exclusion
of DB HTTP traffic is used. Forwarded reports still count as traffic. Initial sample,
reboot, counter reset, rule change, or a sampling gap resets coverage. Unsupported
firewall backends, unavailable counters or stale central responses remain unknown.
Requires iptables-save and ip6tables-save compatible with the active firewall.
Coverage is forwarded packets plus registered central demos, not all local application
use. Verify this on the actual gateway before enabling autonomous reboot. This pilot
does not enable reboot or the operations timer.

Run both non-executing probes after installation:

```
sudo python3 /usr/local/lib/tarasec-operations/operations_model_probe.py --progression
sudo python3 /usr/local/lib/tarasec-operations/operations_model_probe.py --progression --quiet-verified
```

The second probe's quiet evidence is synthetic and used only within the probe;
it is never written to the observer or live worker. No returned action executes.
The first accepts a useful command or structured prerequisite; the second requires
a command. PHP lint occurs before feed installation. Live DB queries, HTTPS routing,
source-IP enrollment, active firewall counters and real model responses require
verification on deployment; unit tests cannot establish those facts.


## Existing NetBird-only HTTP deployment

For dbserver1's existing port-80 deployment, configure the node with
http://100.68.126.0/script/operationsActivity.php and --netbird-http. This is
an explicit owner-local option, not an HTTPS downgrade or fallback. Each collection
requires a literal IPv4 address in the overlay range and verifies `ip -j route get`
selects wt0 before reading/sending the token. HTTP proxy environment is ignored and
redirects are disabled. A missing or different route fails closed. HTTPS remains the
default for other deployments. Source-IP token binding on the feed remains unchanged.
Transfer the root-only generated key using the configured admin SSH port; do not use
a honeypot port or copy secrets into chat. Remove any temporary user-readable transfer
copy after successful installation on Audi.
