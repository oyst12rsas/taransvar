<?php
require_once __DIR__ . '/db_connect.php';
$cfg = [];
if (is_readable('/etc/tarasec-poster.conf')) {
    $parsed = parse_ini_file('/etc/tarasec-poster.conf', false, INI_SCANNER_RAW);
    if (is_array($parsed)) $cfg = $parsed;
}
function pcfg(array $c,string $k,string $d=''): string { return trim((string)($c[$k] ?? $d)); }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$siteName=pcfg($cfg,'SITE_NAME','TaraSec WiFi');$siteAddress=pcfg($cfg,'SITE_ADDRESS');$ssid=pcfg($cfg,'SSID','TaraSec');$security=strtoupper(pcfg($cfg,'WIFI_SECURITY','OPEN'));$password=pcfg($cfg,'WIFI_PASSWORD');
$portal=pcfg($cfg,'PORTAL_URL');if($portal===''){ $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';$portal=$scheme.'://'.($_SERVER['HTTP_HOST']??'localhost').'/'; }
$payHeading=pcfg($cfg,'PAYMENT_HEADING','Pay manually');$payText=pcfg($cfg,'PAYMENT_INSTRUCTIONS','Ask the hotspot owner for payment instructions.');$fee=pcfg($cfg,'TARASEC_PAYMENT_FEE_PERCENT','10');$contact=pcfg($cfg,'TARASEC_PAYMENT_CONTACT_URL','https://tarasec.org/');
$wifiPayload = $security==='WPA' ? 'WIFI:T:WPA;S:'.str_replace(['\\',';',',',':'],['\\\\','\\;','\\,','\\:'],$ssid).';P:'.str_replace(['\\',';',',',':'],['\\\\','\\;','\\,','\\:'],$password).';;' : 'WIFI:T:nopass;S:'.str_replace(['\\',';',',',':'],['\\\\','\\;','\\,','\\:'],$ssid).';;';
$plans=[];try{$st=$conn->query('SELECT name,type,price,description FROM plans ORDER BY price ASC');$plans=$st?$st->fetchAll(PDO::FETCH_ASSOC):[];}catch(Throwable $e){}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($siteName)?> WiFi</title>
<style>
@page{size:A4;margin:9mm}body{font-family:Arial,sans-serif;margin:0;color:#111}.poster{border:5px solid #111;padding:18px;min-height:255mm;box-sizing:border-box}h1{font-size:42px;text-align:center;margin:4px 0}.address{text-align:center;font-size:17px;margin-bottom:20px}.hero{text-align:center;font-size:25px;font-weight:bold}.qrs{display:flex;gap:35px;justify-content:center;margin:22px 0}.qr{text-align:center;width:230px}.qrbox{width:210px;height:210px;margin:auto}.ssid{font-size:24px;font-weight:bold}.payment{border:3px solid #111;padding:18px;margin:20px 0;text-align:center}.payment .instruction{font-size:28px;font-weight:bold;white-space:pre-wrap}.plans{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}.plan{border:1px solid #777;padding:9px 14px;min-width:130px;text-align:center}.upsell{margin-top:18px;padding:12px;background:#eee;text-align:center;font-size:16px}.small{font-size:12px;color:#555}.toolbar{text-align:center;margin:10px}.toolbar button{padding:10px 18px}@media print{.toolbar{display:none}.poster{border-width:4px}}
</style></head><body><div class="toolbar"><button onclick="window.print()">Print poster</button> &nbsp; Configure with <code>sudo bash misc/configure_hotspot_poster.sh</code></div><div class="poster">
<h1>WiFi ACCESS</h1><div class="address"><strong><?=h($siteName)?></strong><?php if($siteAddress!==''):?><br><?=h($siteAddress)?><?php endif;?></div><div class="hero">Scan • Pay • Connect</div>
<div class="qrs"><div class="qr"><div id="wifiQr" class="qrbox"></div><h2>1. Join Wi‑Fi</h2><div class="ssid"><?=h($ssid)?></div></div><div class="qr"><div id="portalQr" class="qrbox"></div><h2>2. Open hotspot</h2><div><?=h($portal)?></div></div></div>
<div class="payment"><h2><?=h($payHeading)?></h2><div class="instruction"><?=h($payText)?></div></div>
<?php if($plans):?><h2 style="text-align:center">Access plans</h2><div class="plans"><?php foreach($plans as $p):?><div class="plan"><strong><?=h((string)$p['name'])?></strong><br><?=h((string)$p['price'])?><?php if(!empty($p['type'])):?><br><small><?=h((string)$p['type'])?></small><?php endif;?></div><?php endforeach;?></div><?php endif;?>
<div class="upsell"><strong>Want payments and access activated automatically?</strong><br>TaraSec can integrate supported mobile payments so customers do not need manual verification.<?php if(is_numeric($fee)):?> Configured service fee: approximately <strong><?=h($fee)?>%</strong>.<?php endif;?> <br><span class="small">Ask TaraSec for onboarding: <?=h($contact)?></span></div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><script>
(function(){if(typeof QRCode==='undefined'){document.getElementById('wifiQr').textContent='QR library unavailable';document.getElementById('portalQr').textContent='QR library unavailable';return;}new QRCode(document.getElementById('wifiQr'),{text:<?=json_encode($wifiPayload)?>,width:210,height:210,correctLevel:QRCode.CorrectLevel.M});new QRCode(document.getElementById('portalQr'),{text:<?=json_encode($portal)?>,width:210,height:210,correctLevel:QRCode.CorrectLevel.M});})();
</script></body></html>
