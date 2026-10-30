'use strict';
const $ = id => document.getElementById(id);
let nodes = {}, token = '', sessionId = '', expires = 0;
let running = false, busy = false, generation = 0, timer, started = 0;
let baseline = false, gatewayState = 'unknown', receivers = {}, debug = {};
function card(id, name, role, poke) {
  const article = document.createElement('article'); article.id = id;
  const small = document.createElement('small'); small.textContent = role;
  const title = document.createElement('h2'); title.textContent = name;
  const state = document.createElement('p'); state.className = 'state'; state.textContent = '○ UNKNOWN';
  const detail = document.createElement('p'); detail.className = 'detail'; detail.textContent = 'Waiting for DB authorization';
  article.append(small, title, state, detail);
  if (poke) { const button = document.createElement('button'); button.textContent = 'Poke ' + name; button.disabled = true; button.onclick = () => action(() => pokeNode(id)); article.append(button); }
  $('cards').append(article);
}
card('gateway', 'Your gateway', 'Current local state', false);
function render(id, result) {
  const el = $(id), state = ['clean', 'infected'].includes(result?.state) ? result.state : 'unknown';
  el.className = state;
  el.querySelector('.state').textContent = ({clean: '● CLEAN', infected: '● INFECTED', unknown: '○ UNKNOWN'})[state];
  el.querySelector('.detail').textContent = (result?.message || 'No evidence') + (result?.observed_at ? ' · ' + new Date(result.observed_at).toLocaleTimeString() : '');
}
function buttons() {
  $('start').disabled = busy;
  $('mark').disabled = !running || busy || !baseline || gatewayState !== 'clean';
  $('stop').disabled = !running || busy;
  Object.keys(nodes).forEach(id => $(id).querySelector('button').disabled = !running || busy);
}
function publish() {
  debug = {generated_at: new Date().toISOString(), demo: 'db-authorized-web-poke', gateway: gatewayState, receivers,
    baseline_confirmed: baseline, session_age_seconds: started ? Math.floor((Date.now() - started) / 1000) : 0,
    note: 'Session and Poke tokens omitted. Receiver observations change only on direct Poke.'};
  $('debug').textContent = JSON.stringify(debug, null, 2); buttons();
}
async function request(url, body) {
  const response = await fetch(url, {method: 'POST', body: new URLSearchParams(body), credentials: 'omit',
    cache: 'no-store', referrerPolicy: 'no-referrer', signal: AbortSignal.timeout(15000)});
  const data = await response.json();
  if (!response.ok || !data.ok) { const error = new Error(data.error || 'Request failed (' + response.status + ')'); error.status = response.status; throw error; }
  return data;
}
const gatewayCall = (action, extra = {}) => request('/script/webPokeGateway.php', {action, token, ...extra});
function gatewayResult(result) { gatewayState = result?.state || 'unknown'; render('gateway', result); }
function deny(message) {
  running = false; clearTimeout(timer); ++generation; gatewayState = 'unknown';
  render('gateway', {state: 'unknown', message});
  for (const id of Object.keys(nodes)) { receivers[id] = {state: 'unknown', message: 'Demo access unavailable'}; render(id, receivers[id]); }
  $('message').textContent = message; publish();
}
async function pokeNode(id) {
  const version = generation;
  render(id, {state: 'unknown', message: 'Sending an authorized direct Poke…'});
  try {
    const data = await request('http://' + nodes[id].address + '/script/webPokeReceiver.php', {session_id: sessionId, poke_token: nodes[id].poke_token});
    if (version !== generation) return;
    receivers[id] = data.observation;
  } catch (error) {
    if (version !== generation) return;
    receivers[id] = {state: 'unknown', message: error.message};
    if (error.status === 403) deny('DB authorization was refused or expired. Ask the demo operator.');
  }
  if (version === generation) render(id, receivers[id]);
}
function confirmBaseline() {
  if (!baseline && gatewayState === 'clean' && Object.keys(nodes).length > 0 && Object.keys(nodes).every(id => receivers[id]?.state === 'clean')) {
    baseline = true;
    $('message').textContent = 'All ' + (Object.keys(nodes).length + 1) + ' computers confirmed clean. Mark this device infected, then Poke the receivers one at a time.';
  }
}
async function poll(version) {
  if (!running || busy || version !== generation) return;
  const began = Date.now();
  if (began >= expires * 1000) { deny('Session expired. The operator may need to clear any remaining demo mark.'); return; }
  try {
    const data = await gatewayCall('status');
    if (!running || version !== generation) return;
    gatewayResult(data.gateway); receivers = data.receivers;
    Object.keys(nodes).forEach(id => render(id, receivers[id]));
    confirmBaseline(); publish();
    $('freshness').textContent = 'DB status checked ' + new Date().toLocaleTimeString() + ' · last Poke observations';
  } catch (error) {
    if (!running || version !== generation) return;
    if (error.status === 403) { deny(error.message); return; }
    gatewayResult({state: 'unknown', message: error.message});
    Object.keys(nodes).forEach(id => { receivers[id] = {state: 'unknown', message: error.message}; render(id, receivers[id]); });
    publish();
  }
  if (running && version === generation) timer = setTimeout(() => poll(version), Math.max(0, 1000 - (Date.now() - began)));
}
async function action(work) {
  if (busy) return;
  clearTimeout(timer); ++generation; busy = true; buttons();
  try { await work(); confirmBaseline(); }
  catch (error) { if (error.status === 403) deny(error.message); else $('message').textContent = error.message; }
  finally { busy = false; publish(); if (running) poll(generation); }
}
$('start').onclick = () => action(async () => {
  running = false; baseline = false; gatewayState = 'unknown';
  render('gateway', {state: 'unknown', message: 'Awaiting DB authorization'});
  Object.keys(nodes).forEach(id => render(id, {state: 'unknown', message: 'Awaiting DB authorization'}));
  $('message').textContent = 'Asking the DB server to authorize this gateway and register the receivers…';
  const data = await gatewayCall('start');
  token = data.token; sessionId = data.session_id; expires = data.expires; started = Date.now();
  nodes = data.nodes; receivers = {};
  $('cards').replaceChildren(); card('gateway', data.gateway_name, 'Current local state', false);
  Object.entries(nodes).forEach(([id, node]) => card(id, node.name, 'Last observed', true));
  gatewayResult(data.gateway); running = true;
  if (gatewayState !== 'clean') {
    $('message').textContent = 'Independent or earlier demo evidence remains active. Review it before starting; this demo will not clear it.';
    return;
  }
  $('message').textContent = 'DB authorized. Collecting clean observations; retry a Poke if kernel changes are still propagating.';
  await Promise.all(Object.keys(nodes).map(pokeNode));
});
$('mark').onclick = () => action(async () => {
  if (!running || !baseline || gatewayState !== 'clean') return;
  const data = await gatewayCall('mark'); gatewayResult(data.gateway);
  $('message').textContent = 'Gateway marked. Poke each receiver to send a new tagged connection.';
});
$('stop').onclick = () => {
  running = false; clearTimeout(timer); ++generation; buttons();
  $('message').textContent = 'Stopped observing. Use Reset before leaving to clear this session’s demo mark.';
  $('freshness').textContent = 'Stopped · observations no longer refreshed';
};
$('copy').onclick = async () => {
  try { await navigator.clipboard.writeText(JSON.stringify(debug, null, 2)); $('copy').textContent = 'Copied'; }
  catch { const range = document.createRange(); range.selectNodeContents($('debug')); const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); $('copy').textContent = 'Selected — copy manually'; }
};
buttons();
