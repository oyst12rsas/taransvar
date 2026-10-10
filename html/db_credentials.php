<?php
// Credentials are local configuration outside the web root; no built-in fallback.
function tarasecDbPassword(): string {
    $path = getenv('TARASEC_DB_APP_PASSWORD_FILE') ?: '/etc/tarasec/db-app.password';
    $raw = @file_get_contents($path, false, null, 0, 128);
    $value = is_string($raw) ? trim($raw) : '';
    if (!preg_match('/^[a-f0-9]{64}$/D', $value)) {
        throw new RuntimeException('Local database credentials unavailable; run the updated installer');
    }
    return $value;
}
