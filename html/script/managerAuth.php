<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const TARASEC_MANAGER_KEYS = '/etc/tarasec-manager-keys.conf';

function managerReply(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function startManagerSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/*
 * Configuration format, one manager key per line:
 *
 *   # comments are allowed
 *   owner-name:0123456789abcdef...
 *   0123456789abcdef...              # label defaults to "manager"
 *
 * The key itself is never returned by this endpoint.  The file should be
 * readable by the web server but not publicly served by Apache.
 */
function allowedManagerKeys(): array
{
    $path = TARASEC_MANAGER_KEYS;
    if (!is_readable($path)) {
        throw new RuntimeException('Manager key configuration is unavailable');
    }

    $keys = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $label = 'manager';
        $key = $line;
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $label = trim(substr($line, 0, $colon));
            $key = trim(substr($line, $colon + 1));
        }

        if ($key !== '') {
            $keys[] = [
                'label' => $label !== '' ? $label : 'manager',
                'key' => $key,
            ];
        }
    }
    return $keys;
}

function currentManager(): ?array
{
    startManagerSession();
    if (empty($_SESSION['tarasec_manager_authenticated'])) {
        return null;
    }

    return [
        'label' => (string)($_SESSION['tarasec_manager_label'] ?? 'manager'),
        'authenticated_at' => (string)($_SESSION['tarasec_manager_authenticated_at'] ?? ''),
    ];
}

$action = strtolower(trim((string)($_REQUEST['action'] ?? 'status')));

if ($action === 'status') {
    $manager = currentManager();
    managerReply(200, [
        'ok' => true,
        'authenticated' => $manager !== null,
        'manager' => $manager,
    ]);
}

if ($action === 'logout') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        managerReply(405, ['ok' => false, 'error' => 'logout_requires_post']);
    }
    startManagerSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    managerReply(200, ['ok' => true, 'authenticated' => false]);
}

if ($action !== 'login') {
    managerReply(400, ['ok' => false, 'error' => 'invalid_action']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    managerReply(405, ['ok' => false, 'error' => 'login_requires_post']);
}

$key = trim((string)($_POST['key'] ?? ''));
if ($key === '') {
    managerReply(400, ['ok' => false, 'error' => 'missing_key']);
}

try {
    $matchedLabel = null;
    foreach (allowedManagerKeys() as $entry) {
        if (hash_equals((string)$entry['key'], $key)) {
            $matchedLabel = (string)$entry['label'];
            break;
        }
    }

    if ($matchedLabel === null) {
        // Do not reveal whether the config exists or how many keys are valid.
        managerReply(401, ['ok' => false, 'error' => 'invalid_manager_key']);
    }

    startManagerSession();
    session_regenerate_id(true);
    $_SESSION['tarasec_manager_authenticated'] = 1;
    $_SESSION['tarasec_manager_label'] = $matchedLabel;
    $_SESSION['tarasec_manager_authenticated_at'] = gmdate('c');

    managerReply(200, [
        'ok' => true,
        'authenticated' => true,
        'manager' => [
            'label' => $matchedLabel,
            'authenticated_at' => $_SESSION['tarasec_manager_authenticated_at'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('managerAuth.php: ' . $e->getMessage());
    managerReply(503, ['ok' => false, 'error' => 'manager_auth_unavailable']);
}
