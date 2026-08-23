<?php
require_once __DIR__ . '/../payment_lib.php';
require_once __DIR__ . '/../providers/MpesaProvider.php';

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) paymentJson(400, ['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);

try {
    paymentEnsureSchema($conn);
    $cb = $payload['Body']['stkCallback'] ?? null;
    if (!is_array($cb)) throw new RuntimeException('Missing stkCallback.');
    $checkoutId = trim((string)($cb['CheckoutRequestID'] ?? ''));
    if ($checkoutId === '') throw new RuntimeException('Missing CheckoutRequestID.');

    $stmt = $conn->prepare("SELECT * FROM payment WHERE provider='mpesa' AND (providerPaymentId=? OR providerPaymentId LIKE CONCAT(?,':%')) LIMIT 1");
    $stmt->execute([$checkoutId, $checkoutId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) throw new RuntimeException('Unknown CheckoutRequestID.');

    $resultCode = (int)($cb['ResultCode'] ?? -1);
    if ($resultCode !== 0) {
        paymentMarkFailed($conn, (int)$payment['paymentId'], (string)($cb['ResultDesc'] ?? 'M-Pesa payment failed'), $payload);
        paymentJson(200, ['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    // The callback is additionally cross-checked with Daraja before granting access.
    $query = (new MpesaProvider())->verifyCheckout($checkoutId);
    if ((int)($query['ResultCode'] ?? -1) !== 0) {
        throw new RuntimeException('Daraja checkout query did not confirm successful payment.');
    }

    $meta = [];
    foreach (($cb['CallbackMetadata']['Item'] ?? []) as $item) {
        if (isset($item['Name'])) $meta[$item['Name']] = $item['Value'] ?? null;
    }
    $paidKes = isset($meta['Amount']) ? (float)$meta['Amount'] : null;
    $expectedKes = ((int)$payment['amountMinor']) / 100;
    if ($paidKes === null || abs($paidKes - $expectedKes) > 0.01) {
        throw new RuntimeException('M-Pesa amount does not match the TaraSec payment.');
    }

    if (!empty($meta['MpesaReceiptNumber'])) {
        $stmt = $conn->prepare('UPDATE payment SET providerPaymentId=CONCAT(?,\':\',?) WHERE paymentId=?');
        $stmt->execute([$checkoutId, (string)$meta['MpesaReceiptNumber'], (int)$payment['paymentId']]);
    }

    $payload['darajaQuery'] = $query;
    paymentActivate($conn, (int)$payment['paymentId'], $payload);
    paymentJson(200, ['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
} catch (Throwable $e) {
    error_log('M-Pesa callback error: ' . $e->getMessage());
    paymentJson(400, ['ResultCode' => 1, 'ResultDesc' => 'Rejected']);
}
