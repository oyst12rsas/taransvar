<?php
require_once __DIR__ . '/../payment_lib.php';
require_once __DIR__ . '/../providers/GcashProvider.php';

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) paymentJson(400, ['result' => ['resultCode' => 'INVALID_REQUEST', 'resultStatus' => 'F', 'resultMessage' => 'Invalid JSON']]);

try {
    $provider = new GcashProvider();
    if (!$provider->verifyNotification($raw, $_SERVER)) {
        throw new RuntimeException('Invalid GCash signature.');
    }

    $requestId = trim((string)($payload['paymentRequestId'] ?? ''));
    if ($requestId === '') throw new RuntimeException('Missing paymentRequestId.');
    $payment = paymentFindByRequest($conn, 'gcash', $requestId);
    if (!$payment) throw new RuntimeException('Unknown paymentRequestId.');

    $status = strtoupper((string)($payload['paymentStatus'] ?? ''));
    $amount = $payload['paymentAmount'] ?? [];
    if (($amount['currency'] ?? '') !== $payment['currency'] || (string)($amount['value'] ?? '') !== (string)$payment['amountMinor']) {
        throw new RuntimeException('GCash amount/currency mismatch.');
    }

    if ($status === 'SUCCESS') {
        if (!empty($payload['paymentId'])) {
            paymentRecordProviderResult($conn, (int)$payment['paymentId'], (string)$payload['paymentId'], $payload);
        }
        paymentActivate($conn, (int)$payment['paymentId'], $payload);
    } elseif ($status === 'FAIL') {
        paymentMarkFailed($conn, (int)$payment['paymentId'], (string)($payload['paymentFailReason'] ?? 'GCash payment failed'), $payload);
    }

    paymentJson(200, ['result' => ['resultCode' => 'SUCCESS', 'resultStatus' => 'S', 'resultMessage' => 'success']]);
} catch (Throwable $e) {
    error_log('GCash callback error: ' . $e->getMessage());
    paymentJson(400, ['result' => ['resultCode' => 'INVALID_REQUEST', 'resultStatus' => 'F', 'resultMessage' => 'rejected']]);
}
