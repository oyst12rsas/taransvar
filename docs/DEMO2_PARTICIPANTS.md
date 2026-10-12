# Concurrent Demo 2 participants

Several units may share a gateway and the same Node A/Node B setup. Starting a
session is limited to one live session per setup, gateway and verified unit,
rather than one session per shared NAT address.

The app first calls `appDemoSshIdentity.php` on its current gateway. The gateway
uses the actual socket peer to find the local `unit.unitId`; it ignores submitted
unit IDs and `Client-IP` headers. It returns a random proof valid for 60 seconds.
The app submits that proof to the DB's `appDemoSshSession.php` create action.
The DB redeems the proof directly from its observed, registered NetBird gateway.
Only the configured global DB addresses may resolve proofs on a gateway. The
gateway rechecks local infection policy at redemption. The app never chooses the
unit ID. This uses the testbed's existing trust in configured VPN gateway peers;
it is not a new cryptographic gateway identity protocol.

Unit IDs are gateway-local, so both gateway address and unit ID scope evidence.
Node A and Node B attribution uses exact translated tuples and owner-confirmed
gateway reports. Unresolved concurrent traffic waits for attribution; it never
selects the newest participant. Status polling reconciles late Node B callbacks
after gateway confession. Invalid/repeated attempts still require owner review.
Session ID and secret session token authorize status/cancellation independently
of the shared source address. Credentials for the non-executing Node B honeypot
remain shared (`demo` / `1`).

The SSH attempts must originate from the unit identified by the app. Running the
commands on a different laptop while supervising from a phone does not prove the
phone's unit identity. Cross-device supervision needs a separate verified source
selection flow; do not work around it by trusting a submitted unit ID.

## Deployment

Update the DB server and each participating gateway from the same revision. Run:

```sh
sudo bash misc/install_demo2_participants.sh
```

The installer validates PHP, preserves old API files and applies additive schema
migration 99 before installing the APIs. A nonstandard web root may be passed as
the first argument. No SSH/firewall restart or kernel rebuild is required. Install
an Android build containing `DemoSshClient.create(..., gatewayBaseUrl)` afterwards.
Older clients retain their one-unverified-session-per-source restriction.

For rollback, restore the three API files from the printed backup directory,
removing a file if its corresponding `.absent` marker exists. Keep the additive
schema; do not lower `setup.dbVersion`. New app builds require the new APIs, so
roll back the app with the APIs if necessary.

## Validation

`DEMO2_TEST_PORT=<disposable-MariaDB-port> php tests/demo2_participants_test.php`
executes the real gateway and DB API scripts against an isolated fixture database.
Only the DB-to-gateway HTTP boundary is simulated using responses from the real
gateway API. The tests cover two participants behind one gateway, duplicate starts,
forged unit/IP claims, proof resolution authorization, migration idempotence,
evidence isolation, immediate and late Node B attribution, and cancellation.

Live acceptance must additionally verify that the DB can reach the gateway's HTTP
identity endpoint through NetBird, the gateway recognizes the originating device,
and the normal reporting/conntrack path supplies the same local unit ID. Source
and fixture checks do not establish live testbed acceptance.
