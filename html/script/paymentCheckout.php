<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/paymentConfig.php';

function failPayment(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failPayment(405, 'POST required');
}

$nonce = trim((string)($_POST['paymentMethodNonce'] ?? ''));
$amountRaw = trim((string)($_POST['amount'] ?? ''));
$currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
$hotspotId = trim((string)($_POST['hotspotId'] ?? ''));
$research = ((string)($_POST['researchDiscount'] ?? '0')) === '1';

if ($nonce === '') failPayment(400, 'Missing payment method nonce');
if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $amountRaw)) failPayment(400, 'Invalid amount');
$amount = (float)$amountRaw;
if ($amount <= 0.0 || $amount > 100000.0) failPayment(400, 'Amount outside permitted range');
if (!preg_match('/^[A-Z]{3}$/', $currency)) failPayment(400, 'Invalid currency');
if ($hotspotId !== '' && !preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $hotspotId)) failPayment(400, 'Invalid hotspot ID');

$orderId = 'TS-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));

try {
    $config = taraPaymentConfig();
    $gateway = taraPaymentGateway($config);
    $merchantAccountId = taraPaymentMerchantAccountId($currency, $config);

    $sale = [
        'amount' => number_format($amount, 2, '.', ''),
        'paymentMethodNonce' => $nonce,
        'orderId' => $orderId,
        'options' => ['submitForSettlement' => true],
    ];
    if ($merchantAccountId !== null) {
        $sale['merchantAccountId'] = $merchantAccountId;
    }

    $result = $gateway->transaction()->sale($sale);

    if (!$result->success) {
        $message = 'Payment was not approved.';
        if (isset($result->message) && $result->message) $message = (string)$result->message;
        failPayment(402, $message);
    }

    $transaction = $result->transaction;
    echo json_encode([
        'ok' => true,
        'provider' => 'braintree',
        'environment' => $config['environment'],
        'orderId' => $orderId,
        'transactionId' => $transaction->id ?? '',
        'status' => $transaction->status ?? '',
        'amount' => number_format($amount, 2, '.', ''),
        'currency' => $currency,
        'hotspotId' => $hotspotId,
        'researchDiscount' => $research,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
