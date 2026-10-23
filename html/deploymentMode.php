<?php
// Local host policy, independent of requests and database credentials.
function tarasecDeploymentMode(): string
{
    $path = '/etc/tarasec-deployment-mode';
    if (!file_exists($path)) return 'unconfigured'; // Legacy installs remain usable until enrolled.
    $value = @file_get_contents($path);
    return $value !== false && trim($value) === 'demo' ? 'demo' : 'production';
}
function tarasecRequireDemo(): void
{
    if (tarasecDeploymentMode() === 'production') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'error' => 'Demonstrations are disabled in production mode']);
        exit;
    }
}
