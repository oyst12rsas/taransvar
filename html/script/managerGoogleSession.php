<?php
declare(strict_types=1);
require_once __DIR__.'/managerSshApprovalTarget.php';

function managerGoogleSessionActive(): bool {
    if (empty($_SESSION['tarasec_manager_authenticated'])
        || (int)($_SESSION['manager_google_until'] ?? 0)<=time()
        || (string)($_SESSION['manager_google_email'] ?? '')!==(string)($_SESSION['tarasec_manager_email'] ?? ''))
        return false;
    try { $target=managerSshApprovalTargetRead(); }
    catch (Throwable $e) { return false; }
    return $target!==null && hash_equals($target['server_ref'],(string)($_SESSION['manager_google_ref'] ?? ''))
        && hash_equals($target['approval_url'],(string)($_SESSION['manager_google_origin'] ?? ''));
}

function managerGoogleRedeem(array $target,string $token,string $binding,string $email,
    string $configPath='/etc/tarasec/gatekeeper-google.php'): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$token) || !preg_match('/^[a-f0-9]{64}$/D',$binding))
        throw new RuntimeException('Fresh Google proof required');
    $info=@lstat($configPath);
    if (!$info || is_link($configPath) || ($info['mode'] & 0170000)!==0100000 || $info['uid']!==0
        || ($info['mode'] & 0027)!==0) throw new RuntimeException('Google management handoff unavailable');
    $cfg=require $configPath;
    if (!is_array($cfg) || ($cfg['agent_api'] ?? '')!==$target['approval_url'].'api.php'
        || !preg_match('/^[a-f0-9]{64}$/D',(string)($cfg['shared_secret'] ?? ''))
        || !is_string($cfg['client_id'] ?? null)) throw new RuntimeException('Google management handoff unavailable');
    $ch=curl_init($target['approval_url'].'mobile.php?action=consume_management');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$cfg['shared_secret'],'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['client_id'=>$cfg['client_id'],'token'=>$token,'binding'=>$binding,'email'=>$email])]);
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    $proof=is_string($raw)?json_decode($raw,true):null;
    if ($http!==200 || !is_array($proof) || ($proof['ok'] ?? false)!==true
        || ($proof['email'] ?? '')!==strtolower($email) || empty($proof['sub'])
        || !hash_equals($target['server_ref'],(string)($proof['server_ref'] ?? ''))
        || !is_int($proof['expires_at'] ?? null) || $proof['expires_at']<=time()
        || $proof['expires_at']>time()+960) throw new RuntimeException('Fresh Google proof could not be verified');
    return $proof;
}
