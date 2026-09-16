# TaraSec generic server status agent

This directory is a small, fork-friendly framework for server owners who want a VM or ordinary Linux server to report health to a TaraSec DB server without installing the TaraSec gateway/kernel stack.

## Security model

The agent is deliberately **outbound-only**. It opens no listening port, accepts no commands from the DB server, and does not execute instructions returned by the receiver. The default report interval is 120 seconds with random jitter. The receiver should identify/authorize the source (for example by a private VPN address or another registration mechanism) and rate-limit the status endpoint.

This is status telemetry, not a remote-management agent and not a traffic generator. Reports are small and bounded. The script uses one HTTP POST per interval and refuses intervals below 60 seconds. These constraints are intentional so a fork is not useful as a high-rate DoS client.

## Report format

The JSON is compatible with Gatekeeper's existing status concepts while identifying itself explicitly as a generic server:

- `agent=tarasec-server-agent`
- `agentVersion`
- `role=vm` (configurable)
- `hostname`
- `uptimeSeconds`
- `load1`, `load5`, `load15`
- `memoryAvailableKb`, `memoryTotalKb`
- `diskRootUsedPercent`
- `updates` when available
- `bootReq`

It intentionally does not collect arbitrary files, application data, command output, credentials, process command lines, or user data.

## Install

Copy `tarasec-server-status.py` and `tarasec-server-status.conf.example`, edit the configuration, then use the included systemd service/timer. The DB URL should normally be reachable through a private/partner network or HTTPS.

The DB server must register the VM as an allowed reporting source. Do not expose an unauthenticated auto-registration endpoint: unknown senders should be rejected rather than being allowed to create dashboard entries.

## Forking

Server owners can fork this directory and add bounded health fields relevant to their service. Keep the following invariants: outbound only; no remote command execution; minimum 60-second interval; bounded request size; short network timeout; no automatic target discovery; one configured DB endpoint.