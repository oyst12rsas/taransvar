<?php
// TaraSec payment backend configuration.
// Keep all merchant secrets outside the repository, preferably as environment variables.

function taraPaymentEnvironment(): string
{
    $env = strtolower(trim((string)getenv('TARASEC_PAYMENT_ENV')));
    return in_array($env, ['production', 'live'], true) ? 'production' : 'sandbox';
}

function taraPaymentConfig(): array
{
    return [
        'provider' => 'braintree',
        'environment' => taraPaymentEnvironment(),
        'merchantId' => trim((string)getenv('BRAINTREE_MERCHANT_ID')),
        'publicKey' => trim((string)getenv('BRAINTREE_PUBLIC_KEY')),
        'privateKey' => trim((string)getenv('BRAINTREE_PRIVATE_KEY')),
    ];
}

function taraPaymentConfigured(array $config): bool
{
    return $config['merchantId'] !== '' && $config['publicKey'] !== '' && $config['privateKey'] !== '';
}

function taraPaymentAutoload(): ?string
{
    $candidates = [
        dirname(__DIR__, 2) . '/vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return $file;
        }
    }
    return null;
}

function taraPaymentGateway(array $config)
{
    if (!taraPaymentConfigured($config)) {
        throw new RuntimeException('Braintree merchant credentials are not configured on this server.');
    }

    taraPaymentAutoload();
    if (!class_exists('Braintree\\Gateway')) {
        throw new RuntimeException('Braintree PHP SDK is not installed. Run: composer require braintree/braintree_php');
    }

    return new Braintree\Gateway([
        'environment' => $config['environment'],
        'merchantId' => $config['merchantId'],
        'publicKey' => $config['publicKey'],
        'privateKey' => $config['privateKey'],
    ]);
}
