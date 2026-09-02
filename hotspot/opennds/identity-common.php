<?php
declare(strict_types=1);

require_once __DIR__ . '/subscriber-api-common.php';

function identity_config(string $provider): array {
    $provider=strtolower($provider);
    $callback=getenv('TARASEC_IDENTITY_CALLBACK') ?: 'https://tarasec.org/hotspot/opennds/identity-callback.php';
    if ($provider === 'google') {
        return [
            'provider'=>'google',
            'client_id'=>(string)getenv('TARASEC_GOOGLE_CLIENT_ID'),
            'client_secret'=>(string)getenv('TARASEC_GOOGLE_CLIENT_SECRET'),
            'authorize'=>'https://accounts.google.com/o/oauth2/v2/auth',
            'token'=>'https://oauth2.googleapis.com/token',
            'callback'=>$callback
        ];
    }
    if ($provider === 'facebook') {
        return [
            'provider'=>'facebook',
            'client_id'=>(string)getenv('TARASEC_FACEBOOK_CLIENT_ID'),
            'client_secret'=>(string)getenv('TARASEC_FACEBOOK_CLIENT_SECRET'),
            'authorize'=>'https://www.facebook.com/v23.0/dialog/oauth',
            'token'=>'https://graph.facebook.com/v23.0/oauth/access_token',
            'callback'=>$callback
        ];
    }
    identity_error(400,'unsupported_identity_provider');
}

function identity_error(int $status,string $reason): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $safe=htmlspecialchars(str_replace('_',' ',$reason),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TaraSec sign-in</title></head><body><h1>TaraSec sign-in unavailable</h1><p>'.$safe.'</p></body></html>';
    exit;
}

function identity_http(string $url,string $method='GET',array $fields=[]): array {
    if (!function_exists('curl_init')) identity_error(500,'php_curl_not_installed');
    $ch=curl_init();
    if ($method === 'GET' && $fields) $url.=(str_contains($url,'?')?'&':'?').http_build_query($fields);
    curl_setopt_array($ch,[
        CURLOPT_URL=>$url,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Accept: application/json']
    ]);
    if ($method === 'POST') {
        curl_setopt($ch,CURLOPT_POST,true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($fields));
        curl_setopt($ch,CURLOPT_HTTPHEADER,['Accept: application/json','Content-Type: application/x-www-form-urlencoded']);
    }
    $body=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        error_log("identity provider HTTP failure: $status $error");
        identity_error(502,'identity_provider_unavailable');
    }
    $json=json_decode((string)$body,true);
    if (!is_array($json)) identity_error(502,'identity_provider_invalid_reply');
    return $json;
}

function identity_redirect_uri(string $value): string {
    $allowed=array_filter(array_map('trim',explode(',',getenv('TARASEC_IDENTITY_APP_REDIRECTS') ?: 'tarasec://identity')));
    if (!in_array($value,$allowed,true)) identity_error(400,'invalid_app_redirect');
    return $value;
}

function identity_random(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
}
