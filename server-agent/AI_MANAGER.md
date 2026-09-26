# Optional AI server manager pilot

This pilot extends the outbound-only status framework without allowing the database or model to run commands. It follows three separate stages:

1. **Observe:** bounded host metrics, allowlisted systemd status, and 40 recent journal lines per allowlisted service. Common credential patterns are redacted.
2. **Propose:** the OpenAI Responses API returns a structured diagnosis. Its action vocabulary is only `none` or `restart_service`, and it has no execution tool.
3. **Approve and apply:** a local administrator approves one indexed action. The resulting random approval expires after ten minutes, works once, and is validated again locally before execution.

Version 1 cannot install packages, edit files, run a shell, change SSH, firewall, routing, NetBird/WireGuard, execute SQL, deploy code, reboot, or restart a unit absent from `SERVICE_ALLOWLIST`.

## Authority mode

Set `AI_AGENT_MODE` in `/etc/tarasec-server-manager.conf`. New installations default to `conservative`.

- `disabled`: skip AI assessment and refuse approval or application.
- `conservative`: diagnose and propose; require local human approval before changes.
- `defensive`: reserved for automatic active-threat containment as those tools are implemented.
- `autonomous`: reserved for broad locally executed repair authority as those tools are implemented.

Unknown values produce a warning and use `conservative`. The mode is reread before approval and application, and is written into snapshots, proposals, approvals, and audit events. The agent cannot change its own mode.

**Current implementation status:** all enabled modes still expose only `none` and an approved `restart_service` action. Selecting `defensive` or `autonomous` does not yet grant additional commands. This avoids presenting planned authority as implemented behavior.

The public definitions and current status are maintained at <https://tarasec.org/ai/server-manager/>.

## Pilot installation

Run on the **pilot Ubuntu server**, not the DB server:

```bash
clear
cd /path/to/taransvar/server-agent
sudo ./install-server-manager.sh
sudoedit /etc/tarasec-server-manager.conf
sudoedit /etc/tarasec-server-manager.env
sudo -u tarasec-manager /usr/local/lib/tarasec/tarasec-server-manager.py snapshot
sudo systemctl start tarasec-server-manager.service
sudo journalctl -u tarasec-server-manager.service --no-pager -n 100
```

The installer does not enable the timer. After reviewing the first proposal:

```bash
clear
sudo systemctl enable --now tarasec-server-manager.timer
```

Review `/var/lib/tarasec-server-manager/proposal.json`. To approve proposed action index 0 and then apply it:

```bash
clear
sudo -u tarasec-manager /usr/local/lib/tarasec/tarasec-server-manager.py approve PROPOSAL_ID 0
sudo /usr/local/lib/tarasec/tarasec-server-manager.py apply APPROVAL_ID
```

The first command only creates a ten-minute approval. The second performs exactly that approved restart. Review `audit.jsonl` in the state directory for the local audit trail.
