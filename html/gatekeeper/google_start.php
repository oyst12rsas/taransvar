<?php
declare(strict_types=1);
session_start();
header('Cache-Control: no-store');
$cfgPath = '/etc/tarasec/gatekeeper-google.php';
$cfg = is_readable($cfgPath) ? require $cfgPath : null;
if (!is_array($cfg) || !preg_match('/^[a-z0-9_-]{2,32}$/D', (string)($cfg['client_id'] ?? ''))
    || !preg_match('/^https:\/\/tarasec\.org\/ops\/agent\/gatekeeper\.php$/D', (string)($cfg['sign_in_url'] ?? ''))) {
    http_response_code(503); exit('Google sign-in is not configured on Gatekeeper.');
}
$_SESSION['google_admin_state'] = bin2hex(random_bytes(24));
$_SESSION['google_admin_until'] = time() + 300;
header('Location: '.$cfg['sign_in_url'].'?'.http_build_query([
    'id'=>$cfg['client_id'], 'state'=>$_SESSION['google_admin_state']
]), true, 303);
