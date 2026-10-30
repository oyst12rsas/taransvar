<?php
require_once __DIR__.'/../html/script/modelProviderError.php';
function requireProvider($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$cases = [
 [500,'{"message":"429 You have no credits remaining. SECRET"}','provider_quota_exhausted'],
 [429,'{"error":{"code":"insufficient_quota"}}','provider_quota_exhausted'],
 [429,'{"error":{"code":"rate_limit_exceeded"}}','rate_limited'],
 [500,'Incorrect API key provided: SECRET','provider_credential_rejected'],
 [401,'SECRET','authentication_failed'],
 [500,'SECRET arbitrary provider response','model_http_error'],
 [500,str_repeat('x',16384).'no credits remaining SECRET','model_http_error'],
];
foreach ($cases as [$status,$body,$code]) {
 $result=taraSecModelProviderFailure($status,$body);
 requireProvider($result['error']===$code,'Wrong sanitized provider classification');
 requireProvider($result['upstream_http_status']===$status,'Keep actual outer HTTP status');
 requireProvider(!str_contains(json_encode($result),'SECRET'),'Raw provider content leaked');
}
echo "Sanitized model-provider propagation checks passed.\n";
