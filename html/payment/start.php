<?php
session_start();
require_once __DIR__ . '/payment_lib.php';
require_once __DIR__ . '/providers/MpesaProvider.php';
require_once __DIR__ . '/providers/GcashProvider.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    paymentJson(405, ['ok' => false, 'error' => 'POST required']);
}

try {
    $provider = strtolower(trim((string)($_POST['provider'] ?? '')));
    $planId = (int)($_POST['plan_id'] ?? 0);
    $phone = trim((string)($_POST['phone'] ?? ($_SESSION['phone'] ?? '')));
    $email = trim((string)($_POST['email'] ?? ($_SESSION['email'] ?? '')));
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    if ($planId <= 0) throw new RuntimeException('Choose a WiFi plan.');
    if (!$userId && $phone === '' && $email === '') throw new RuntimeException('Phone number or account login is required.');
    if ($provider === 'mpesa' && $phone === '') throw new RuntimeException('M-Pesa requires a Kenyan phone number.');

    $payment = paymentCreate($conn, $provider, $planId, $userId, $phone ?: null, $email ?: null);
    $payment['paymentId'] = (int)$payment['paymentId'];

    $client = match ($provider) {
        'mpesa' => new MpesaProvider(),
        'gcash' => new GcashProvider(),
        default => throw new RuntimeException('Unsupported payment provider.'),
    };

    try {
        $result = $client->start($payment);
        paymentRecordProviderResult($conn, $payment['paymentId'], $result['providerPaymentId'] ?? null, $result['response'] ?? []);
    } catch (Throwable $providerError) {
        paymentMarkFailed($conn, $payment['paymentId'], $providerError->getMessage());
        throw $providerError;
    }

    if (!empty($result['redirectUrl'])) {
        header('Location: ' . $result['redirectUrl'], true, 303);
        exit;
    }

    header('Location: ' . paymentBaseUrl() . '/payment/return.php?id=' . $payment['paymentId'], true, 303);
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Payment could not be started</h2><p>' . htmlspecialchars($e->getMessage()) . '</p><p><a href="/payment/">Back to plans</a></p>';
}
