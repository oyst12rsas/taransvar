<?php
require_once __DIR__ . '/../payment_lib.php';

final class MpesaProvider
{
    private array $cfg;
    private string $apiBase;

    public function __construct()
    {
        $this->cfg = paymentConfig();
        $env = strtolower(paymentCfg($this->cfg, 'MPESA_ENV', 'sandbox') ?? 'sandbox');
        $this->apiBase = $env === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }

    private function required(string $key): string
    {
        $v = paymentCfg($this->cfg, $key);
        if (!$v) throw new RuntimeException("Missing payment configuration: $key");
        return $v;
    }

    private function curlJson(string $url, array $headers, ?array $body = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('M-Pesa HTTP error: ' . $err);
        }
        curl_close($ch);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['raw' => $raw];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('M-Pesa returned HTTP ' . $status . ': ' . substr($raw, 0, 500));
        }
        return $data;
    }

    private function token(): string
    {
        $key = $this->required('MPESA_CONSUMER_KEY');
        $secret = $this->required('MPESA_CONSUMER_SECRET');
        $auth = base64_encode($key . ':' . $secret);
        $data = $this->curlJson(
            $this->apiBase . '/oauth/v1/generate?grant_type=client_credentials',
            ['Authorization: Basic ' . $auth, 'Accept: application/json']
        );
        if (empty($data['access_token'])) throw new RuntimeException('M-Pesa did not return an access token.');
        return (string)$data['access_token'];
    }

    private function password(string $timestamp): string
    {
        return base64_encode($this->required('MPESA_SHORTCODE') . $this->required('MPESA_PASSKEY') . $timestamp);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '0')) $digits = '254' . substr($digits, 1);
        if (str_starts_with($digits, '7') || str_starts_with($digits, '1')) $digits = '254' . $digits;
        if (!preg_match('/^254[17][0-9]{8}$/', $digits)) throw new RuntimeException('Enter a valid Kenyan M-Pesa phone number.');
        return $digits;
    }

    public function start(array $payment): array
    {
        $shortcode = $this->required('MPESA_SHORTCODE');
        $phone = $this->normalizePhone((string)($payment['phone'] ?? ''));
        $timestamp = date('YmdHis');
        $callback = paymentBaseUrl() . '/payment/callback/mpesa.php';
        $amountKes = max(1, (int)round(((int)$payment['amountMinor']) / 100));

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password' => $this->password($timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => paymentCfg($this->cfg, 'MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
            'Amount' => $amountKes,
            'PartyA' => $phone,
            'PartyB' => $shortcode,
            'PhoneNumber' => $phone,
            'CallBackURL' => $callback,
            'AccountReference' => substr((string)$payment['providerRequestId'], 0, 20),
            'TransactionDesc' => 'TaraSec WiFi plan',
        ];

        $response = $this->curlJson(
            $this->apiBase . '/mpesa/stkpush/v1/processrequest',
            ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/json', 'Accept: application/json'],
            $payload
        );
        $providerId = $response['CheckoutRequestID'] ?? null;
        if (!$providerId) throw new RuntimeException('M-Pesa did not return CheckoutRequestID.');
        return ['providerPaymentId' => (string)$providerId, 'response' => $response, 'redirectUrl' => null];
    }

    public function verifyCheckout(string $checkoutRequestId): array
    {
        $timestamp = date('YmdHis');
        $payload = [
            'BusinessShortCode' => $this->required('MPESA_SHORTCODE'),
            'Password' => $this->password($timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];
        return $this->curlJson(
            $this->apiBase . '/mpesa/stkpushquery/v1/query',
            ['Authorization: Bearer ' . $this->token(), 'Content-Type: application/json', 'Accept: application/json'],
            $payload
        );
    }
}
