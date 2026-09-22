<?php
function appDemo4Esc($value){ return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function appDemo4()
{
?>
<style>
.gk-demo4{max-width:900px;margin:0 auto;text-align:left}.gk-demo4 h1{text-align:center}.gk-demo4-card{border:1px solid #bbb;background:#fff;padding:14px;margin:12px 0}
.gk-demo4 table{width:100%;border-collapse:collapse}.gk-demo4 th,.gk-demo4 td{border:1px solid #ddd;padding:7px;text-align:left}.gk-demo4-muted{color:#555}
</style>
<div class="gk-demo4">
<h1>HTTP Demo 4 — partner ISP routing</h1>
<p>Demo 4 shows whether tagged traffic from a registered gateway is routed through the selected partner ISP router with a recognizable fixed public address.</p>
<div class="gk-demo4-card">
<h2>Reported gateway state</h2>
<?php
$c=null;
try {
    $c=getConnection();
    $exists=$c->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='demo4GatewayState' LIMIT 1");
    if($exists && $exists->num_rows){
        $q=$c->query("SELECT INET_NTOA(s.gatewayIp) gatewayIp,s.routerId,s.state,s.message,s.reported,p.name partnerName,INET_NTOA(r.taggedTrafficRoute) routeIp FROM demo4GatewayState s LEFT JOIN partnerRouter r ON r.routerId=s.routerId LEFT JOIN partner p ON p.partnerId=r.partnerId ORDER BY s.reported DESC LIMIT 25");
        if($q && $q->num_rows){
            print '<table><tr><th>Gateway</th><th>Partner route</th><th>State</th><th>Reported</th></tr>';
            while($r=$q->fetch_assoc()) print '<tr><td>'.appDemo4Esc($r['gatewayIp']).'</td><td>'.appDemo4Esc($r['partnerName']).' / '.appDemo4Esc($r['routeIp']).'</td><td>'.appDemo4Esc($r['state']).($r['message']?' — '.appDemo4Esc($r['message']):'').'</td><td>'.appDemo4Esc($r['reported']).'</td></tr>';
            print '</table>'; $q->free();
        } else print '<p class="gk-demo4-muted">No Demo 4 gateway reports yet.</p>';
    } else print '<p class="gk-demo4-muted">Demo 4 tables are not installed.</p>';
    if($exists)$exists->free(); $c->close();
} catch(Throwable $e){ if($c)$c->close(); error_log('appDemo4: '.$e->getMessage()); print '<p>Demo 4 state is temporarily unavailable.</p>'; }
?>
</div>
<div class="gk-demo4-card">
<h2>Implementation status</h2>
<p class="gk-demo4-muted">The HTTP page currently observes gateway/router configuration and application reports. Interactive route selection and tagged-traffic test controls will be added here, using the same registered gateway and partner-router API as the app.</p>
</div>
<p><a href="index.php?f=appDemos">Back to HTTP demos</a></p>
</div>
<?php
}
?>