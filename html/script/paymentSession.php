<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/paymentConfig.php';

try {
    $config = taraPaymentConfig();
    if (!taraPaymentConfigured($config)) {
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'configured' => false,
            'provider' => 'braintree',
            'environment' => $config['environment'],
            'methods' => ['paypal', 'google_pay', 'apple_pay_web'],
            'error' => 'Payment sandbox is not configured yet.'
        ]);
        exit;
    }

    $gateway = taraPaymentGateway($config);
    $token = $gateway->clientToken()->generate();

    echo json_encode([
        'ok' => true,
        'configured' => true,
        'provider' => 'braintree',
        'environment' => $config['environment'],
        'methods' => ['paypal', 'google_pay', 'apple_pay_web'],
        'clientToken' => $token
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'configured' => false,
        'provider' => 'braintree',
        'error' => $e->getMessage()
    ]);
}
