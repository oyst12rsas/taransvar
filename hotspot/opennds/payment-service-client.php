<?php

declare(strict_types=1);

function tarasec_payment_client_config(): array {
    $path=getenv('TARASEC_PAYMENT_CORE_CLIENT') ?: '/etc/tarasec/payment-core-client.php';
    if(!is_readable($path)) throw new RuntimeException('payment_service_client_missing');
    $cfg=require $path;
    if(!is_array($cfg)||empty($cfg['base_url'])||empty($cfg['token'])) throw new RuntimeException('payment_service_client_invalid');
    return $cfg;
}

function tarasec_payment_request(string $action,string $method,array $payload=[]): array {
    $cfg=tarasec_payment_client_config();
    $url=rtrim((string)$cfg['base_url'],'/').'/internal-credit.php?action='.rawurlencode($action);
    if($method==='GET' && $payload) $url.='&'.http_build_query($payload);
    $ch=curl_init($url);
    $headers=['Accept: application/json','X-TaraSec-Service-Token: '.(string)$cfg['token']];
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>$headers];
    if($method==='POST'){
        $body=json_encode($payload,JSON_UNESCAPED_SLASHES);
        $opts[CURLOPT_POST]=true; $opts[CURLOPT_POSTFIELDS]=$body; $headers[]='Content-Type: application/json'; $opts[CURLOPT_HTTPHEADER]=$headers;
    }
    curl_setopt_array($ch,$opts); $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $err=curl_error($ch); curl_close($ch);
    if($body===false) throw new RuntimeException('payment_service_unreachable'.($err?': '.$err:''));
    $json=json_decode($body,true);
    if(!is_array($json)) throw new RuntimeException('payment_service_invalid_response');
    if($status<200||$status>=300||empty($json['ok'])) throw new RuntimeException((string)($json['error']??'payment_service_error'));
    return $json;
}
