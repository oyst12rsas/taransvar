<?php
// TaraSec captive payment bridge. No PayMongo secret is stored on the hotspot.
// The hotspot authenticates to the private TaraSec Payment backend with its own token.

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

function failPage(string $message, int $status = 400): never {
    http_response_code($status);
    $m = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>TaraSec Payment</title><style>body{font-family:sans-serif;background:#eef3f8;margin:0;padding:24px}.card{max-width:560px;margin:auto;background:#fff;padding:24px;border-radius:14px;box-shadow:0 2px 12px #0002}.field,.btn{width:100%;box-sizing:border-box;padding:12px;margin-top:10px}.btn{background:#1265ad;color:#fff;border:0;border-radius:8px;font-weight:700}</style><div class="card"><h2>Payment unavailable</h2><p>'.$m.'</p><p><a href="http://status.client">Return to hotspot</a></p></div>';
    exit;
}

function cfg(): array {
    $path='/etc/tarasec/payment-client.php';
    if(!is_readable($path)) failPage('Online payment is not configured on this hotspot.',503);
    $c=require $path;
    if(!is_array($c)||empty($c['base_url'])||empty($c['hotspot_id'])||empty($c['api_token'])||$c['hotspot_id']==='CHANGE_ME'||$c['api_token']==='CHANGE_ME') failPage('Online payment is not configured on this hotspot.',503);
    return $c;
}

function api(array $c,string $method,string $path,?array $body=null): array {
    $ch=curl_init(rtrim((string)$c['base_url'],'/').$path);
    $headers=['Authorization: Bearer '.$c['api_token'],'Accept: application/json'];
    if($body!==null){$headers[]='Content-Type: application/json';curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_SLASHES));}
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$headers]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($raw===false||$status<200||$status>=300) failPage('The payment service could not be reached. Please try again or use the hotspot owner\'s manual payment option.',502);
    $j=json_decode($raw,true);
    if(!is_array($j)||empty($j['ok'])) failPage('The payment service returned an error. Please try again.',502);
    return $j;
}

$c=cfg();
$clientIp=(string)($_SERVER['REMOTE_ADDR']??'');
if(filter_var($clientIp,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false) failPage('Invalid hotspot client address.');

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $plan=trim((string)($_POST['plan_code']??''));
    $username=trim((string)($_POST['username']??''));
    if($plan===''||$username===''||strlen($username)>64) failPage('Choose a plan and enter your hotspot username.');
    $j=api($c,'POST','/v1/checkout',[
        'hotspot_id'=>$c['hotspot_id'],
        'plan_code'=>$plan,
        'client_ref'=>$clientIp,
        'client_ip'=>$clientIp,
        'username'=>$username,
    ]);
    if(empty($j['checkout_url'])) failPage('The payment provider did not return a checkout page.',502);
    header('Location: '.$j['checkout_url'],true,303);
    exit;
}

$plans=api($c,'GET','/v1/plans?hotspot_id='.rawurlencode((string)$c['hotspot_id']))['plans']??[];
if(!$plans) failPage('No online plans are currently enabled for this hotspot.',503);

echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>TaraSec Plans</title><style>body{font-family:sans-serif;background:#eef3f8;margin:0;padding:20px;color:#172233}.wrap{max-width:650px;margin:auto}.card{background:#fff;padding:20px;border-radius:12px;margin:12px 0;box-shadow:0 2px 12px #0002}.price{font-size:24px;font-weight:700;color:#1265ad}.field,.btn{width:100%;box-sizing:border-box;padding:12px;margin-top:10px}.btn{background:#1265ad;color:#fff;border:0;border-radius:8px;font-weight:700}.small{font-size:12px;color:#68788a}</style><div class="wrap"><h1>Get Internet access</h1><p>Choose a plan. Payments are processed securely by PayMongo. Your hotspot access is activated after TaraSec receives verified payment confirmation.</p>';
foreach($plans as $p){
    $code=htmlspecialchars((string)$p['code'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $name=htmlspecialchars((string)$p['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $desc=htmlspecialchars((string)($p['description']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $currency=htmlspecialchars((string)$p['currency'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $amount=number_format(((int)$p['amount_centavos'])/100,2);
    echo '<div class="card"><h2>'.$name.'</h2><div class="price">'.$currency.' '.$amount.'</div>'.($desc!==''?'<p>'.$desc.'</p>':'').'<form method="post"><input type="hidden" name="plan_code" value="'.$code.'"><label><b>Hotspot username</b></label><input class="field" name="username" autocomplete="username" required><button class="btn" type="submit">Pay securely</button></form></div>';
}
echo '<p class="small">If online payment is unavailable, ask the hotspot owner for the manual payment/access option.</p><p><a href="http://status.client">Return to hotspot</a></p></div>';
