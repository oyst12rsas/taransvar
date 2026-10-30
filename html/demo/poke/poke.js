'use strict';
const gateway = '100.68.165.190';
const nodes = {
  roquefort: {name: 'Roquefort', address: '100.68.176.110'},
  camembert: {name: 'Camembert', address: '100.68.149.164'},
  dbserver1: {name: 'dbserver1', address: '100.68.126.0'},
  gouda: {name: 'Gouda', address: '100.68.51.247'},
};
const $ = id => document.getElementById(id);
let token = '', running = false, busy = false, generation = 0, timer, started = 0;
let baseline = false, gatewayState = 'unknown', receivers = {}, debug = {};
function card(id, name, role, poke) {
  const article = document.createElement('article'); article.id = id;
  const small = document.createElement('small'); small.textContent = role;
  const title = document.createElement('h2'); title.textContent = name;
  const state = document.createElement('p'); state.className = 'state'; state.textContent = '○ UNKNOWN';
  const detail = document.createElement('p'); detail.className = 'detail'; detail.textContent = 'Waiting to start';
  article.append(small, title, state, detail);
  if (poke) { const button = document.createElement('button'); button.textContent = 'Poke ' + name; button.disabled = true; button.onclick = () => action(() => pokeNode(id)); article.append(button); }
  $('cards').append(article);
}
card('gateway', 'Standard gateway', 'Current local state', false);
Object.entries(nodes).forEach(([id, node]) => card(id, node.name, 'Last observed', true));
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
  debug = {generated_at: new Date().toISOString(), demo: 'web-poke', gateway: gatewayState, receivers,
    baseline_confirmed: baseline, session_age_seconds: Math.floor((Date.now() - started) / 1000),
    note: 'Session token omitted. Receiver observations change only on direct Poke.'};
  $('debug').textContent = JSON.stringify(debug, null, 2);
  buttons();
}
async function request(url, body) {
  const response = await fetch(url, {method: body ? 'POST' : 'GET', body: body ? new URLSearchParams(body) : undefined,
    credentials: 'omit', cache: 'no-store', referrerPolicy: 'no-referrer', signal: AbortSignal.timeout(5000)});
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.error || 'Request failed (' + response.status + ')');
  return data;
}
async function localState() {
  const data = await request('/script/appLocalInfection.php');
  gatewayState = data.infected ? 'infected' : 'clean';
  render('gateway', {state: gatewayState, message: data.infected ? 'Gateway sees this device as infected' : 'Gateway sees this device as clean', observed_at: data.server_time});
  return data;
}
async function pokeNode(id) {
  render(id, {state: 'unknown', message: 'Sending a direct Poke…'});
  try {
    const data = await request('http://' + nodes[id].address + '/script/webPokeReceiver.php', {token});
    receivers[id] = data.observation;
  } catch (error) { receivers[id] = {state: 'unknown', message: error.message}; }
  render(id, receivers[id]);
}
function confirmBaseline() {
  if (!baseline && gatewayState === 'clean' && Object.keys(nodes).every(id => receivers[id]?.state === 'clean')) {
    baseline = true;
    $('message').textContent = 'All five confirmed clean. Mark this device infected, then Poke the receivers one at a time.';
  }
}
async function poll(version) {
  if (!running || busy || version !== generation) return;
  const began = Date.now();
  if (began - started >= 1800000) { stop('Session expired. Reset to start again. The gateway demo mark may still be active.'); return; }
  const results = await Promise.allSettled([request('/script/appLocalInfection.php'), request('/script/webPokeStatus.php?token=' + token)]);
  if (!running || version !== generation) return;
  const [gw, snapshots] = results;
  if (gw.status === 'fulfilled') {
    gatewayState = gw.value.infected ? 'infected' : 'clean';
    render('gateway', {state: gatewayState, message: gw.value.message, observed_at: gw.value.server_time});
  } else { gatewayState = 'unknown'; render('gateway', {state: 'unknown', message: gw.reason.message}); }
  if (snapshots.status === 'fulfilled') receivers = snapshots.value.receivers;
  else receivers = Object.fromEntries(Object.keys(nodes).map(id => [id, {state: 'unknown', message: snapshots.reason.message}]));
  Object.keys(nodes).forEach(id => render(id, receivers[id]));
  confirmBaseline(); publish();
  $('freshness').textContent = 'Status checked ' + new Date().toLocaleTimeString() + ' · receiver cards retain their last Poke';
  timer = setTimeout(() => poll(version), Math.max(0, 1000 - (Date.now() - began)));
}
async function action(work) {
  if (busy) return;
  clearTimeout(timer); ++generation; busy = true; buttons();
  try { await work(); confirmBaseline(); }
  catch (error) { $('message').textContent = error.message; }
  finally { busy = false; publish(); if (running) poll(generation); }
}
$('start').onclick = () => action(async () => {
  if (location.protocol !== 'http:' || location.hostname !== gateway || (location.port && location.port !== '80')) {
    $('open').hidden = false;
    throw new Error('Open this demo on Standard gateway over HTTP while connected through it.');
  }
  running = false; baseline = false; gatewayState = 'unknown'; receivers = {};
  Object.keys(nodes).forEach(id => render(id, {state: 'unknown', message: 'Collecting clean baseline…'}));
  token = Array.from(crypto.getRandomValues(new Uint8Array(32)), b => b.toString(16).padStart(2, '0')).join('');
  started = Date.now();
  await request('/script/appInfectionControl.php', {infected: '0', demo: '1'});
  const local = await localState();
  if (local.infected) throw new Error('Independent security evidence is still active. Review it before starting this demo.');
  running = true;
  $('message').textContent = 'Collecting real clean observations. If a receiver is unknown or still infected, wait briefly and Poke it again.';
  await Promise.all(Object.keys(nodes).map(pokeNode));
});
$('mark').onclick = () => action(async () => {
  if (!running || !baseline || gatewayState !== 'clean') return;
  await request('/script/appInfectionControl.php', {infected: '1', demo: '1'});
  await localState();
  $('message').textContent = 'Gateway marked. Receivers retain their last observation. Poke each one to send a new tagged connection.';
});
function stop(message) {
  running = false; clearTimeout(timer); ++generation; buttons();
  $('message').textContent = message;
  $('freshness').textContent = 'Stopped · displayed observations are no longer refreshed';
}
$('stop').onclick = () => stop('Stopped observing. Use Reset before leaving to clear this device’s demo mark.');
$('copy').onclick = async () => {
  try { await navigator.clipboard.writeText(JSON.stringify(debug, null, 2)); $('copy').textContent = 'Copied'; }
  catch { const range = document.createRange(); range.selectNodeContents($('debug')); const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); $('copy').textContent = 'Selected — copy manually'; }
};
buttons();
