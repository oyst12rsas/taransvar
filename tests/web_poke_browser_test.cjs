const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.join(__dirname, '..');
(async () => {
  const browser = await chromium.launch({headless: true, ...(process.env.CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.CHROMIUM_EXECUTABLE_PATH, args: ['--no-sandbox', '--disable-dev-shm-usage', '--no-zygote', '--single-process', '--disable-gpu', '--disable-software-rasterizer']} : {})});
  try {
    const page = await browser.newPage({viewport: {width: 390, height: 844}});
    let infected = false, independent = false, unavailable = false, statusCalls = 0, controlCalls = 0;
    const observations = {}, pokes = [];
    const names = { '100.68.176.110':'roquefort', '100.68.149.164':'camembert', '100.68.126.0':'dbserver1', '100.68.51.247':'gouda' };
    const unknown = () => Object.fromEntries(Object.values(names).map(id => [id, {state:'unknown',message:'Not yet poked'}]));
    await page.route('http://**/*', async route => {
      const url = new URL(route.request().url());
      let data;
      if (url.pathname === '/demo/poke/') return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(root,'html/demo/poke/index.php'),'utf8').replace(/^<\?php.*?\?>/s,'')});
      if (url.pathname.endsWith('/poke.js') || url.pathname.endsWith('/style.css')) return route.fulfill({contentType:url.pathname.endsWith('.js')?'text/javascript':'text/css',body:fs.readFileSync(path.join(root,'html/demo/poke',path.basename(url.pathname)))});
      if (url.pathname.endsWith('/appInfectionControl.php')) { ++controlCalls; infected = new URLSearchParams(route.request().postData()).get('infected') === '1'; data={ok:true}; }
      else if (url.pathname.endsWith('/appLocalInfection.php')) data={ok:true,infected:infected||independent,message:'Gateway state',server_time:new Date().toISOString()};
      else if (url.pathname.endsWith('/webPokeReceiver.php')) {
        assert.equal(route.request().method(),'POST','Only direct Pokes contact receivers from browser');
        const token = new URLSearchParams(route.request().postData()).get('token');
        observations[token] ||= unknown();
        const id = names[url.hostname]; assert.ok(id); pokes.push(id);
        data = {ok:true,observation:{state:infected?'infected':'clean',observed_at:new Date().toISOString(),message:'Actual test observation'}};
        observations[token][id] = data.observation;
      }
      else if (url.pathname.endsWith('/webPokeStatus.php')) {
        ++statusCalls;
        if (unavailable) return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({ok:false,error:'Unavailable'})});
        data={ok:true,receivers:observations[url.searchParams.get('token')]||unknown()};
      } else return route.abort();
      return route.fulfill({contentType:'application/json',body:JSON.stringify(data),headers:{'Access-Control-Allow-Origin':'http://100.68.165.190'}});
    });
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.goto('http://100.68.165.190/demo/poke/');
    await page.click('#start');
    await page.waitForFunction(() => !document.getElementById('mark').disabled);
    assert.equal(await page.locator('article.clean').count(),5);
    assert.equal(pokes.length,4);
    await page.click('#mark');
    await page.waitForFunction(() => document.querySelector('#gateway .state').textContent.includes('INFECTED'));
    await page.waitForFunction(() => document.querySelector('#roquefort button').disabled === false);
    await page.waitForTimeout(2200);
    assert.equal(await page.locator('article.infected').count(),1);
    assert.equal(pokes.length,4,'Polling never pokes receivers');
    assert.ok(statusCalls>=2,'Status refreshes once per second');
    for (const id of Object.values(names)) {
      await page.click('#'+id+' button');
      await page.waitForFunction(id => document.querySelector('#'+id+' .state').textContent.includes('INFECTED'),id);
      await page.waitForFunction(id => !document.querySelector('#'+id+' button').disabled,id);
    }
    assert.equal(await page.locator('article.infected').count(),5);
    unavailable=true;
    await page.waitForFunction(() => document.querySelector('#roquefort .state').textContent.includes('UNKNOWN'));
    assert.equal(await page.locator('article.unknown').count(),4,'Failed snapshots cannot stay green/red');
    unavailable=false;
    await page.click('#start');
    await page.waitForFunction(() => !document.getElementById('mark').disabled);
    assert.equal(await page.locator('article.clean').count(),5,'Reset obtains fresh clean baseline');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),false,'Phone layout fits');
    await page.click('#stop');
    await page.waitForTimeout(200);
    const stoppedCalls=statusCalls;
    await page.waitForTimeout(1200);
    assert.equal(statusCalls,stoppedCalls,'Stop ends polling');
    independent=true;
    await page.click('#start');
    await page.waitForFunction(() => document.getElementById('message').textContent.includes('Independent security evidence'));
    assert.equal(await page.locator('#mark').isDisabled(),true);
    assert.equal(await page.locator('#stop').isDisabled(),true);
    assert.equal(await page.locator('#debug').textContent().then(s=>s.includes('token=')),false);
    assert.ok(controlCalls>=4); assert.deepEqual(errors,[]);
    console.log('Browser flow passed: clean baseline, mark, independent Pokes, read-only polling, failures, reset, stop, real findings and phone layout.');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exit(1);});
