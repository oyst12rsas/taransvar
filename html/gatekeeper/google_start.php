<?php
declare(strict_types=1);
require_once __DIR__.'/../script/serviceDiscoveryCommon.php';
session_start();
header('Cache-Control: no-store');
$cfgPath = '/etc/tarasec/gatekeeper-google.php';
$cfg = is_readable($cfgPath) ? require $cfgPath : null;
if (!is_array($cfg) || !preg_match('/^[a-z0-9_-]{2,32}$/D', (string)($cfg['client_id'] ?? ''))
    || !preg_match('/^[a-f0-9]{64}$/D', (string)($cfg['shared_secret'] ?? ''))) {
    http_response_code(503); exit('Google sign-in is not configured on Gatekeeper.');
}
try { $provider = taraAdminServices($cfg); }
catch (Throwable $e) { http_response_code(503); exit('Administrator identity service is not configured.'); }
if (($cfg['agent_api'] ?? '') !== $provider['agent_api']) { http_response_code(503); exit('Register a separate gateway credential with the selected administrator service.'); }
$_SESSION['google_admin_service'] = $provider;
$_SESSION['google_admin_state'] = bin2hex(random_bytes(24));
$_SESSION['google_admin_until'] = time() + 300;
header('Location: '.$provider['sign_in_url'].'?'.http_build_query([
    'id'=>$cfg['client_id'], 'state'=>$_SESSION['google_admin_state']
]), true, 303);
