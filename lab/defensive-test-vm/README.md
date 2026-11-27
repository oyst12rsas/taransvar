# TaraSec web-scan detection pilot

We're inviting pentesters, infrastructure operators, and university teams to help test how TaraSec detects and assesses web probes. Start with synthetic logs in your own VM, or review the design and tell us where it could miss a probe or flag ordinary traffic. This branch documents the proposed setup. The collector, shared intake, and VPS rollout still need implementation and validation.

## How the setup works

| Component | Role |
| --- | --- |
| Isolated test VM | Run local fixtures through the web-log collector and TaraSec defensive pipeline. Keep the VM off production networks and use a lab-only log destination. |
| Both TaraSec VPSs | After lab verification, deploy the same passive collector on both VPSs with distinct sensor IDs. On the NetBird VPS, read Traefik access logs. On the other VPS, use its web proxy's access logs. |
| rsyslog relay | Forward selected observations over authenticated TLS. The relay assigns the sensor identity from the authenticated sender and routes events to the pilot intake. |
| dbserver1 and Flowise | Store observations in a dedicated pilot stream and assess them through the TaraSec operations flow. Only advisory reports are accepted. Pilot events have no route to production tagging, blocking, or partner notifications. |
| Participant installations | Run locally by default. Sharing is opt-in once a dedicated intake is available. Participants need no access to dbserver1 or the TaraSec overlay. |

## First local test

1. Use a throwaway VM and feed synthetic access-log entries into a test input. Include repeated requests for a common probe path such as `/.env`, ordinary requests such as `GET /healthz`, and one malformed line. Use a documentation address such as `192.0.2.10` in fixtures.
2. Check that repeated probes generate one observation for the configured time window and carry the expected sensor ID, category, count, and timestamps. Ordinary requests and malformed lines should produce no threat observation. A malformed line should leave the collector running.
3. In the integrated lab, follow the observation ID from the collector through rsyslog to the stored event and the Flowise assessment. Check that the response passes schema validation and contains `action: "report"`. Replaying the same observation ID should produce one stored event and one assessment. An invalid assessment response should leave the event available for diagnosis and cause no state change.
4. Before enabling both VPS collectors, verify the source attribution and routing on each host. Confirm that the pilot intake rejects unauthenticated events and that a synthetic observation cannot reach enforcement or notification consumers.

Use synthetic fixtures for active testing and collect real observations passively from web services you operate or are authorized to monitor. TaraSec's public services and other participants' systems are outside the scope of this invitation.

## What participants can share

Share a false positive, a missed detection, a reproducible synthetic fixture, or an aggregate observation from a service you monitor. For a first report, include the collector version if available, the probe category, the time window and count, and the expected and actual result. Keep raw access logs local. Omit client IPs, raw request paths, query strings, cookies, credentials, request bodies, and authorization headers from shared reports. Cross-VPS counts can show whether both hosts saw the same probe category. Identifying a common source would require a separate design and is outside this pilot.

Send feedback to the person who shared this branch, or open a GitHub issue with synthetic or redacted examples. Useful cases include ordinary requests that resemble probes, duplicate delivery, and differences in access-log formats.
