# Targeted partner observation and Demo 5

The DB opens a bounded observation window after a fresh, severity 7 or higher
rejection with an actual zero tag from an exact registered partner address.
It notifies that partner through ordinary report.php. An HTTP acknowledgement
starts the grace period; it does not prove the partner repaired tagging.
Enrolled independent receivers poll for the request and upload aggregate counts
of observed connections, including untagged, tagged, unknown tag, malicious
untagged rejection and SSH access-policy denial. No payloads or identities are
uploaded. Counts use distinct connection tuples, not retransmitted packet counts.

The alarm ratio is malicious rejected untagged connections / all observed
untagged connections. Default thresholds are 20 connections, five malicious
rejections, two receivers that observed malicious traffic and a ratio of 0.5.
Only fresh samples after notification plus the grace period count toward alarms.
The latest cumulative sample replaces a receiver's previous sample; polling
does not inflate counts. Unknown or mixed tags cannot enter the denominator.
Ordinary SSH source-allowlist denial is excluded from the malicious numerator.
No samples means insufficient evidence, never healthy. A positive tag alone
does not prove every offending unit was tagged. A NAT source remains
router_or_subnode_unknown; the alarm concerns partner behavior, not attribution
of personal responsibility. Different ports are expected and included.

Production alarms create a Gatekeeper warning and appear on DB Home and the
Partner observation page. They do not automatically blacklist a production
partner. Existing bounded Demo 5 restrictions require the ratio threshold before
the selected receiver's next ordinary report can issue the demo restriction.

## DB server deployment

Apply misc/partner_observation.sql and misc/demo5_gateway_control.sql after the
existing demo5_partner_containment.sql migration. Deploy the web tree normally.
Install PHP CLI/mysql; run misc/install_partner_observation.sh as root from the
installed checkout. Edit /etc/tarasec/partner-observation.php to enable the
feature and tune thresholds; this configuration is separate from tarasecfw.conf.
Keep minimum_receivers at least two. Disabling the feature immediately stops new
observation requests and new alarm evaluation. Existing alarm records remain.

Each participating receiver must be explicitly enrolled in the existing
partnerRestrictionReceiver table with a unique token. Store the matching token
and DB HTTPS or encrypted NetBird origin in its existing root-only
/etc/tarasec/partner-restrictions.json. Run the observation installer on those
receivers too. Ensure receiver traffic recording, normalizer and cron reporting
are working; deploy the updated normalizer to /root/taransvar/perl and restart
tarasec-remote-normalizer.service. Unknown tags must be fixed before testing.

## Demo 5 gateway-controlled failure exercise

Use a dedicated test gateway, never the shared standard gateway. Enable its
existing demo5Enabled flag and enroll its IP/token. Install and load the updated
tarakernel; this adds a root-only demo5_pause_until parameter. Store a root-only
/etc/tarasec/demo5-gateway.json containing enabled=true, db_url and node_token,
then run misc/install_demo5_gateway.sh as root. This opts the gateway into
DB-registered Demo 5 commands. No control-plane token is given to the phone.

Starting Demo 5 records both the exercise and observation at the DB. The gateway
polls an authenticated feed and temporarily suppresses new TaraSec tagging for
three minutes. It does not unload tarakernel, change persistent doTagging,
disable administrative SSH protection or bypass assistance drops. The kernel
uses a monotonic deadline to restore tagging even if the worker, DB or network
fails. Release clears the pause on the next gateway poll; clock changes cannot
extend the deadline. A configured pause is displayed separately from actual
receiver evidence. Old kernels return a visible error rather than pretend to
pause. Expiry preserves the prior configured tagging policy; it does not turn
an originally disabled doTagging setting on.

For a repeatable ratio exercise, explicitly configure two independent decoy
receivers in demo5ObservationTarget(routerId,receiverIp,receiverPort). Port 4205
is the suggested example. On each receiver run
misc/install_demo5_rejection_target.sh 4205. It refuses a listening port and
adds only an INPUT hook to its own decoy rejection chain, leaving forwarding,
NAT, VM rules and other listeners intact. The explicit decoy rejection is
classified as severity 7 by the normalizer and follows ordinary report.php
handling. Existing TARASEC_ rsyslog forwarding and traffic collection are
required. It is an operator-designated honeypot policy, not a synthetic DB row.
The helper is temporary; reboot or removing its INPUT hook ends the decoy test.

The app sends one bounded connection to each configured decoy per button press.
Wait for pause_configured, send initial tests to establish reports and partner
notification, then wait for its grace period and send ten further tests to the
two decoys. Observe fresh counts, the DB alarm, and the selected receiver's next
report triggering the existing demo restriction. Distribution remains separately
pending/applied/error/released; a timeout alone proves none of those steps.
If thresholds are not reached before pause expiry, the result is insufficient
evidence and tagging resumes. Release the exercise before starting another.

## Limits and validation

Receiver counts are authenticated receiver assertions, not independent packet
captures. Compromised enrolled receivers can lie; independent receivers reduce
but do not eliminate that risk. The existing traffic table stores flow state,
not an immutable packet ledger. Mixed tags, records spanning window boundaries
and capped scans are excluded conservatively, so incomplete collection may
prevent an alarm. The ratio represents traffic seen by participating receivers,
not all traffic on the Internet. IPv4 TCP is the supported test path.

Run tests/partner_observation_test.php and the Demo 5 migration/API tests; lint
the PHP workers and endpoints, build the kernel module, and compile the app.
Live verification needs the above enrollment, migrations, normalizer deployment,
and independent receiver observations. No live gateway is changed by committing
this code.
