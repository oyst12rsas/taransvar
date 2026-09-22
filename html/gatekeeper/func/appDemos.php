<?php
function appDemos()
{
    global $demoRow, $setupRow;

    $isDbServer = isset($setupRow['isDbServer']) && (int)$setupRow['isDbServer'] === 1;
    $dbIp = '';
    $routeConn = getConnection();
    $routeResult = $routeConn->query("SELECT INET_NTOA(globalDb1ip) db1 FROM setup LIMIT 1");
    if ($routeResult && ($routeRow = $routeResult->fetch_assoc()))
        $dbIp = (string)($routeRow['db1'] ?? '');
    if ($routeResult) $routeResult->free();
    $routeConn->close();

    $demo1Ip = is_array($demoRow) ? (string)($demoRow['targetHost'] ?? '') : '';
    $demo1Url = filter_var($demo1Ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ? 'http://'.$demo1Ip.'/gatekeeper/index.php?f=demo&view=browser'
        : '';
    $dbBase = filter_var($dbIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ? 'http://'.$dbIp.'/gatekeeper/index.php?f='
        : ($isDbServer ? 'index.php?f=' : '');
?>
<style>
.http-demo-hub{max-width:1050px;margin:0 auto;text-align:left}.http-demo-hub h1{text-align:center}.http-demo-intro{text-align:center;max-width:760px;margin:0 auto 18px}
.http-demo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.http-demo-card{border:1px solid #bbb;background:#fff;padding:16px}
.http-demo-card h2{margin-top:0}.http-demo-card a{display:inline-block;padding:8px 11px;border:1px solid #777;background:#f8f8f8;text-decoration:none}.http-demo-error{color:#a00000;font-weight:bold}.http-demo-note{margin-top:18px;padding:12px;border:1px solid #ddd;background:#fafafa}
</style>
<div class="http-demo-hub">
<h1>HTTP demos</h1>
<p class="http-demo-intro">Choose one demonstration. Each opens on its own page with its explanation, controls, live status, and “Copy debug info for AI” button.</p>
<div class="http-demo-grid">
 <div class="http-demo-card">
  <h2>Demo 1</h2>
  <p>Browser traffic attribution, suspicious reports, tagging and threat assessment.</p>
  <?php if ($demo1Url !== '') { ?><a href="<?php print htmlspecialchars($demo1Url, ENT_QUOTES, 'UTF-8'); ?>">Open Demo 1</a><?php } else { ?><p class="http-demo-error">Unavailable: no valid Demo 1 receiving/target host is configured.</p><?php } ?>
 </div>
 <div class="http-demo-card">
  <h2>Demo 2</h2>
  <p>SSH rejection at Node A, gateway infection evidence, and legitimate Node B clearing.</p>
  <?php if ($dbBase !== '') { ?><a href="<?php print htmlspecialchars($dbBase.'appDemo2', ENT_QUOTES, 'UTF-8'); ?>">Open Demo 2</a><?php } else { ?><p class="http-demo-error">Unavailable: no valid global DB server is configured on this node.</p><?php } ?>
 </div>
 <div class="http-demo-card">
  <h2>Demo 3</h2>
  <p>Community Request for Assistance, containment evidence, release and recovery.</p>
  <?php if ($dbBase !== '') { ?><a href="<?php print htmlspecialchars($dbBase.'appDemo3', ENT_QUOTES, 'UTF-8'); ?>">Open Demo 3</a><?php } else { ?><p class="http-demo-error">Unavailable: no valid global DB server is configured on this node.</p><?php } ?>
 </div>
 <div class="http-demo-card">
  <h2>Demo 4</h2>
  <p>Tagged traffic routed through a selected partner ISP router with a recognizable public address.</p>
  <?php if ($dbBase !== '') { ?><a href="<?php print htmlspecialchars($dbBase.'appDemo4', ENT_QUOTES, 'UTF-8'); ?>">Open Demo 4</a><?php } else { ?><p class="http-demo-error">Unavailable: no valid global DB server is configured on this node.</p><?php } ?>
 </div>
</div>
<div class="http-demo-note">At any time, use the debug-copy button on the individual demo page and paste the report into AI for an explanation. AI background: <a href="https://tarasec.org/ai/demo-guide/">tarasec.org/ai/demo-guide/</a></div>
<p><a href="index.php?f=demo">Back to Demo</a></p>
</div>
<?php
}
?>