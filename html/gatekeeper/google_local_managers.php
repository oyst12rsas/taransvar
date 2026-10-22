<?php
declare(strict_types=1);
// This separate, read-only web projection is written only by root enrollment.
// Never expose the agent token or the root-only SSH policy to the web process.
function gatekeeperLocalManager(string $email, string $path = '/etc/tarasec/gatekeeper-managers.json'): bool {
    if (is_link($path)) return false;
    $info = @stat($path);
    if (!$info || $info['uid'] !== 0 || ($info['mode'] & 0022) !== 0 || $info['size'] > 16384) return false;
    $hashes = json_decode((string)@file_get_contents($path), true);
    if (!is_array($hashes) || !array_is_list($hashes)) return false;
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[^\x20-\x7e]/', $email)) return false;
    foreach ($hashes as $hash) {
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) return false;
    }
    return in_array(hash('sha256', $email), $hashes, true);
}
