<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>TaraSec SSH attribution demo</title>
    <style>
        :root { color-scheme:light dark; --bg:#0b1120; --panel:#172033; --line:#334155; --accent:#ff8a38; }
        body { margin:0; background:var(--bg); color:#e5e7eb; font:16px/1.6 system-ui,sans-serif; }
        main { max-width:980px; margin:auto; padding:36px 20px 72px; }
        h1,h2 { line-height:1.2; } h2 { margin-top:2.2rem; color:#ffa466; }
        a { color:#ffad70; } .lead { color:#cbd5e1; font-size:1.18rem; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:20px; margin:18px 0; }
        .flow { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:10px; }
        .flow div { background:#111827; border:1px solid var(--line); border-radius:10px; padding:14px; }
        code,pre { background:#050914; border-radius:6px; } code { padding:.15rem .35rem; }
        pre { padding:16px; overflow:auto; border:1px solid #263349; }
        table { width:100%; border-collapse:collapse; } th,td { padding:10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
        .note { border-left:5px solid var(--accent); }
    </style>
</head>
<body><main>
    <p><a href="/">← TaraSec</a></p>
    <h1>SSH attribution and self-correction demo</h1>
    <p class="lead">See TaraSec detect an apparently infected unit, carry elaborated threat information across the network, attribute a second connection through conntrack, and correct only the evidence created by the active demonstration.</p>

    <div class="card"><strong>Presenting on a computer?</strong> <a href="/demo/live/">Open the live Demo 1 network view</a> to show gateway state and two receivers together.</div>
    <h2>What the demo shows</h2>
    <div class="flow">
        <div><strong>Unit</strong><br>Phone or laptop making the SSH connections</div>
        <div><strong>Gateway</strong><br>Tags traffic and maintains unit attribution</div>
        <div><strong>Node A</strong><br>Rejects SSH and reports ordinary threat evidence</div>
        <div><strong>Node B</strong><br>Runs a non-executing SSH honeypot</div>
        <div><strong>DB server</strong><br>Correlates evidence and owns demo state</div>
        <div><strong>App</strong><br>Starts and observes the experiment</div>
    </div>
    <p>The gateway is deliberately unaware that a demo is running. It detects, tags and reports exactly as it would in normal operation. Only the DB server knows the selected Node A, Node B and active session window.</p>

    <h2>Sequence</h2>
    <ol>
        <li>The app asks the DB server to start a session while the unit is clear.</li>
        <li>The DB returns Node A, Node B, the dedicated demo port and Node B's current short credential.</li>
        <li>The unit connects to Node A. Its rejection produces normal TaraSec evidence and the gateway tags subsequent traffic.</li>
        <li>The unit connects to Node B's SSH honeypot. It reports the complete TCP tuple and tagged threat information without exposing the node.</li>
        <li>The DB correlates the tuple through TaraSec/conntrack to the right unit and active session.</li>
        <li>A valid sequence clears only its reversible demo attribution. Independent evidence still requires the hotspot owner’s regular clearance process.</li>
    </ol>

    <div class="card note">
        <strong>Designed for classrooms.</strong> One Node B and one port may serve many demonstrations simultaneously. All active sessions share Node B’s short username/password; the DB keeps it unchanged until the last active session completes or expires. The full connection tuple—not the password—identifies the unit.
    </div>

    <h2>Deploy your own experiment</h2>
    <p>You need a TaraSec hotspot or gateway, two reachable Linux nodes and a TaraSec DB server. Node A uses an ordinary rejecting firewall/honeypot policy. Node B reserves port 22 for the lightweight, non-executing SSH simulator while genuine administrative SSH uses the separately configured <code>SSH_PORT</code>. Configure both nodes in <code>demoSshSetup</code>; the app reads the available setups from the DB.</p>
    <pre><code>SSH_PORT="5822"
SSH_HONEYPOT="on"
SSH_HONEYPOT_PORTS="22"
SSH_HONEYPOT_AUTH_MODE="reject-all"
SSH_HONEYPOT_DEMO_PORT="22"
SSH_HONEYPOT_DEMO_DB_URL="https://your-db.example/script/appDemoSshSession.php"</code></pre>
    <p>Node B does not expose files, directories, accounts or a command interpreter. The honeypot never starts a real shell and never executes submitted commands; every prompt and response is generated from a small set of canned simulations. Its dedicated Node B token authenticates reports to the DB. See <a href="/ssh-hardening.php">SSH hardening and honeypot installation</a> for setup details.</p>

    <h2>Evidence available for research</h2>
    <table>
        <thead><tr><th>Layer</th><th>Observation</th></tr></thead>
        <tbody>
            <tr><td>Node A</td><td>Rejected connection, destination policy and threat description</td></tr>
            <tr><td>Gateway</td><td>Unit attribution, infection state and subsequent traffic tag</td></tr>
            <tr><td>Node B</td><td>SSH metadata, authentication result and complete connection tuple</td></tr>
            <tr><td>DB server</td><td>Setup, session state, correlated evidence and immutable demo event history</td></tr>
            <tr><td>App</td><td>A simple view of the same end-to-end process</td></tr>
        </tbody>
    </table>

    <h2>Why it matters</h2>
    <p>The sequence also resembles ordinary operational mistakes: connecting to the wrong address or port, or deploying an incorrect firewall rule. It provides a repeatable way to study false attribution and safe correction without teaching the gateway to suppress real detections.</p>
</main></body></html>
