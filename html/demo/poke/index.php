<?php require_once __DIR__ . '/../../deploymentMode.php'; tarasecRequireDemo(); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>TaraSec · Poke the next computer</title><link rel="stylesheet" href="style.css"></head>
<body><main>
<header><a href="/demo/">TARASEC / LIVE TESTBED</a><span>Browser demonstration</span></header>
<h1>Who knows<br>you’re infected?</h1>
<p class="lead">See how each computer observes your device. Start from its current state, then Poke each receiver to send a fresh tagged connection.</p>
<p>Open this page on <strong>your TaraSec gateway</strong>. Anyone connected through an allowed gateway can join; no device or owner registration is needed. The DB server authorizes the gateway.</p>
<section class="controls" aria-label="Demo controls"><button id="start">Start / Reset my demos</button><button id="mark" disabled>Mark this device infected</button><button id="stop" disabled>Stop</button></section>
<p id="message" role="status" aria-live="polite">Start replaces this device’s earlier web demo, closes its verified SSH demos and clears its demo-only marks. A separate security finding must be resolved before joining.</p>

<section id="cards" aria-label="Computer observations"></section>
<p class="muted" id="freshness">Not started</p>
<section class="explanation"><h2>Knowledge travels with the next connection.</h2><ol><li>Start / Reset: replace this device’s earlier demos, clear its demo-only marks and collect fresh observations. A separate security finding blocks participation until it is resolved.</li><li>Mark infected: available once all observations are clean. Your gateway’s assessment of this device turns red; receivers keep their last observed state.</li><li>Poke a receiver: a direct connection carries the current traffic tag through your gateway.</li></ol><p>Receiver cards show the state <strong>Last observed</strong> on a direct Poke. The gateway checks combined DB status once a second; these checks do not send phone traffic to the receivers. Other traffic from this device may still reach them.</p><p>Restart clears demo-only marks belonging to this device, including older app marks, after a reload. Earlier web-demo access is revoked and verified SSH sessions for this device are cancelled. Other devices and security findings are preserved. Shared demos without verified device ownership are not cancelled. Receiver cards change only after a fresh Poke; wait for tag propagation and Poke again if needed. A session lasts 30 minutes. Stopping or closing the page does not clear the gateway’s demo mark; use Reset before leaving.</p></section>
<details><summary>Debug evidence</summary><button id="copy">Copy debug</button><pre id="debug">No evidence yet.</pre></details>
<footer>Research prototype · Real traffic observations · DB-authorized gateways · Roquefort, Camembert, dbserver1 and Gouda</footer>
</main><script src="poke.js"></script></body></html>
