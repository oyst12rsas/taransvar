<?php
require_once __DIR__ . '/../db_connect.php';

function paymentEnsureSchema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS payment (
        paymentId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        provider VARCHAR(32) NOT NULL,
        providerRequestId VARCHAR(96) NOT NULL,
        providerPaymentId VARCHAR(128) NULL,
        planId INT UNSIGNED NOT NULL,
        userId INT UNSIGNED NULL,
        phone VARCHAR(64) NULL,
        email VARCHAR(255) NULL,
        clientIp VARCHAR(45) NULL,
        currency CHAR(3) NOT NULL,
        amountMinor BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        paidTime TIMESTAMP NULL,
        activatedTime TIMESTAMP NULL,
        failureReason VARCHAR(255) NULL,
        providerPayload LONGTEXT NULL,
        PRIMARY KEY(paymentId),
        UNIQUE KEY uq_payment_request(provider, providerRequestId),
        KEY idx_payment_provider_id(provider, providerPaymentId),
        KEY idx_payment_status(status),
        KEY idx_payment_user(userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function paymentConfig(): array
{
    $file = '/etc/tarasec-payment.conf';
    if (!is_readable($file)) {
        return [];
    }
    $cfg = parse_ini_file($file, false, INI_SCANNER_RAW);
    return is_array($cfg) ? $cfg : [];
}

function paymentCfg(array $cfg, string $name, ?string $default = null): ?string
{
    $v = $cfg[$name] ?? getenv($name);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string)$v;
}

function paymentBaseUrl(): string
{
    $cfg = paymentConfig();
    $configured = paymentCfg($cfg, 'PAYMENT_PUBLIC_BASE_URL');
    if ($configured) {
        return rtrim($configured, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function paymentPlan(PDO $conn, int $planId): array
{
    $stmt = $conn->prepare('SELECT * FROM plans WHERE id = ? LIMIT 1');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        throw new RuntimeException('Unknown WiFi plan.');
    }
    return $plan;
}

function paymentMinorAmount(array $plan): int
{
    return (int)round(((float)$plan['price']) * 100);
}

function paymentCurrency(string $provider): string
{
    return $provider === 'gcash' ? 'PHP' : 'KES';
}

function paymentCreate(PDO $conn, string $provider, int $planId, ?int $userId, ?string $phone, ?string $email): array
{
    paymentEnsureSchema($conn);
    $provider = strtolower($provider);
    if (!in_array($provider, ['mpesa', 'gcash'], true)) {
        throw new RuntimeException('Unsupported payment provider.');
    }
    $plan = paymentPlan($conn, $planId);
    $requestId = strtoupper($provider) . '-' . bin2hex(random_bytes(12));
    $currency = paymentCurrency($provider);
    $amountMinor = paymentMinorAmount($plan);
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $conn->prepare('INSERT INTO payment(provider,providerRequestId,planId,userId,phone,email,clientIp,currency,amountMinor,status) VALUES(?,?,?,?,?,?,?,?,?,\'pending\')');
    $stmt->execute([$provider, $requestId, $planId, $userId, $phone, $email, $ip, $currency, $amountMinor]);

    return [
        'paymentId' => (int)$conn->lastInsertId(),
        'provider' => $provider,
        'providerRequestId' => $requestId,
        'plan' => $plan,
        'currency' => $currency,
        'amountMinor' => $amountMinor,
        'phone' => $phone,
        'email' => $email,
    ];
}

function paymentLoad(PDO $conn, int $paymentId): array
{
    paymentEnsureSchema($conn);
    $stmt = $conn->prepare('SELECT * FROM payment WHERE paymentId = ? LIMIT 1');
    $stmt->execute([$paymentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Payment not found.');
    }
    return $row;
}

function paymentRecordProviderResult(PDO $conn, int $paymentId, ?string $providerPaymentId, array $payload): void
{
    $stmt = $conn->prepare('UPDATE payment SET providerPaymentId=COALESCE(?,providerPaymentId), providerPayload=? WHERE paymentId=?');
    $stmt->execute([$providerPaymentId, json_encode($payload, JSON_UNESCAPED_SLASHES), $paymentId]);
}

function paymentMarkFailed(PDO $conn, int $paymentId, string $reason, array $payload = []): void
{
    $stmt = $conn->prepare("UPDATE payment SET status='failed', failureReason=?, providerPayload=? WHERE paymentId=? AND status <> 'paid'");
    $stmt->execute([$reason, json_encode($payload, JSON_UNESCAPED_SLASHES), $paymentId]);
}

function paymentFindByRequest(PDO $conn, string $provider, string $requestId): ?array
{
    paymentEnsureSchema($conn);
    $stmt = $conn->prepare('SELECT * FROM payment WHERE provider=? AND providerRequestId=? LIMIT 1');
    $stmt->execute([$provider, $requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function paymentDataLimitMb(?string $value): ?int
{
    if (!$value) return null;
    $s = trim(strtoupper($value));
    if (str_contains($s, 'UNLIMIT')) return null;
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(GB|G)/', $s, $m)) return (int)round(((float)$m[1]) * 1024);
    if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*(MB|M)/', $s, $m)) return (int)round((float)$m[1]);
    return null;
}

function paymentExpiryForPlan(array $plan): string
{
    $type = strtolower((string)($plan['type'] ?? 'hourly'));
    $seconds = match ($type) {
        'monthly' => 30 * 86400,
        'daily' => 86400,
        default => 3600,
    };
    return date('Y-m-d H:i:s', time() + $seconds);
}

function paymentActivate(PDO $conn, int $paymentId, array $providerPayload = []): void
{
    paymentEnsureSchema($conn);
    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM payment WHERE paymentId=? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payment) throw new RuntimeException('Payment not found.');
        if (!empty($payment['activatedTime'])) {
            $conn->commit();
            return;
        }

        $plan = paymentPlan($conn, (int)$payment['planId']);
        $phone = trim((string)($payment['phone'] ?? ''));
        $email = trim((string)($payment['email'] ?? ''));
        $userId = (int)($payment['userId'] ?? 0);
        $username = '';

        if ($userId > 0) {
            $u = $conn->prepare('SELECT id,username,email,phone FROM radcheck WHERE id=? LIMIT 1');
            $u->execute([$userId]);
            $user = $u->fetch(PDO::FETCH_ASSOC);
            if ($user) $username = (string)$user['username'];
        }

        if ($username === '' && $phone !== '') {
            $u = $conn->prepare('SELECT id,username FROM radcheck WHERE phone=? LIMIT 1');
            $u->execute([$phone]);
            if ($user = $u->fetch(PDO::FETCH_ASSOC)) {
                $userId = (int)$user['id'];
                $username = (string)$user['username'];
            } else {
                $username = $phone;
                $u = $conn->prepare("INSERT INTO radcheck(username,phone,attribute,op,value) VALUES(?,?, 'Cleartext-Password', ':=', '')");
                $u->execute([$username, $phone]);
                $userId = (int)$conn->lastInsertId();
            }
        }

        if ($username === '' && $email !== '') {
            $u = $conn->prepare('SELECT id,username FROM radcheck WHERE email=? LIMIT 1');
            $u->execute([$email]);
            if ($user = $u->fetch(PDO::FETCH_ASSOC)) {
                $userId = (int)$user['id'];
                $username = (string)$user['username'];
            }
        }

        if ($username === '') throw new RuntimeException('Payment has no usable hotspot identity.');

        $expiry = paymentExpiryForPlan($plan);
        $mbquota = paymentDataLimitMb($plan['data_limit'] ?? null);
        $subType = $mbquota === null ? 'expiry' : 'limited';
        $u = $conn->prepare('UPDATE radcheck SET subscriptionType=?, expirytime=?, mbquota=COALESCE(?,mbquota), mbusage=0 WHERE id=?');
        $u->execute([$subType, $expiry, $mbquota, $userId]);

        $clientIp = (string)($payment['clientIp'] ?? '');
        if ($clientIp !== '') {
            $s = $conn->prepare('INSERT INTO session(id,ip,username,active,lastrequest) VALUES(?,?,?,1,NOW())');
            $s->execute([$userId, $clientIp, $username]);
        }

        $stmt = $conn->prepare("UPDATE payment SET status='paid',paidTime=COALESCE(paidTime,NOW()),activatedTime=NOW(),userId=?,providerPayload=? WHERE paymentId=?");
        $stmt->execute([$userId, json_encode($providerPayload, JSON_UNESCAPED_SLASHES), $paymentId]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

function paymentJson(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
