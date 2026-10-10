'use strict';
const el=id=>document.getElementById(id);
let running=false,busy=false,timer=null,path=null,cycle=0,lastComplete=0,lastSignature='',evidence={};
function ipv4(value){return /^(\d{1,3}\.){3}\d{1,3}$/.test(value)&&value.split('.').every(n=>Number(n)<=255);}
async function request(base,file,body){
 const response=await fetch(base+'/script/'+file,{method:body?'POST':'GET',body:body?new URLSearchParams(body):undefined,cache:'no-store',credentials:'omit',signal:AbortSignal.timeout(6000)});
 const data=await response.json();if(!response.ok||data.ok!==true)throw Error(data.error||'Status unavailable');return data;
}
function event(text){const list=el('events');if(list.textContent==='No events yet.')list.replaceChildren();const item=document.createElement('li');item.textContent=new Date().toLocaleTimeString()+' · '+text;list.prepend(item);while(list.children.length>30)list.lastChild.remove();}
function card(id,state,detail,tone=''){el(id).className=tone;el(id).querySelector('.state').textContent=state;el(id).querySelector('.detail').textContent=detail;}
function buttons(){el('start').disabled=running||busy;['mark','clear','stop'].forEach(id=>el(id).disabled=!running||busy);['gateway','receiverA','receiverB'].forEach(id=>el(id).disabled=running||busy);}
async function observation(base,file){try{return {data:await request(base,file),checked_at:new Date().toISOString()};}catch(error){return {error:error.message,checked_at:new Date().toISOString()};}}
function receiver(id,result){
 const d=result.data;if(!d){card(id,'Unknown · request failed',result.error);return;}
 const fresh=d.source==='traffic'&&Number(d.trafficSecondsSince)>=0&&Number(d.trafficSecondsSince)<=2;
 const route=d.client_ip===path.gateway;
 if(!fresh||!route){card(id,route?'Waiting for fresh traffic evidence':'Different route observed','Source '+d.client_ip+' · evidence '+d.source+' · age '+d.trafficSecondsSince+'s');return;}
 const suspicious=Number(d.trafficSeverity)>1;
 card(id,suspicious?'Suspicious traffic observed':'Clean traffic observed','Source '+d.client_ip+' · traffic severity '+d.trafficSeverity+' · '+result.checked_at,suspicious?'bad':'good');
}
async function poll(){
 if(!running||busy)return;busy=true;buttons();
 try{
 const gateway=await observation(path.base,'appLocalInfection.php');
 const [a,b]=await Promise.all([observation(path.a,'appInfection.php'),observation(path.b,'appInfection.php')]);
 evidence={cycle:++cycle,client:'this browser',path,gateway,receivers:{a,b}};
 if(gateway.data){const d=gateway.data;card('gw',d.infected?'Client marked suspicious':'Client locally clear','Client '+d.client_ip+' · severity '+d.severity+' · '+gateway.checked_at,d.infected?'bad':'good');card('client','Requests sent','Gateway sees this computer as '+d.client_ip);}else{card('gw','Unknown · request failed',gateway.error);card('client','Route unverified','Gateway status unavailable');}
 receiver('a',a);receiver('b',b);
 const signature=JSON.stringify([gateway.data?.infected,gateway.error,el('a').querySelector('.state').textContent,el('b').querySelector('.state').textContent]);
 if(signature!==lastSignature){event('Gateway: '+el('gw').querySelector('.state').textContent+'; A: '+el('a').querySelector('.state').textContent+'; B: '+el('b').querySelector('.state').textContent);lastSignature=signature;}
 el('evidence').textContent=JSON.stringify(evidence,null,2);lastComplete=Date.now();
 el('message').textContent='Observing this computer through '+path.gateway+'. Gateway and receivers refresh together every cycle. Failed requests remain unknown.';
 }finally{busy=false;buttons();if(running)timer=setTimeout(poll,3000);}
}
el('start').onclick=()=>{
 const addresses=['gateway','receiverA','receiverB'].map(id=>el(id).value.trim());
 if(!addresses.every(ipv4)||new Set(addresses).size!==3){el('message').textContent='Enter three distinct IPv4 addresses for the gateway and two receivers.';return;}
 if(location.protocol==='https:'){el('message').textContent='Open this page over HTTP on a TaraSec node inside the demo network. This browser cannot call the current HTTP demo APIs from an HTTPS page.';return;}
 path={gateway:addresses[0],base:'http://'+addresses[0],a:'http://'+addresses[1],b:'http://'+addresses[2]};running=true;lastSignature='';event('Started browser traffic observations.');poll();
};
async function control(infected){
 if(!running||busy)return;clearTimeout(timer);busy=true;buttons();
 try{const d=await request(path.base,'appInfectionControl.php',{infected:infected?'1':'0',demo:'1'});event('Gateway acknowledged: '+d.message);el('explanation').textContent=infected?'The gateway accepted the demo mark. Wait for fresh receiver observations to confirm the assessment carried by subsequent traffic.':'Demo state cleared on the gateway. Independent security findings may remain active; watch subsequent receiver evidence.';card('gw','Change acknowledged · awaiting poll',d.message);}
 catch(error){event('Control failed: '+error.message);el('message').textContent='Gateway control failed: '+error.message;}
 finally{busy=false;buttons();poll();}
}
el('mark').onclick=()=>control(true);el('clear').onclick=()=>control(false);
el('stop').onclick=()=>{running=false;clearTimeout(timer);buttons();event('Observation stopped. Demo state remains until you clear it.');el('message').textContent='Stopped observing. Start again and clear demo state if you previously marked this client suspicious.';};
el('fullscreen').onclick=async()=>{document.body.classList.toggle('present');try{if(!document.fullscreenElement)await document.documentElement.requestFullscreen();else await document.exitFullscreen();}catch{};};
setInterval(()=>{if(lastComplete){const seconds=Math.floor((Date.now()-lastComplete)/1000);el('freshness').textContent='Cycle '+cycle+' · '+seconds+'s ago'+(!running?' · stopped':seconds>10?' · stale':'');}},1000);
