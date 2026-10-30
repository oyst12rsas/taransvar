<?php
// Inspect only a bounded error body to choose fixed labels. Never return raw text.
function taraSecModelProviderFailure(int $http, string $body): array
{
    $detail = strtolower(substr($body, 0, 16384));
    $prefix = 'Model endpoint returned HTTP '.$http;
    $has = static fn(array $markers): bool => count(array_filter($markers,
        static fn(string $marker): bool => str_contains($detail, $marker))) > 0;
    if ($has(['insufficient_quota','no credits remaining','exceeded your current quota','credit balance is too low'])) {
        $code = 'provider_quota_exhausted';
        $message = $prefix.': model provider credits/quota exhausted; check billing and the credential used by this flow. No model decision returned.';
    } elseif ($has(['invalid_api_key','incorrect api key','invalid api key'])) {
        $code = 'provider_credential_rejected';
        $message = $prefix.': model provider API credential rejected; check the configured credential. No model decision returned.';
    } elseif (in_array($http, [401,403], true)) {
        $code = 'authentication_failed';
        $message = $prefix.': model endpoint authentication/access denied; check credentials and permissions.';
    } elseif ($http === 429 || $has(['rate_limit_exceeded','rate limit reached'])) {
        $code = 'rate_limited';
        $message = $prefix.': model request rate limited; retry after the provider cooldown.';
    } else {
        $code = 'model_http_error';
        $message = $prefix.'; inspect model service logs for the underlying error (response details withheld).';
    }
    return ['error'=>$code,'upstream_http_status'=>$http,'message'=>$message];
}
