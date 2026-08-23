<?php
require_once __DIR__ . '/payment_lib.php';
try {
    $payment = paymentLoad($conn, (int)($_GET['id'] ?? 0));
    paymentJson(200, [
        'ok' => true,
        'status' => $payment['status'],
        'provider' => $payment['provider'],
        'reference' => $payment['providerRequestId'],
        'paidTime' => $payment['paidTime'],
        'activatedTime' => $payment['activatedTime'],
    ]);
} catch (Throwable $e) {
    paymentJson(404, ['ok' => false, 'error' => 'Payment not found']);
}
