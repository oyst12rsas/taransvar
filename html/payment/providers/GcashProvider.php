<?php
require_once __DIR__ . '/../payment_lib.php';

final class GcashProvider
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = paymentConfig();
    }

    private function required(string $key): string
    {
        $v = paymentCfg($this->cfg, $key);
        if (!$v) throw new RuntimeException("Missing payment configuration: $key");
        return $v;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        return base64_decode($value, true) ?: '';
    }

    private function privateKey()
    {
        $path = $this->required('GCASH_PRIVATE_KEY_FILE');
        $pem = @file_get_contents($path);
        if ($pem === false) throw new RuntimeException('Cannot read GCash private key file.');
        $key = openssl_pkey_get_private($pem);
        if (!$key) throw new RuntimeException('Invalid GCash private key.');
        return $key;
    }

    private function requestTime(): string
    {
        $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        return $dt->format('Y-m-d\TH:i:s.vP');
    }

    private function sign(string $method, string $uri, string $clientId, string $requestTime, string $body): string
    {
        $content = strtoupper($method) . ' ' . $uri . "\n" . $clientId . '.' . $requestTime . '.' . $body;
        $signature = '';
        if (!openssl_sign($content, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign GCash request.');
        }
        return self::base64UrlEncode($signature);
    }

    public function start(array $payment): array
    {
        $base = rtrim($this->required('GCASH_API_BASE'), '/');
        $clientId = $this->required('GCASH_CLIENT_ID');
        $partnerId = $this->required('GCASH_PARTNER_ID');
        $appId = $this->required('GCASH_APP_ID');
        $productCode = $this->required('GCASH_PRODUCT_CODE');
        $uri = '/api/v1/payments/pay';
        $returnUrl = paymentBaseUrl() . '/payment/return.php?id=' . (int)$payment['paymentId'];
        $notifyUrl = paymentBaseUrl() . '/payment/callback/gcash.php';

        $payload = [
            'partnerId' => $partnerId,
            'appId' => $appId,
            'paymentRequestId' => (string)$payment['providerRequestId'],
            'paymentOrderTitle' => 'TaraSec WiFi plan',
            'productCode' => $productCode,
            'paymentAmount' => [
                'currency' => 'PHP',
                'value' => (string)$payment['amountMinor'],
            ],
            'paymentFactor' => ['isCashierPayment' => true],
            'paymentReturnUrl' => $returnUrl,
            'paymentNotifyUrl' => $notifyUrl,
            'extraParams' => [
                'ORDER' => json_encode([
                    'referenceOrderId' => (string)$payment['providerRequestId'],
                    'orderAmount' => json_encode(['currency' => 'PHP', 'value' => (string)$payment['amountMinor']]),
                ], JSON_UNESCAPED_SLASHES),
            ],
            'extendInfo' => json_encode(['customerBelongsTo' => paymentCfg($this->cfg, 'GCASH_SITE_NAME', 'GCash')], JSON_UNESCAPED_SLASHES),
            'envInfo' => ['terminalType' => 'WEB'],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $time = $this->requestTime();
        $signature = $this->sign('POST', $uri, $clientId, $time, $body);
        $headers = [
            'Content-Type: application/json; charset=UTF-8',
            'Accept: application/json',
            'Client-Id: ' . $clientId,
            'Request-Time: ' . $time,
            'Signature: algorithm=RSA256, keyVersion=' . paymentCfg($this->cfg, 'GCASH_KEY_VERSION', '1') . ', signature=' . $signature,
        ];

        $ch = curl_init($base . $uri);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('GCash HTTP error: ' . $err);
        }
        curl_close($ch);
        $response = json_decode($raw, true);
        if (!is_array($response)) throw new RuntimeException('Invalid GCash response.');
        if ($status < 200 || $status >= 300) throw new RuntimeException('GCash returned HTTP ' . $status . '.');

        $resultStatus = $response['result']['resultStatus'] ?? '';
        if (!in_array($resultStatus, ['A', 'S'], true)) {
            throw new RuntimeException('GCash rejected payment: ' . ($response['result']['resultMessage'] ?? 'unknown error'));
        }
        $providerId = $response['paymentId'] ?? null;
        $redirect = $response['actionForm']['redirectionUrl'] ?? null;
        if (!$redirect) throw new RuntimeException('GCash did not return a cashier redirection URL.');
        return ['providerPaymentId' => $providerId, 'response' => $response, 'redirectUrl' => $redirect];
    }

    public function verifyNotification(string $rawBody, array $server): bool
    {
        $publicPath = $this->required('GCASH_PLATFORM_PUBLIC_KEY_FILE');
        $pem = @file_get_contents($publicPath);
        if ($pem === false) return false;
        $public = openssl_pkey_get_public($pem);
        if (!$public) return false;

        $signatureHeader = $server['HTTP_SIGNATURE'] ?? '';
        $clientId = $server['HTTP_CLIENT_ID'] ?? '';
        $responseTime = $server['HTTP_REQUEST_TIME'] ?? ($server['HTTP_RESPONSE_TIME'] ?? '');
        if (!$signatureHeader || !$clientId || !$responseTime) return false;
        if (!preg_match('/signature=([^,\s]+)/', $signatureHeader, $m)) return false;
        $signature = self::base64UrlDecode(urldecode($m[1]));
        if ($signature === '') return false;

        $uri = parse_url($server['REQUEST_URI'] ?? '/payment/callback/gcash.php', PHP_URL_PATH) ?: '/payment/callback/gcash.php';
        $content = 'POST ' . $uri . "\n" . $clientId . '.' . $responseTime . '.' . $rawBody;
        return openssl_verify($content, $signature, $public, OPENSSL_ALGO_SHA256) === 1;
    }
}
