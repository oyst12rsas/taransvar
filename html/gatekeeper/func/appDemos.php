<?php
/*
 * Browser/HTTP view of the coordinated TaraSec app demos.
 *
 * This page deliberately reads the same authoritative DB state as the Android
 * demos instead of inventing a second demo state machine.  Actions that require
 * a participant token, controller token, SSH client, or registered gateway stay
 * in their existing endpoints/app; Gatekeeper is the HTTP observer.
 */
function appDemoEsc($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function appDemoTableExists($c,$name){
    $s=$c->prepare("SHOW TABLES LIKE ?"); $s->bind_param("s",$name); $s->execute();
    $ok=$s->get_result()->num_rows>0; $s->close(); return $ok;
}
function appDemos()
{
    global $setupRow;
    $isDb=isset($setupRow['isDbServer']) && (int)$setupRow['isDbServer']===1;
?>
<style>
.gk-app-demos{max-width:1100px;margin:0 auto;text-align:left}.gk-app-demos h1{text-align:center}
.gk-demo-help{text-align:center;max-width:850px;margin:0 auto 18px}.gk-demo-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:12px}
.gk-demo-card{background:#fff;border:1px solid #bbb;padding:14px}.gk-demo-card h2{margin:.1rem 0 .55rem}.gk-demo-card table{margin:8px 0;width:100%;border-collapse:collapse}
.gk-demo-card td,.gk-demo-card th{padding:6px;border:1px solid #ddd;text-align:left}.gk-demo-muted{color:#555}.gk-demo-good{color:#28752d;font-weight:bold}
.gk-demo-warn{color:#9a5a00;font-weight:bold}.gk-demo-bad{color:#a00000;font-weight:bold}.gk-demo-actions{margin-top:12px}
.gk-demo-actions a{display:inline-block;padding:7px 10px;border:1px solid #777;background:#f8f8f8;text-decoration:none;margin:0 5px 5px 0}
</style>
<div class="gk-app-demos">
<h1>HTTP demo dashboard</h1>
<p class="gk-demo-help"><b>This is the browser view of the same demos used by the TaraSec app.</b> It reads the shared demo state from the database. Refresh at any time, or copy what you see to AI for an explanation. Operations that need SSH, participant/controller secrets, or a registered gateway remain protected by the existing demo endpoints.</p>
<?php
    if(!$isDb){
        print '<div class="gk-demo-card"><b>DB server required.</b> Open this page on the TaraSec DB Gatekeeper to see coordinated demo state.</div></div>';
        return;
    }
    $c=null;
    try {
        $c=getConnection(); $c->query("SET time_zone='+00:00'");
?>
<div class="gk-demo-grid">
<div class="gk-demo-card"><h2>Demo 1 — browser tagging</h2>
<p>The original Gatekeeper HTTP demo: traffic attribution, suspicious reports, tagging and threat assessment.</p>
<div class="gk-demo-actions"><a href="index.php?f=demo&amp;view=browser">Run Demo 1</a></div></div>

<div class="gk-demo-card"><h2>Demo 2 — SSH rejection and clearing</h2>
<?php
if(appDemoTableExists($c,'demoSshSession')){
    $q=$c->query("SELECT s.demoSshSessionId id,s.state,INET_NTOA(s.sourceIp) sourceIp,s.created,s.expires,s.completed,INET_NTOA(d.nodeAIp) nodeA,d.nodeAPort,INET_NTOA(n.ip) nodeB,n.port nodeBPort FROM demoSshSession s JOIN demoSshSetup d ON d.demoSshSetupId=s.demoSshSetupId JOIN demoSshNodeB n ON n.demoSshNodeBId=s.demoSshNodeBId ORDER BY s.demoSshSessionId DESC LIMIT 5");
    if($q && $q->num_rows){ print '<table><tr><th>ID</th><th>State</th><th>Path</th></tr>'; while($r=$q->fetch_assoc()){
        print '<tr><td>'.(int)$r['id'].'</td><td>'.appDemoEsc($r['state']).'</td><td>'.appDemoEsc($r['sourceIp']).' → '.appDemoEsc($r['nodeA']).':'.(int)$r['nodeAPort'].' → '.appDemoEsc($r['nodeB']).':'.(int)$r['nodeBPort'].'</td></tr>';
    } print '</table>'; $q->free(); } else print '<p class="gk-demo-muted">No Demo 2 sessions yet.</p>';
} else print '<p class="gk-demo-muted">Demo 2 tables are not installed.</p>';
?>
<p class="gk-demo-muted">The HTTP page can observe the complete server-side sequence. The actual SSH connections still require an SSH client.</p></div>

<div class="gk-demo-card"><h2>Demo 3 — community containment</h2>
<?php
if(appDemoTableExists($c,'demoAssistanceSession')){
    $q=$c->query("SELECT d.sessionId,d.name,d.state,d.threshold,d.targetIp,COUNT(p.participantId) participants,SUM(CASE WHEN p.severity>d.threshold THEN 1 ELSE 0 END) infected,SUM(CASE WHEN p.decision='silent' THEN 1 ELSE 0 END) silent FROM demoAssistanceSession d LEFT JOIN demoAssistanceParticipant p ON p.sessionId=d.sessionId GROUP BY d.sessionId,d.name,d.state,d.threshold,d.targetIp ORDER BY d.sessionId DESC LIMIT 5");
    if($q && $q->num_rows){ print '<table><tr><th>ID</th><th>State</th><th>Participants</th><th>Containment</th></tr>'; while($r=$q->fetch_assoc()){
        print '<tr><td>'.(int)$r['sessionId'].'</td><td>'.appDemoEsc($r['state']).'</td><td>'.(int)$r['participants'].' ('.(int)$r['infected'].' infected)</td><td>'.(int)$r['silent'].' silent</td></tr>';
    } print '</table>'; $q->free(); } else print '<p class="gk-demo-muted">No Demo 3 sessions yet.</p>';
} else print '<p class="gk-demo-muted">Demo 3 tables are not installed.</p>';
?>
<p class="gk-demo-muted">This makes the important test visible in HTTP: an infected participant should become unreachable/silent during containment and recover after release.</p></div>

<div class="gk-demo-card"><h2>Demo 4 — partner ISP routing</h2>
<?php
if(appDemoTableExists($c,'demo4GatewayState')){
    $q=$c->query("SELECT INET_NTOA(s.gatewayIp) gatewayIp,s.routerId,s.state,s.message,s.reported,p.name partnerName,INET_NTOA(r.taggedTrafficRoute) routeIp FROM demo4GatewayState s LEFT JOIN partnerRouter r ON r.routerId=s.routerId LEFT JOIN partner p ON p.partnerId=r.partnerId ORDER BY s.reported DESC LIMIT 8");
    if($q && $q->num_rows){ print '<table><tr><th>Gateway</th><th>Partner/route</th><th>State</th></tr>'; while($r=$q->fetch_assoc()){
        print '<tr><td>'.appDemoEsc($r['gatewayIp']).'</td><td>'.appDemoEsc($r['partnerName']).' / '.appDemoEsc($r['routeIp']).'</td><td>'.appDemoEsc($r['state']).($r['message']?' — '.appDemoEsc($r['message']):'').'</td></tr>';
    } print '</table>'; $q->free(); } else print '<p class="gk-demo-muted">No Demo 4 gateway reports yet.</p>';
} else print '<p class="gk-demo-muted">Demo 4 tables are not installed.</p>';
?>
<p class="gk-demo-muted">Shows whether tagged traffic routing was configured/applied by each registered gateway and which partner route it uses.</p></div>
</div>
<p class="gk-demo-actions"><a href="index.php?f=appDemos">Refresh</a><a href="index.php?f=demo">Back to Demo</a></p>
<?php
        $c->close();
    } catch(Throwable $e){
        if($c) $c->close();
        error_log('appDemos: '.$e->getMessage());
        print '<div class="gk-demo-card gk-demo-bad">Demo state is temporarily unavailable.</div>';
    }
?>
</div>
<?php
}
?>