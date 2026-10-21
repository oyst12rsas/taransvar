# Minute activity overview

Gatekeeper → Units shows an administrator-only, automatically refreshed overview:
active client traffic, missing reports (over 200 seconds or never received), and
other reporting units. Reports older than 130 seconds are labelled stale; their
activity is never treated as current. SSH sessions include usernames and terminal
names, including `notty`; these are authenticated OpenSSH session processes, not
listening sockets or unauthenticated connections. Idle sessions remain listed.

Deploy `misc/` to the installed Perl runtime on each reporting unit and `html/`
to the web root on the DB server, following `misc/compile.pl` deployment output.
No database migration is required; telemetry is carried in status JSON.

The read-only collector requires Python 3, iproute2, procps, and conntrack.
On Ubuntu/Debian install a missing conntrack tool with `sudo apt install conntrack`.
Packet accounting must be enabled (`sysctl net.netfilter.nf_conntrack_acct`).
If disabled, an owner can enable it with
`sudo sysctl -w net.netfilter.nf_conntrack_acct=1`; counters apply to newly created
connections. Persist that setting in the owner's sysctl configuration if desired.
The collector does not change firewall rules or enable accounting itself.

Client activity uses packet counter changes between minute samples of routed
conntrack entries. Both original endpoints must be nonlocal; every configured
local IPv4/IPv6 address is excluded. Consequently OUTPUT heartbeats, polling,
maintenance, and INPUT connections to local services cannot add active clients.
NAT reply endpoints do not disqualify forwarded outbound client connections.
A source stays active for five minutes after an observed counter change. Distinct
source addresses are counted, rather than people. The legacy `usr` field now
carries this count only when known; the old hardcoded 10.100.0.0/16 query is gone.

This is a passive, sampled view: flows that start and disappear entirely between
samples can be missed, as can inbound DNAT connections addressed to the router.
It does not establish that a machine is safe to reboot. Missing tools, failed
collection, missing accounting, an initial sample, or a sampling gap over 130
seconds produce unknown, never an asserted zero. Old nodes show unknown in the
new overview. Existing report freshness semantics are unchanged.

Validation: `python3 -m unittest discover -s misc -p test_unit_activity.py`.
On a deployed gateway, wait for two reports, send only status reports and check
zero active clients. Then generate sustained routed client traffic and verify the
count increases; stop traffic and verify expiry after five minutes. Open an SSH
terminal and an SSH nonterminal command and verify their sessions appear; close
them and verify disappearance. Stop reporting and verify stale/missing grouping.
