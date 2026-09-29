<?php
declare(strict_types=1);
session_start();
header('Cache-Control: no-store');
function gatekeeperGoogleFail(string $message): never {
    http_response_code(403);
    exit(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
}
$state = (string)($_GET['state'] ?? '');
$code = (string)($_GET['code'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/D', $state) || !preg_match('/^[a-f0-9]{64}$/D', $code)
    || !hash_equals((string)($_SESSION['google_admin_state'] ?? ''), $state)
    || (int)($_SESSION['google_admin_until'] ?? 0) < time())
    gatekeeperGoogleFail('Sign-in request expired. Please try again.');
unset($_SESSION['google_admin_state'], $_SESSION['google_admin_until']);
$cfgPath = '/etc/tarasec/gatekeeper-google.php';
$cfg = is_readable($cfgPath) ? require $cfgPath : null;
if (!is_array($cfg) || !preg_match('/^[a-f0-9]{64}$/D', (string)($cfg['shared_secret'] ?? ''))
    || ($cfg['agent_api'] ?? '') !== 'https://tarasec.org/ops/agent/api.php')
    gatekeeperGoogleFail('Google sign-in is not configured.');
$curl = curl_init($cfg['agent_api'].'?action=gatekeeper_redeem');
curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_HTTPHEADER=>[
    'Authorization: Bearer '.$cfg['shared_secret'], 'Content-Type: application/json'
], CURLOPT_POSTFIELDS=>json_encode(['id'=>$cfg['client_id'],'state'=>$state,'code'=>$code]),
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_FOLLOWLOCATION=>false]);
$response = curl_exec($curl);
$http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);
$identity = is_string($response) ? json_decode($response, true) : null;
if ($http !== 200 || !is_array($identity) || ($identity['ok'] ?? false) !== true
    || !filter_var($identity['email'] ?? null, FILTER_VALIDATE_EMAIL))
    gatekeeperGoogleFail('Google sign-in could not be verified.');
require __DIR__.'/dbfunc.php';
$conn = getConnection();
$email = strtolower((string)$identity['email']);
$stmt = $conn->prepare('SELECT userId FROM user WHERE LOWER(username) = ? AND CAST(isAdmin AS UNSIGNED) = 1 LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
if (!$user) gatekeeperGoogleFail('This Google account is not an existing Gatekeeper administrator.');
session_regenerate_id(true);
$_SESSION['userid'] = (int)$user['userId'];
$_SESSION['isAdmin'] = 1;
$_SESSION['hold'] = 0;
header('Location: index.php?f=main', true, 303);
