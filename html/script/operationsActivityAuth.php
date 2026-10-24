<?php
declare(strict_types=1);
/** Verify only a dedicated read-only activity credential, never the node's admin token. */
function operationsActivityRemoteAuth(string $token, string $ip): bool {
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)
        || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        || (ip2long($ip) & 0xffc00000) !== ip2long('100.64.0.0')) return false;
    $curl = curl_init('https://tarasec.org/ops/agent/activity.php?action=verify');
    curl_setopt_array($curl, [CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode(['source_ip'=>$ip]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$token],
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_PROXY=>'', CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>3,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $raw = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($status !== 200 || !is_string($raw) || strlen($raw)>8192) return false;
    $body = json_decode($raw,true);
    return is_array($body) && ($body['ok'] ?? false) === true
        && ($body['scope'] ?? '') === 'operations_activity' && ($body['source_ip'] ?? '') === $ip;
}
