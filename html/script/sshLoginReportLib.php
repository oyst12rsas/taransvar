<?php
function managerSshLoginReport(): array
{
    // Recheck the switch on each request so disabling immediately hides cached events.
    $config = @file_get_contents('/etc/tarasec/ssh-login-report.conf');
    if ($config !== false && preg_match('/^\\s*SSH_LOGIN_REPORT_ENABLED\\s*=\\s*["\x27]?(no|false|0|off)["\x27]?\\s*$/mi', $config)) {
        return ['status'=>'disabled', 'summary'=>'report disabled'];
    }
    $raw = @file_get_contents('/run/tarasec-ssh-logins.json');
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) return ['status'=>'unavailable', 'summary'=>'SSH activity not installed or unavailable'];
    if (time() - (int)($data['checked_at'] ?? 0) > 180) {
        return ['status'=>'stale', 'summary'=>'SSH activity report is stale'];
    }
    return $data;
}
