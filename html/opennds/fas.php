<?php
session_start();
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../auth_tara.php';

function tara_opennds_key(): string {
    $path = '/etc/tarasec-opennds-fas.key';
    if (!is_readable($path)) {
        throw new RuntimeException('openNDS FAS key is not readable');
    }
    $key = trim((string)file_get_contents($path));
    if ($key === '') {
        throw new RuntimeException('openNDS FAS key is empty');
    }
    return $key;
}

function tara_decode_fas(string $encoded): array {
    $encoded = strtr($encoded, '-_', '+/');
    $pad = strlen($encoded) % 4;
    if ($pad) {
        $encoded .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($encoded, true);
    if ($decoded === false) {
        return [];
    }

    $out = [];
    foreach (preg_split('/,\s*/', $decoded) as $part) {
        $pos = strpos($part, '=');
        if ($pos === false) {
            continue;
        }
        $name = trim(substr($part, 0, $pos));
        $value = trim(substr($part, $pos + 1));
        if ($name !== '') {
            $out[$name] = $value;
        }
    }
    return $out;
}

function tara_auth_url(array $fas, string $token): string {
    $gateway = $fas['gatewayaddress'] ?? '';
    $authdir = trim($fas['authdir'] ?? 'opennds_auth', '/');

    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $gateway)) {
        throw new RuntimeException('Invalid gateway address from openNDS');
    }
    if (!preg_match('/^[A-Za-z0-9_\-\/]+$/', $authdir)) {
        throw new RuntimeException('Invalid auth directory from openNDS');
    }

    $redir = $fas['originurl'] ?? '/';
    return 'http://' . $gateway . '/' . $authdir . '/?tok=' . rawurlencode($token)
        . '&redir=' . rawurlencode($redir) . '&custom=tarasec';
}

$encodedFas = (string)($_POST['fas'] ?? $_GET['fas'] ?? '');
$fas = tara_decode_fas($encodedFas);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $result = verifyAccountLogin($email, $password, false);

    if (!empty($result['status'])) {
        try {
            $hid = $fas['hid'] ?? $fas['client_hid'] ?? '';
            if ($hid === '') {
                throw new RuntimeException('Missing client hash from openNDS');
            }
            $token = hash('sha256', $hid . tara_opennds_key());
            $authUrl = tara_auth_url($fas, $token);
            header('Location: ' . $authUrl, true, 302);
            exit;
        } catch (Throwable $e) {
            error_log('TaraSec openNDS FAS: ' . $e->getMessage());
            $error = 'Hotspot authentication succeeded, but the gateway could not be notified.';
        }
    } else {
        $error = (string)($result['message'] ?? 'Login failed.');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>TaraSec WiFi Login</title>
<style>
body{font-family:system-ui,sans-serif;background:#f4f6f8;margin:0;padding:2rem;color:#17202a}.card{max-width:430px;margin:5vh auto;background:white;padding:2rem;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.1)}h1{margin-top:0}label{display:block;margin-top:1rem;font-weight:600}input{box-sizing:border-box;width:100%;padding:.8rem;margin-top:.35rem;border:1px solid #bbb;border-radius:8px}button{width:100%;margin-top:1.4rem;padding:.9rem;border:0;border-radius:8px;background:#1468a8;color:white;font-weight:700}.error{background:#ffe8e8;padding:.8rem;border-radius:8px}.small{font-size:.9rem;color:#65727e;margin-top:1rem}
</style>
</head>
<body>
<div class="card">
<h1>TaraSec WiFi</h1>
<p>Sign in to continue to the Internet.</p>
<?php if ($error !== ''): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
<input type="hidden" name="fas" value="<?= htmlspecialchars($encodedFas, ENT_QUOTES, 'UTF-8') ?>">
<label>Email<input type="email" name="email" required autocomplete="username"></label>
<label>Password<input type="password" name="password" required autocomplete="current-password"></label>
<button type="submit">Connect</button>
</form>
<p class="small"><a href="/">Hotspot plans and account options</a></p>
</div>
</body>
</html>
