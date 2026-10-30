<?php require_once __DIR__ . '/../../deploymentMode.php'; tarasecRequireDemo(); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>TaraSec · Poke the next computer</title><link rel="stylesheet" href="style.css"></head>
<body><main>
<header><a href="/demo/">TARASEC / LIVE TESTBED</a><span>Browser demonstration</span></header>
<h1>Who knows<br>you’re infected?</h1>
<p class="lead">The gateway knows first. Poke each computer to see what your next connection tells it.</p>
<p>Open this page on <strong>your TaraSec gateway</strong>. The DB server must authorize the gateway before this browser can join.</p>
<section class="controls" aria-label="Demo controls"><button id="start">Start clean / Reset</button><button id="mark" disabled>Mark this device infected</button><button id="stop" disabled>Stop</button></section>
<p id="message" role="status" aria-live="polite">Start to request a DB-authorized session and collect a clean baseline from the approved receivers.</p>

<section id="cards" aria-label="Computer observations"></section>
<p class="muted" id="freshness">Not started</p>
<section class="explanation"><h2>Knowledge travels with the next connection.</h2><ol><li>Start clean: all participating computers must confirm clean before marking is enabled.</li><li>Mark infected: Your gateway turns red. Receivers keep their last observed state.</li><li>Poke a receiver: a direct connection carries the current traffic tag through your gateway.</li></ol><p>Receiver cards show the state <strong>Last observed</strong> on a direct Poke. The gateway checks combined DB status once a second; these checks do not send phone traffic to the receivers. Other traffic from this device may still reach them.</p><p>Reset clears only this session’s demo evidence. Independent security findings remain active. A session lasts 30 minutes. Stopping or closing the page does not clear the gateway’s demo mark; use Reset before leaving.</p></section>
<details><summary>Debug evidence</summary><button id="copy">Copy debug</button><pre id="debug">No evidence yet.</pre></details>
<footer>Research prototype · Real traffic observations · DB-authorized gateways · Roquefort, Camembert, dbserver1 and Gouda</footer>
</main><script src="poke.js"></script></body></html>
