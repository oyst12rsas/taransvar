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
    $s=$c->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");
    $s->bind_param("s",$name); $s->execute();
    $ok=$s->get_result()->num_rows>0; $s->close(); return $ok;
}
function appDemos()
{
    global $setupRow;
    $isDbServer = isset($setupRow['isDbServer']) && (int)$setupRow['isDbServer'] === 1;
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
<p class="gk-demo-help"><b>This is the browser view of the same demos used by the TaraSec app.</b> It can be opened from any Gatekeeper and reads the authoritative shared demo state from the central database. Demo traffic and actions are directed to the configured demo nodes/gateway rather than treating the web host as the demo target. Refresh at any time, or copy what you see to AI for an explanation. Operations that need SSH, participant/controller secrets, or a registered gateway remain protected by the existing demo endpoints.</p>
<?php
    $c=null;
    try {
        $c=getConnection();
?>
<div class="gk-demo-grid">
<div class="gk-demo-card"><h2>Demo 1 — browser tagging</h2>
<p>The original Gatekeeper HTTP demo: traffic attribution, suspicious reports, tagging and threat assessment.</p>
<div class="gk-demo-actions"><a href="index.php?f=demo&amp;view=browser">Run Demo 1</a></div></div>

<div class="gk-demo-card" id="demo2"><h2>Demo 2 — SSH rejection and clearing</h2>
<?php
if(appDemoTableExists($c,'demoSshSession')){
    $q=$c->query("SELECT s.demoSshSessionId id,s.state,INET_NTOA(s.sourceIp) sourceIp,s.created,s.expires,s.completed,INET_NTOA(d.nodeAIp) nodeA,d.nodeAPort,INET_NTOA(n.ip) nodeB,n.port nodeBPort FROM demoSshSession s JOIN demoSshSetup d ON d.demoSshSetupId=s.demoSshSetupId JOIN demoSshNodeB n ON n.demoSshNodeBId=s.demoSshNodeBId ORDER BY s.demoSshSessionId DESC LIMIT 5");
    if($q && $q->num_rows){ print '<table><tr><th>ID</th><th>State</th><th>Path</th></tr>'; while($r=$q->fetch_assoc()){
        print '<tr><td>'.(int)$r['id'].'</td><td>'.appDemoEsc($r['state']).'</td><td>'.appDemoEsc($r['sourceIp']).' → '.appDemoEsc($r['nodeA']).':'.(int)$r['nodeAPort'].' → '.appDemoEsc($r['nodeB']).':'.(int)$r['nodeBPort'].'</td></tr>';
    } print '</table>'; $q->free(); } else print '<p class="gk-demo-muted">No Demo 2 sessions yet.</p>';
} else print '<p class="gk-demo-muted">Demo 2 tables are not installed.</p>';
?>
<p class="gk-demo-muted">The browser uses the same authoritative Demo 2 API as the Android app. You still make the two SSH connections with an SSH client.</p>
<?php
if (!$isDbServer) {
    $dbIp = '';
    $dbResult = $c->query("SELECT INET_NTOA(globalDb1ip) db1 FROM setup LIMIT 1");
    if ($dbResult && ($dbRow = $dbResult->fetch_assoc())) $dbIp = (string)($dbRow['db1'] ?? '');
    if ($dbResult) $dbResult->free();
    if (filter_var($dbIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        print '<p class="gk-demo-warn">Demo 2 sessions are controlled by the DB server so the gateway and source path are observed correctly.</p>';
        print '<div class="gk-demo-actions"><a href="http://'.appDemoEsc($dbIp).'/gatekeeper/index.php?f=appDemos#demo2">Open Demo 2 on the DB server</a></div>';
    } else {
        print '<p class="gk-demo-bad">The global DB server is not configured on this host.</p>';
    }
} else {
?>
<div id="gk-demo2-client">
    <p id="gk-demo2-message" class="gk-demo-muted">Loading the configured Demo 2 path…</p>
    <div id="gk-demo2-setup"></div>
    <div id="gk-demo2-session" style="display:none">
        <table>
            <tr><th>Session</th><td id="gk-demo2-id">—</td></tr>
            <tr><th>State</th><td id="gk-demo2-state">—</td></tr>
            <tr><th>Node A report</th><td id="gk-demo2-a-status">⚪ Waiting</td></tr>
            <tr><th>Gateway/DB</th><td id="gk-demo2-gateway-status">⚪ Waiting</td></tr>
            <tr><th>Node B report</th><td id="gk-demo2-b-status">⚪ Waiting</td></tr>
            <tr><th>Time remaining</th><td id="gk-demo2-time">—</td></tr>
        </table>
        <h3>1 · Connect to Node A</h3>
        <p>Node A rejects the connection. That rejection is reported through TaraSec and marks this unit as infected.</p>
        <code id="gk-demo2-a-command"></code>
        <button type="button" onclick="gkDemo2Copy('gk-demo2-a-command')">Copy Node A command</button>
        <h3>2 · Connect to Node B</h3>
        <p>Wait until the gateway row says the unit is marked infected, then use the legitimate classroom login at Node B.</p>
        <div id="gk-demo2-b-details"></div>
        <code id="gk-demo2-b-command"></code>
        <button type="button" onclick="gkDemo2Copy('gk-demo2-b-command')">Copy Node B command</button>
        <div class="gk-demo-actions">
            <button type="button" onclick="gkDemo2Refresh()">Refresh session</button>
            <button type="button" onclick="gkDemo2Close()">Close session</button>
            <button type="button" onclick="gkDemo2Debug()">Copy debug info for AI</button>
        </div>
    </div>
    <div id="gk-demo2-start" class="gk-demo-actions" style="display:none">
        <button type="button" id="gk-demo2-start-button" onclick="gkDemo2Start()">Start SSH demo</button>
    </div>
</div>
<script>
(function(){
    const api='../script/appDemoSshSession.php';
    const configApi='../script/appDemoConfiguration.php';
    const storageKey='tarasec_http_demo2_session';
    let setup=null, session=null, timer=null;

    function el(id){ return document.getElementById(id); }
    function message(text,bad){
        el('gk-demo2-message').textContent=text;
        el('gk-demo2-message').className=bad?'gk-demo-bad':'gk-demo-muted';
    }
    async function json(url, options){
        const response=await fetch(url, Object.assign({cache:'no-store'},options||{}));
        const data=await response.json().catch(()=>({ok:false,error:'Invalid server response'}));
        if(!response.ok || data.ok===false) throw new Error(data.error||('HTTP '+response.status));
        return data;
    }
    function body(values){ return new URLSearchParams(values).toString(); }
    function save(){ session ? sessionStorage.setItem(storageKey,JSON.stringify(session)) : sessionStorage.removeItem(storageKey); }
    function command(host,port,user){ return 'ssh -p '+port+' '+user+'@'+host; }
    function statusText(s){
        const state=s.state||'unknown';
        el('gk-demo2-id').textContent='#'+s.session_id;
        el('gk-demo2-state').textContent=state.replaceAll('_',' ');
        el('gk-demo2-a-status').textContent=s.node_a_observed?'🔴 SSH rejection received':'⚪ Waiting';
        el('gk-demo2-gateway-status').textContent=state==='cleared'?'🟢 Demo infection cleared':(s.unit_marked?'🔴 Unit marked infected':'⚪ Waiting');
        el('gk-demo2-b-status').textContent=s.node_b_login_accepted===true?(state==='cleared'?'🟢 Login accepted · evidence validated':'🟢 Login accepted · validation pending'):(s.node_b_login_accepted===false?'🔴 Login rejected':(s.node_b_observed?'🟡 Report received · checking login':'⚪ Waiting'));
        const seconds=Math.max(0,Number(s.seconds_remaining??s.expires_in??0));
        el('gk-demo2-time').textContent=String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');
        el('gk-demo2-a-command').textContent=command(s.node_a,s.node_a_port,s.username||'demo');
        el('gk-demo2-b-command').textContent=command(s.node_b,s.node_b_port,s.username||'demo');
        el('gk-demo2-b-details').textContent='Host '+s.node_b+':'+s.node_b_port+' · username '+(s.username||'demo')+' · password '+(s.password||'1');
        el('gk-demo2-session').style.display='block';
        el('gk-demo2-start').style.display='none';
        if(s.progress_message) message(s.progress_message,false);
    }
    async function eligibility(){
        const check=await json(api+'?action=eligibility');
        if(!check.eligible){
            message(check.message+(check.demo_reset_available?' Close/reset the previous demo state in the app or gateway before starting again.':''),true);
            el('gk-demo2-start').style.display='none';
            return false;
        }
        message(check.operational_warning||check.message,false);
        el('gk-demo2-start').style.display='block';
        return true;
    }
    window.gkDemo2Start=async function(){
        try{
            if(!setup || !await eligibility()) return;
            const created=await json(api,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body({action:'create',setup_id:setup.id})});
            session=created; save(); statusText(session); startPolling();
        }catch(e){ message(e.message,true); }
    };
    window.gkDemo2Refresh=async function(){
        if(!session) return;
        try{
            const fresh=await json(api+'?action=status&session_id='+encodeURIComponent(session.session_id)+'&session_token='+encodeURIComponent(session.session_token));
            session=Object.assign(session,fresh); save(); statusText(session);
            if(['cleared','owner_clear_required','expired','cancelled'].includes(session.state)) stopPolling();
        }catch(e){ message(e.message,true); }
    };
    window.gkDemo2Close=async function(){
        if(!session) return;
        try{
            await json(api,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body({action:'cancel',session_id:session.session_id,session_token:session.session_token})});
            stopPolling(); session=null; save(); el('gk-demo2-session').style.display='none';
            message('Session closed. If its demo infection remains active, clear it on the gateway before starting again.',false);
            await eligibility();
        }catch(e){ message(e.message,true); }
    };
    window.gkDemo2Copy=function(id){
        const text=el(id).textContent;
        navigator.clipboard.writeText(text).catch(()=>window.prompt('Copy this command:',text));
    };
    window.gkDemo2Debug=function(){
        if(!session) return;
        const report=[
            'TaraSec HTTP Demo 2 debug report',
            'ai_background=https://tarasec.org/ai/demo-guide/',
            'secrets=omitted (password and session token)',
            '',
            '[setup]',
            'setup_id='+(setup?.id||0),
            'setup_name='+(setup?.name||'unknown'),
            'node_a='+(session.node_a||'unknown')+':'+(session.node_a_port||0),
            'node_b='+(session.node_b||'unknown')+':'+(session.node_b_port||0),
            '',
            '[session]',
            'session_id='+(session.session_id||0),
            'state='+(session.state||'unknown'),
            'attempts='+(session.attempts||0),
            'seconds_remaining='+(session.seconds_remaining??session.expires_in??0),
            'node_a_observed='+Boolean(session.node_a_observed),
            'unit_marked='+Boolean(session.unit_marked),
            'node_b_observed='+Boolean(session.node_b_observed),
            'node_b_login_accepted='+(session.node_b_login_accepted??'unknown'),
            'progress_message='+(session.progress_message||'none')
        ].join('\n');
        navigator.clipboard.writeText(report).then(()=>message('Debug information copied. Paste it into AI and ask what happened.',false)).catch(()=>window.prompt('Copy this debug report:',report));
    };
    function startPolling(){ stopPolling(); timer=setInterval(window.gkDemo2Refresh,2000); }
    function stopPolling(){ if(timer){ clearInterval(timer); timer=null; } }
    async function init(){
        try{
            const configuration=await json(configApi);
            setup=(configuration.demo_ssh_setups||[]).find(x=>x.id===configuration.selection?.demo2_setup_id)||(configuration.demo_ssh_setups||[])[0];
            if(!setup){ throw new Error('No active Demo 2 setup is configured.'); }
            el('gk-demo2-setup').textContent=setup.name+': '+setup.node_a+':'+setup.node_a_port+' → '+setup.node_b+':'+setup.node_b_port;
            try{ session=JSON.parse(sessionStorage.getItem(storageKey)||'null'); }catch(_){ session=null; }
            if(session?.session_id && session?.session_token){ statusText(session); startPolling(); await window.gkDemo2Refresh(); }
            else await eligibility();
        }catch(e){ message(e.message,true); }
    }
    init();
})();
</script>
<?php } ?>
</div>

<div class="gk-demo-card"><h2>Demo 3 — community containment</h2>
<?php
if(appDemoTableExists($c,'demoAssistanceSession')){
    $q=$c->query("SELECT d.sessionId,d.name,d.state,d.threshold,d.targetIp,COUNT(p.participantId) participants,SUM(CASE WHEN p.severity>d.threshold THEN 1 ELSE 0 END) infected,SUM(CASE WHEN p.decision='silent' THEN 1 ELSE 0 END) silent FROM demoAssistanceSession d LEFT JOIN demoAssistanceParticipant p ON p.sessionId=d.sessionId GROUP BY d.sessionId,d.name,d.state,d.threshold,d.targetIp ORDER BY d.sessionId DESC LIMIT 5");
    if($q && $q->num_rows){ print '<table><tr><th>ID</th><th>State</th><th>Participants</th><th>Containment</th></tr>'; while($r=$q->fetch_assoc()){
        print '<tr><td>'.(int)$r['sessionId'].'</td><td>'.appDemoEsc($r['state']).'</td><td>'.(int)$r['participants'].' ('.(int)$r['infected'].' infected)</td><td>'.(int)$r['silent'].' silent</td></tr>';
    } print '</table>'; $q->free(); } else print '<p class="gk-demo-muted">No Demo 3 sessions yet.</p>';
} else print '<p class="gk-demo-muted">Demo 3 tables are not installed.</p>';
?>
<p class="gk-demo-muted">This makes the important test visible in HTTP: an infected participant should become unreachable/silent during containment and recover after release.</p><div class="gk-demo-actions"><a href="index.php?f=appDemo3">Open HTTP Demo 3</a></div></div>

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
        $errorCode = substr(hash('sha256', $e->getMessage()), 0, 10);
        print '<div class="gk-demo-card gk-demo-bad">Demo state is temporarily unavailable. Error reference: '.appDemoEsc($errorCode).'</div>';
    }
?>
</div>
<?php
}
?>