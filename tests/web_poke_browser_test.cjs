const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.join(__dirname, '..');
(async () => {
  const browser = await chromium.launch({headless: true, ...(process.env.CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.CHROMIUM_EXECUTABLE_PATH, args: ['--no-sandbox', '--disable-dev-shm-usage', '--no-zygote', '--single-process', '--disable-gpu', '--disable-software-rasterizer']} : {})});
  try {
    // Deliberately not Standard gateway: receivers and URLs come only from the DB session.
    const gateway = '100.100.10.20';
    const page = await browser.newPage({viewport: {width: 390, height: 844}});
    let infected = false, otherDemo = false, independent = false, unavailable = false, allowed = true, statusCalls = 0, marks = 0;
    let serial = 0, currentId = '', observations = {}, pokes = [];
    const nodes = {roquefort:{name:'Roquefort',address:'100.68.176.110',poke_token:'r'},camembert:{name:'Camembert',address:'100.68.149.164',poke_token:'c'},dbserver1:{name:'dbserver1',address:'100.68.126.0',poke_token:'d'},gouda:{name:'Gouda',address:'100.68.51.247',poke_token:'g'}};
    const unknown = () => Object.fromEntries(Object.keys(nodes).map(id=>[id,{state:'unknown',message:'Not yet poked'}]));
    const gatewayState = () => ({state:infected||otherDemo||independent?'infected':'clean',message:independent?'Separate security finding':otherDemo?'Marked by another demo':'Gateway state',sources:{web_poke:infected?1:0,other_demo:otherDemo?1:0,security_finding:independent?1:0},observed_at:new Date().toISOString()});
    await page.route('http://**/*', async route => {
      const url = new URL(route.request().url()); let data;
      const reply=(status,body)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(body),headers:{'Access-Control-Allow-Origin':'http://'+gateway}});
      if (url.pathname === '/demo/poke/') return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(root,'html/demo/poke/index.php'),'utf8').replace(/^<\?php.*?\?>/s,'')});
      if (url.pathname.endsWith('/poke.js') || url.pathname.endsWith('/style.css')) return route.fulfill({contentType:url.pathname.endsWith('.js')?'text/javascript':'text/css',body:fs.readFileSync(path.join(root,'html/demo/poke',path.basename(url.pathname)))});
      assert.equal(route.request().method(),'POST');
      if(!allowed) return reply(403,{ok:false,error:'This gateway is not permitted to use the demo'});
      const input=new URLSearchParams(route.request().postData());
      if(url.pathname.endsWith('/webPokeGateway.php')) {
        assert.equal(url.hostname,gateway,'Only local gateway contacted for status');
        const action=input.get('action');
        if(action==='start') {
          if (independent) return reply(409,{ok:false,remediation_required:true,gateway:gatewayState(),error:'A separate security finding is active. Resolve it on the gateway before joining the demo.'});
          infected=false; otherDemo=false; currentId='session'+(++serial); observations=unknown();
          data={ok:true,token:'browser'+serial,session_id:currentId,expires:Math.floor(Date.now()/1000)+1800,gateway_name:'New gateway',gateway_ip:gateway,nodes,gateway:gatewayState()};
        } else {
          assert.equal(input.get('token'),'browser'+serial,'Server-issued device token sent');
          if(action==='status') { ++statusCalls; if(unavailable) return reply(503,{ok:false,error:'DB unavailable'}); }
          else if(action==='mark') { infected=true; ++marks; }
          else assert.fail('Unexpected gateway action');
          data={ok:true,gateway:gatewayState(),receivers:observations,remediation_required:independent};
        }
      } else if(url.pathname.endsWith('/webPokeReceiver.php')) {
        const id=Object.keys(nodes).find(id=>nodes[id].address===url.hostname); assert.ok(id);
        assert.equal(input.get('session_id'),currentId); assert.equal(input.get('poke_token'),nodes[id].poke_token);
        pokes.push(id); data={ok:true,observation:{state:infected||otherDemo?'infected':'clean',message:'Test traffic observation',observed_at:new Date().toISOString()}};
        observations[id]=data.observation;
      } else assert.fail('Legacy or unintended endpoint called: '+url.pathname);
      return reply(200,data);
    });
    const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await page.goto('http://'+gateway+'/demo/poke/');
    await page.click('#start'); await page.waitForFunction(()=>!document.getElementById('mark').disabled);
    assert.equal(await page.locator('article.clean').count(),5); assert.equal(pokes.length,4);
    assert.equal(await page.locator('#gateway h2').textContent(),'New gateway');
    await page.click('#mark'); await page.waitForFunction(()=>document.querySelector('#gateway .state').textContent.includes('INFECTED'));
    await page.waitForFunction(()=>!document.querySelector('#roquefort button').disabled);
    await page.waitForTimeout(2200);
    assert.equal(await page.locator('article.infected').count(),1); assert.equal(pokes.length,4,'Polling never pokes receivers'); assert.ok(statusCalls>=2);
    for(const id of Object.keys(nodes)) {
      await page.click('#'+id+' button'); await page.waitForFunction(id=>document.querySelector('#'+id+' .state').textContent.includes('INFECTED'),id);
      await page.waitForFunction(id=>!document.querySelector('#'+id+' button').disabled,id);
    }
    assert.equal(await page.locator('article.infected').count(),5);
    unavailable=true; await page.waitForFunction(()=>document.querySelector('#roquefort .state').textContent.includes('UNKNOWN'));
    assert.equal(await page.locator('article.unknown').count(),5,'DB outage never leaves old green status');
    unavailable=false;
    await page.click('#start'); await page.waitForFunction(()=>!document.getElementById('mark').disabled);
    assert.equal(await page.locator('article.clean').count(),5);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
    if(process.env.WEB_POKE_SCREENSHOT) await page.screenshot({path:process.env.WEB_POKE_SCREENSHOT,fullPage:true});
    const report=await page.locator('#debug').textContent();
    assert.ok(!report.includes('browser'+serial)&&!report.includes('poke_token'),'Debug omits capabilities');
    allowed=false;
    await page.waitForFunction(()=>document.getElementById('message').textContent.includes('not permitted'));
    assert.equal(await page.locator('article.unknown').count(),5);
    assert.ok(await page.locator('#mark').isDisabled() && await page.locator('#roquefort button').isDisabled(),'Policy change disables running session');
    const stopped=statusCalls; await page.waitForTimeout(1200); assert.equal(statusCalls,stopped,'Denial stops polling');
    allowed=true; otherDemo=true;
    await page.click('#start'); await page.waitForFunction(()=>!document.getElementById('mark').disabled);
    await page.waitForFunction(()=>!document.querySelector('#roquefort button').disabled);
    assert.equal(await page.locator('article.clean').count(),5,'Older app/demo marks are reset automatically');
    assert.ok(!await page.locator('#mark').isDisabled()); assert.equal(marks,1);
    otherDemo=false; independent=true;
    const priorPokes=pokes.length;
    await page.click('#start'); await page.waitForFunction(()=>document.getElementById('message').textContent.includes('before joining'));
    assert.ok((await page.locator('#gateway .state').textContent()).includes('(SECURITY FINDING)'));
    assert.ok(await page.locator('#mark').isDisabled() && await page.locator('#roquefort button').isDisabled());
    assert.equal(pokes.length,priorPokes,'Non-demo finding prevents participant Pokes');
    independent=false;
    await page.click('#start'); await page.waitForFunction(()=>!document.getElementById('mark').disabled);
    assert.equal(await page.locator('article.clean').count(),5,'Resolved finding permits a fresh clean baseline');
    await page.click('#stop'); await page.waitForTimeout(200); const stoppedAgain=statusCalls;
    await page.waitForTimeout(1200); assert.equal(statusCalls,stoppedAgain);
    assert.deepEqual(errors,[]);
    console.log('Browser passed: dynamic gateway, DB-issued sessions, mark/Poke isolation, combined polling, DB outage, reset, immediate policy denial, independent findings, stop and phone layout.');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exit(1);});
