<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SSH hardening and honeypots | TaraSec</title>
    <style>
        :root { color-scheme: light dark; --accent:#e87521; --panel:#172033; }
        body { margin:0; font:16px/1.6 system-ui,sans-serif; background:#0b1120; color:#e5e7eb; }
        main { max-width:980px; margin:auto; padding:32px 20px 64px; }
        h1,h2 { line-height:1.2; } h1 { color:#fff; } h2 { margin-top:2rem; color:#ff9b50; }
        a { color:#ffad70; } .lead { font-size:1.15rem; color:#cbd5e1; }
        .card { background:var(--panel); border:1px solid #334155; border-radius:12px; padding:20px; margin:18px 0; }
        .good { border-left:5px solid #22c55e; } .advanced { border-left:5px solid var(--accent); }
        code,pre { background:#050914; border-radius:6px; } code { padding:.15rem .35rem; }
        pre { padding:16px; overflow:auto; border:1px solid #263349; }
        table { width:100%; border-collapse:collapse; } th,td { padding:10px; border-bottom:1px solid #334155; text-align:left; vertical-align:top; }
        .warning { color:#fbbf24; }
    </style>
</head>
<body><main>
    <p><a href="/firewall/">← Firewall configuration</a></p>
    <h1>SSH hardening and honeypots</h1>
    <p class="lead">TaraSec separates genuine administration from decoy traffic: real OpenSSH moves to a restricted alternative port, while one or more public SSH-looking ports can collect attack telemetry.</p>

    <h2>Required foundation</h2>
    <div class="card good">
        <ul>
            <li>Allow the real SSH port only from named administration IP addresses or networks.</li>
            <li>Use key authentication; disable password and root login for genuine OpenSSH.</li>
            <li>Keep a console open while changing ports and use TaraSec's rollback timer.</li>
            <li>Never reuse the genuine server's host keys in a honeypot.</li>
        </ul>
    </div>

    <h2>Lightweight TaraSec SSH simulator</h2>
    <p>The bundled simulator is suitable for small nodes and demonstrations. It completes an SSH handshake, records connection and client metadata, and returns canned shell-like responses. It exposes no host files, directories or accounts and never invokes a real command or shell. Plaintext passwords are not logged.</p>
    <pre><code>SSH_PORT="5822"
SSH_ALLOWED_SOURCES="100.68.10.7"
SSH_HONEYPOT="on"
SSH_HONEYPOT_PORTS="22"
SSH_HONEYPOT_AUTH_MODE="accept-all"
DBSERVER="100.68.126.0"</code></pre>
    <p>Port 22 is reserved for the honeypot/demo service; genuine administrative SSH uses <code>SSH_PORT</code>. Additional decoy entries can be comma/space separated and may contain inclusive ranges. TaraSec keeps ranges compact and redirects them to one honeypot listener. It rejects collisions with <code>SSH_PORT</code> and permits at most 64 individual entries or ranges.</p>

    <table>
        <thead><tr><th>Authentication mode</th><th>Behaviour</th></tr></thead>
        <tbody>
            <tr><td><code>reject-all</code></td><td>Logs attempts but never opens the simulated shell.</td></tr>
            <tr><td><code>accept-all</code></td><td>Accepts every password into the non-executing simulated shell.</td></tr>
            <tr><td><code>password</code></td><td>Accepts only a configured SHA-256 hash of a dedicated fake password.</td></tr>
        </tbody>
    </table>
    <p>Generate a hash without placing the fake password in shell history:</p>
    <pre><code>read -rsp 'Fake honeypot password: ' HP_PASS; echo
printf '%s' "$HP_PASS" | sha256sum
unset HP_PASS</code></pre>
    <p>Copy only the 64-character hash to <code>SSH_HONEYPOT_PASSWORD_HASH</code>. Never use a real password.</p>

    <h2>Central database forwarding</h2>
    <p>When <code>DBSERVER</code> is configured, setup installs reliable rsyslog forwarding over TCP/5514 with a disk-backed queue. The DB server stores the original record in <code>syslog</code>, normalizes attack fields into <code>syslogThreat</code>, and queues higher-severity login/command events for AI assessment.</p>
    <p>Events include sensor/node, source and destination addresses and ports, session ID, SSH client version, username, password length, authentication result, commands entered in the fake shell, severity and action. They do not include plaintext passwords.</p>

    <h2>App SSH demonstration</h2>
    <p>An automated app demonstration uses a short-lived DB session and a shared Node B credential. The DB keeps that credential unchanged while any sessions on Node B remain active. TaraSec correlates each connection's full tuple through conntrack, so one Node B and one demo port can serve many concurrent classroom sessions.</p>
    <p>An expected Node A rejection may create a reversible demo infection; a correct Node B attempt may clear only that same demo infection. A wrong attempt requires the normal hotspot-owner clearing process.</p>
    <p>Non-demo evidence always takes precedence: a failure outside the active demo context creates a regular infection, and no successful demo challenge may clear it.</p>
    <p><a href="/demo/">See the complete, self-hostable SSH attribution demo.</a></p>

    <h2>Cowrie: the richer option</h2>
    <div class="card advanced">
        <p><a href="https://cowrie.readthedocs.io/">Cowrie</a> is preferred for research sensors and exposed systems that need deeper interaction. It provides a much more complete SSH/Telnet environment, filesystem simulation, session replay, downloaded-file capture and richer structured events.</p>
        <p>TaraSec's <code>tool/cowrie_send_log.pl</code> adapter and remote normalizer feed Cowrie JSON into the same <code>syslog</code>/<code>syslogThreat</code> pipeline.</p>
    </div>
    <table>
        <thead><tr><th></th><th>TaraSec lightweight</th><th>Cowrie</th></tr></thead>
        <tbody>
            <tr><td>Resources/setup</td><td>Small and quick</td><td>Heavier and more involved</td></tr>
            <tr><td>Interaction</td><td>Small canned shell</td><td>Detailed filesystem and command emulation</td></tr>
            <tr><td>Evidence</td><td>Connections, login attempts and commands</td><td>Rich sessions, replay and file artefacts</td></tr>
            <tr><td>Recommended use</td><td>Baseline protection, demos, small nodes</td><td>Dedicated research and production sensors</td></tr>
        </tbody>
    </table>

    <h2>Safety boundary</h2>
    <p class="warning">A honeypot is not a replacement for SSH access control. Expose only deliberate decoy ports, keep the simulator isolated, rate-limit resource use, and treat captured commands or files as hostile data.</p>
</main></body></html>
