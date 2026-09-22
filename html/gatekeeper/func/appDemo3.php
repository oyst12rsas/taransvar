<?php
function appDemo3Esc($value){ return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

function appDemo3()
{
    global $setupRow;
    $isDbServer = isset($setupRow['isDbServer']) && (int)$setupRow['isDbServer'] === 1;
?>
<style>
.gk-demo3{max-width:900px;margin:0 auto;text-align:left}.gk-demo3 h1{text-align:center}.gk-demo3-card{border:1px solid #bbb;background:#fff;padding:14px;margin:12px 0}
.gk-demo3-flow{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}.gk-demo3-step{border:1px solid #ddd;padding:10px;background:#fafafa}
.gk-demo3 table{width:100%;border-collapse:collapse}.gk-demo3 th,.gk-demo3 td{border:1px solid #ddd;padding:6px;text-align:left}.gk-demo3-actions button,.gk-demo3-actions a{margin:4px;padding:7px 10px}
.gk-demo3-muted{color:#555}.gk-demo3-bad{color:#a00000;font-weight:bold}.gk-demo3-good{color:#28752d;font-weight:bold}
</style>
<div class="gk-demo3">
<h1>Demo 3 — community containment</h1>
<p>This is the HTTP version of the app's shared Request for Assistance demonstration. It uses the same server sessions and evidence; it does not create a parallel simulation.</p>
<div class="gk-demo3-flow">
 <div class="gk-demo3-step"><b>1 · Join</b><br>Participants join the same exercise and keep sending heartbeats.</div>
 <div class="gk-demo3-step"><b>2 · Request assistance</b><br>The protected server asks partners to reject traffic above the selected threshold.</div>
 <div class="gk-demo3-step"><b>3 · Observe containment</b><br>An infected participant should become silent while clean participants remain connected.</div>
 <div class="gk-demo3-step"><b>4 · Release</b><br>The assistance request is withdrawn and connectivity should recover.</div>
</div>
<?php
if (!$isDbServer) {
    $c=getConnection(); $dbIp='';
    $q=$c->query("SELECT INET_NTOA(globalDb1ip) db1 FROM setup LIMIT 1");
    if($q && ($r=$q->fetch_assoc())) $dbIp=(string)($r['db1']??'');
    if($q) $q->free(); $c->close();
    if(filter_var($dbIp,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))
        print '<div class="gk-demo3-card"><p>Demo 3 is controlled by the DB server so traffic follows the configured gateway path.</p><a href="http://'.appDemo3Esc($dbIp).'/gatekeeper/index.php?f=appDemo3">Open HTTP Demo 3 on the DB server</a></div>';
    else
        print '<div class="gk-demo3-card gk-demo3-bad">The global DB server is not configured on this host.</div>';
} else {
?>
<div class="gk-demo3-card">
 <h2>Joinable exercises</h2>
 <p id="demo3-message" class="gk-demo3-muted">Loading live sessions…</p>
 <div id="demo3-list"></div>
 <div class="gk-demo3-actions"><button type="button" onclick="demo3Load()">Refresh</button></div>
</div>
<div id="demo3-session-card" class="gk-demo3-card" style="display:none">
 <h2 id="demo3-title">Current exercise</h2>
 <div id="demo3-session"></div>
 <label>Nickname (optional) <input id="demo3-nickname" maxlength="80"></label>
 <div class="gk-demo3-actions">
  <button id="demo3-join" type="button" onclick="demo3Join()">Join as a clean participant</button>
  <button id="demo3-leave" type="button" onclick="demo3Leave()" style="display:none">Leave this demo</button>
  <button type="button" onclick="demo3CopyDebug()">Copy debug info for AI</button>
 </div>
</div>
<div class="gk-demo3-card">
 <h2>Implementation status</h2>
 <p class="gk-demo3-good">Implemented: live public-session discovery, shared session status, clean participant join, heartbeat evidence, leave, and AI debug information.</p>
 <p class="gk-demo3-muted">Next: create-exercise controls and infected/clean selection. The infected path must first call the observed TaraSec gateway, exactly as the app does; changing only the central demo severity would falsely simulate containment.</p>
</div>
<script>
(function(){
 const api='../script/appDemoAssistance.php', key='tarasec_http_demo3_participant';
 let selected=null, participant=null, timer=null;
 const el=id=>document.getElementById(id);
 async function request(action, values, method){
   const options={cache:'no-store'};
   let url=api+'?action='+encodeURIComponent(action);
   if(method==='POST'){options.method='POST';options.headers={'Content-Type':'application/x-www-form-urlencoded'};options.body=new URLSearchParams(values||{}).toString();}
   else if(values) url+='&'+new URLSearchParams(values).toString();
   const response=await fetch(url,options), data=await response.json().catch(()=>({ok:false,error:'invalid_server_response'}));
   if(!response.ok||data.ok===false) throw new Error(data.error||('HTTP '+response.status));
   return data;
 }
 function message(text,bad){el('demo3-message').textContent=text;el('demo3-message').className=bad?'gk-demo3-bad':'gk-demo3-muted';}
 function render(s){
   selected=s; el('demo3-session-card').style.display='block'; el('demo3-title').textContent=s.name+' · session #'+s.session_id;
   const rows=(s.participants||[]).map(p=>'<tr><td>'+escapeHtml(p.nickname||('Participant '+p.participant_id))+'</td><td>'+escapeHtml(p.severity===null?'Undecided':(p.severity>Number(s.threshold)?'Infected':'Clean'))+'</td><td>'+escapeHtml(p.decision||'pending')+'</td><td>'+escapeHtml(p.seconds_since_seen===null?'—':p.seconds_since_seen+'s ago')+'</td></tr>').join('');
   el('demo3-session').innerHTML='<p><b>State:</b> '+escapeHtml(s.state)+' · <b>threshold:</b> '+Number(s.threshold)+' · <b>target:</b> '+escapeHtml(s.target_ip)+'</p><p><b>Request:</b> '+escapeHtml(s.assistance_request_id??'not sent')+' · <b>release:</b> '+escapeHtml(s.release_request_id??'not sent')+'</p><table><tr><th>Participant</th><th>Security</th><th>Heartbeat</th><th>Last seen</th></tr>'+rows+'</table>';
   el('demo3-join').style.display=participant?'none':'inline-block'; el('demo3-leave').style.display=participant?'inline-block':'none';
 }
 function escapeHtml(v){const d=document.createElement('div');d.textContent=String(v);return d.innerHTML;}
 function save(){participant?sessionStorage.setItem(key,JSON.stringify(participant)):sessionStorage.removeItem(key);}
 async function heartbeat(){
   if(!participant)return;
   try{const d=await request('heartbeat',{session_id:participant.session_id,participant_token:participant.participant_token},'POST');render(d.session);}
   catch(e){message('Heartbeat failed: '+e.message,true);}
 }
 window.demo3Load=async function(){
   try{
     const d=await request('list'); const sessions=d.sessions||[];
     el('demo3-list').innerHTML=sessions.length?sessions.map((s,i)=>'<button type="button" data-i="'+i+'">'+escapeHtml(s.name)+' · '+s.seconds_remaining+'s · '+s.summary.participants+' participant(s)</button>').join(' '):'<p>No joinable public exercise is active.</p>';
     el('demo3-list').querySelectorAll('button').forEach(b=>b.onclick=()=>render(sessions[Number(b.dataset.i)]));
     if(sessions.length&&!selected)render(sessions[0]); message('Live server state loaded.',false);
   }catch(e){message('Demo 3 unavailable: '+e.message,true);}
 };
 window.demo3Join=async function(){
   if(!selected)return;
   try{const d=await request('join',{session_id:selected.session_id,nickname:el('demo3-nickname').value},'POST');participant={session_id:selected.session_id,participant_token:d.participant_token,participant_id:d.participant_id};save();render(d.session);timer=setInterval(heartbeat,2000);message('Joined. Heartbeats are now visible to the exercise.',false);}
   catch(e){message('Unable to join: '+e.message,true);}
 };
 window.demo3Leave=async function(){
   if(!participant)return;
   try{const d=await request('leave',{session_id:participant.session_id,participant_token:participant.participant_token},'POST');clearInterval(timer);timer=null;participant=null;save();render(d.session);message('You left this exercise. The shared exercise continues for other participants.',false);}
   catch(e){message('Unable to leave: '+e.message,true);}
 };
 window.demo3CopyDebug=function(){
   if(!selected)return;
   const report=['TaraSec HTTP Demo 3 debug report','ai_background=https://tarasec.org/ai/demo-guide/','secrets=omitted (participant token)','',JSON.stringify(selected,null,2)].join('\n');
   navigator.clipboard.writeText(report).then(()=>message('Debug information copied.',false)).catch(()=>window.prompt('Copy this report:',report));
 };
 try{participant=JSON.parse(sessionStorage.getItem(key)||'null');}catch(_){participant=null;}
 window.demo3Load().then(()=>{if(participant){timer=setInterval(heartbeat,2000);heartbeat();}});
})();
</script>
<?php } ?>
<p><a href="index.php?f=appDemos">Back to HTTP demo dashboard</a></p>
</div>
<?php
}
?>