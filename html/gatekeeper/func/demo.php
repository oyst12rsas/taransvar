<?php

// Demo hub. The original functional browser demo is preserved in
// demoOriginal.php and exposed through its own entry point.
function demo()
{
    global $setupRow;

    if (isset($_GET['view']) && $_GET['view'] === 'browser')
    {
        require_once __DIR__."/demoOriginal.php";
        demoBrowser();
        return;
    }
    $isDbServer = isset($setupRow['isDbServer']) && ((int)$setupRow['isDbServer'] === 1);
?>
<style>
.demo-hub{max-width:1050px;margin:0 auto;text-align:left}.demo-hub h1{text-align:center}.demo-hub-intro{text-align:center;margin:0 auto 1.2rem;max-width:760px}.demo-hub-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;margin:1rem 20px 1.5rem}.demo-hub-card{border:1px solid #bbb;background:#f8f8f8;padding:16px}.demo-hub-card h2{margin-top:0}.demo-hub-card a.demo-button{display:inline-block;margin-top:.5rem;padding:.55rem .8rem;background:#fff;border:1px solid #777;text-decoration:none}.demo-hub-note{margin:0 20px 1.3rem;padding:.8rem;border:1px solid #ddd;background:#fafafa}
</style>
<div class="demo-hub">
<h1>Demo</h1>
<p class="demo-hub-intro">TaraSec has both browser-based demonstrations and newer coordinated app demos. The browser demo remains useful because it needs no app installation and exposes the tagging and threat-assessment flow directly.</p>

<div class="demo-hub-grid">
    <div class="demo-hub-card">
        <h2>Browser demo</h2>
        <p>Run the original functional Gatekeeper demo. It shows addressing/NAT attribution, infection state, suspicious-activity reports, traffic tagging and TaraSec's resulting threat assessment.</p>
        <a class="demo-button" href="index.php?f=demo&amp;view=browser">Open browser demo</a>
    </div>

    <div class="demo-hub-card">
        <h2>Current TaraSec demos</h2>
        <p>The newer demonstrations use the TaraSec app and centrally coordinated demo sessions, including the community assistance/containment exercise.</p>
        <a class="demo-button" href="https://tarasec.org/challenge/">Open TaraSec Challenge</a>
    </div>
</div>

<?php if (!$isDbServer) { ?>
<div class="demo-hub-note">Current and historic centrally registered demo sessions are shown on the DB server's Demo page.</div>
<?php } ?>
</div>
<?php
    if ($isDbServer)
    {
        require_once __DIR__."/dbServerDemos.php";
        dbServerDemos();
    }
}

?>
