<?php
declare(strict_types=1);

// Operator configuration, never populated from caller-supplied URLs or headers.
function taraServiceBase(string $value): string {
    $u = parse_url($value);
    if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host'])
        || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])
        || (isset($u['port']) && ($u['port'] < 1 || $u['port'] > 65535)))
        throw new RuntimeException('Service configuration requires a trusted HTTPS API base.');
    return rtrim($value, '/');
}

function taraServiceConfig(): array {
        $path = '/etc/tarasec/services.php';
        $config = is_readable($path) ? require $path : [];
        if (!is_array($config)) throw new RuntimeException('Invalid service configuration.');
        return $config;
}

function taraAccountServices(?array $config = null): array {
    $config ??= taraServiceConfig();
    $identity = trim((string)($config['identity_api_base'] ?? ''));
    $subscriber = trim((string)($config['subscriber_api_base'] ?? ''));
    if ($identity === '' && $subscriber === '') return [
        'source' => 'tarasec.org',
        'identity_api_base' => 'https://tarasec.org/api/v1/identity',
        'subscriber_api_base' => 'https://tarasec.org/api/v1/subscriber',
    ];
    // These services share subscriber tokens; partial configuration must fail.
    if ($identity === '' || $subscriber === '') throw new RuntimeException('Configure both account service API bases.');
    $identity = taraServiceBase($identity); $subscriber = taraServiceBase($subscriber);
    $a = parse_url($identity); $b = parse_url($subscriber);
    if (strtolower($a['host']) !== strtolower($b['host']) || ($a['port'] ?? 443) !== ($b['port'] ?? 443))
        throw new RuntimeException('Account services must share the same HTTPS origin.');
    return ['source' => 'gateway', 'identity_api_base' => $identity, 'subscriber_api_base' => $subscriber];
}

// Gatekeeper admin credentials remain separate from subscriber credentials.
function taraAdminServices(array $gatewayConfig = [], ?array $services = null): array {
    $services ??= taraServiceConfig();
    $api = trim((string)($services['admin_api_url'] ?? ''));
    $login = trim((string)($services['admin_sign_in_url'] ?? ''));
    if ($api === '' && $login === '') {
        $api = trim((string)($gatewayConfig['agent_api'] ?? ''));
        $login = trim((string)($gatewayConfig['sign_in_url'] ?? ''));
    }
    if ($api === '' && $login === '') {
        $api = 'https://tarasec.org/ops/agent/api.php';
        $login = 'https://tarasec.org/ops/agent/gatekeeper.php';
    }
    if ($api === '' || $login === '') throw new RuntimeException('Configure both administrator service URLs.');
    $pair = taraAccountServices(['identity_api_base'=>$login,'subscriber_api_base'=>$api]);
    return ['sign_in_url'=>$pair['identity_api_base'],'agent_api'=>$pair['subscriber_api_base']];
}
