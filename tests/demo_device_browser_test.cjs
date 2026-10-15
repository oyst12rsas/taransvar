const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const nodes={};
function node(id){return nodes[id]??={textContent:'',innerHTML:'',style:{},value:'',className:'',setAttribute(){},querySelectorAll(){return []}};}
const session={session_id:12,name:'Existing app exercise',threshold:5,state:'contained',participants:[{participant_id:44,nickname:'Phone (app)',severity:10,decision:'silent',seconds_since_seen:9}],target_ip:'100.68.126.0',containment_seconds:120,release_seconds_remaining:50};
let fail=false,calls=[],timers=[];
const context={console,URLSearchParams,AbortSignal,Date,JSON,AbortController,setTimeout(){return 1},clearTimeout(){},setInterval(fn){timers.push(fn);return 1},clearInterval(){},location:{origin:'http://100.68.126.0'},sessionStorage:{getItem(){return null},setItem(){},removeItem(){}},navigator:{},window:{},document:{getElementById:node,createElement:()=>node('created'),querySelectorAll:()=>[]},fetch:async url=>{
 calls.push(url);if(fail)throw Error('offline');
 let d=url.includes('appLocal')?{ok:true,infected:true,severity:10,client_ip:'10.100.0.150'}:url.includes('DeviceSession')?{ok:true,demo:{session_id:12,participant_id:44,fresh:true,session}}:url.includes('appDemoGateway')?{ok:true,gateway:{recognized:true,address:'100.68.165.190',name:'Standard'}}:{ok:true,sessions:[]};
 return {ok:true,json:async()=>d};
}};
let source=fs.readFileSync('html/gatekeeper/func/appDemo3.php','utf8').split('<script>')[2].split('</script>')[0];
source=source.replace('try{participant=JSON.parse(sessionStorage', 'globalThis.test={devicePoll,setGateway:g=>gateway=g};try{participant=JSON.parse(sessionStorage');
context.window=context;vm.createContext(context);vm.runInContext(source,context);
(async()=>{
 await new Promise(r=>setImmediate(r));
 context.test.setGateway({recognized:true,address:'100.68.165.190'});
 await context.test.devicePoll();
 assert(node('demo3-device-session').textContent.includes('already in Demo 3 #12'));
 assert(node('demo3-device-state').textContent.includes('10.100.0.150'));
 assert(node('demo3-device-state').textContent.includes('SUSPICIOUS'));
 assert.equal(node('demo3-join-controls').style.display,'none');
 assert(!calls.some(x=>x.includes('action=heartbeat')||x.includes('action=join')||x.includes('action=severity')),'Discovery must not change participant evidence');
 fail=true;await context.test.devicePoll();
 assert(node('demo3-device-state').textContent.includes('stale'));
 assert(node('demo3-device-state').textContent.includes('no clean state inferred'));
 console.log('PASS: cross-platform discovery, shared identity and state, no duplicate joins/heartbeats, failure remains unknown');
})().catch(e=>{console.error(e);process.exitCode=1});
