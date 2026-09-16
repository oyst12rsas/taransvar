<?php
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TaraSec Challenge</title>
<style>
:root{font-family:Inter,Arial,sans-serif;color:#171717;background:#f5f5f5}*{box-sizing:border-box}body{margin:0}.wrap{max-width:760px;margin:auto;padding:24px}.hero{background:#b00020;color:white;border-radius:24px;padding:30px;margin:20px 0}.hero h1{font-size:clamp(36px,8vw,68px);line-height:.96;margin:0 0 18px}.hero p{font-size:20px;line-height:1.45}.pill{display:inline-block;background:#fff;color:#b00020;font-weight:800;border-radius:999px;padding:10px 16px;margin-bottom:18px}.card{background:white;border-radius:20px;padding:24px;margin:16px 0;box-shadow:0 8px 24px #00000012}.step{display:grid;grid-template-columns:42px 1fr;gap:12px;margin:18px 0}.n{width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:#111;color:white;font-weight:800}.cta{display:block;text-align:center;text-decoration:none;background:#111;color:#fff;padding:16px 20px;border-radius:14px;font-weight:800;margin-top:18px}.muted{color:#666}.live{border:3px solid #b00020}.live h2{color:#b00020}.small{font-size:14px}.question{font-size:24px;font-weight:800;line-height:1.25}</style>
</head>
<body>
<main class="wrap">
<section class="hero">
<div class="pill">🍕 THE TARASEC CHALLENGE</div>
<h1>Help us challenge cybercrime.</h1>
<p><strong>No cybersecurity knowledge needed.</strong> If you think networks should help each other make life harder for cybercriminals, you are qualified.</p>
</section>

<section id="live" class="card live" hidden>
<h2 id="liveTitle">Nearby challenge</h2>
<p id="liveDescription"></p>
<strong id="liveJoin"></strong>
</section>

<section class="card">
<h2>How it works</h2>
<div class="step"><div class="n">1</div><div><strong>Install TaraSec</strong><br><span class="muted">Open the app while you are at the event.</span></div></div>
<div class="step"><div class="n">2</div><div><strong>Join the challenge</strong><br><span class="muted">TaraSec checks whether your Internet connection matches a current local event. If it does, the app shows a red Join Challenge box.</span></div></div>
<div class="step"><div class="n">3</div><div><strong>Run Demo 3</strong><br><span class="muted">You will take part in a real TaraSec Request for Assistance experiment.</span></div></div>
<div class="step"><div class="n">4</div><div><strong>Ask ChatGPT anything</strong><br><span class="muted">You do not need to understand networking, firewalls or protocols. ChatGPT can explain the technical parts.</span></div></div>
<div class="step"><div class="n">5</div><div><strong>Tell us what you think it means</strong><br><span class="muted">Your job is to judge the idea, not to be a cybersecurity expert.</span></div></div>
<a class="cta" href="https://play.google.com/store/apps/details?id=org.tarasec.app">Get TaraSec on Google Play</a>
</section>

<section class="card">
<div class="question">What could happen to cybercrime if networks around the world warned and helped each other?</div>
<p class="muted">That is the challenge. The experiment gives you something concrete to react to; ChatGPT can explain the machinery underneath.</p>
</section>

<section class="card small">
<strong>TaraSec</strong> is a cooperative cybersecurity project by the Norwegian nonprofit Taransvar. The challenge is an educational demonstration. Event availability is determined from the public IP address seen by TaraSec.org; the page does not need your phone location.
</section>
</main>
<script>
fetch('/script/appChallenges.php',{cache:'no-store'})
 .then(r=>r.json()).then(j=>{
   const c=j && j.ok && Array.isArray(j.challenges) ? j.challenges[0] : null;
   if(!c)return;
   document.getElementById('live').hidden=false;
   document.getElementById('liveTitle').textContent=c.title||'Nearby challenge';
   document.getElementById('liveDescription').textContent=c.description||'';
   document.getElementById('liveJoin').textContent=c.join_text||'Join challenge';
 }).catch(()=>{});
</script>
</body>
</html>
