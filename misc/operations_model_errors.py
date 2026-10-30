"""Sanitized downstream classification of model endpoint HTTP failures."""

def classify_model_http(status, body):
    detail = (body[:16384].decode('utf-8', errors='replace') if isinstance(body, bytes)
              else body[:16384] if isinstance(body, str) else '').lower()
    prefix = 'Model endpoint returned HTTP ' + str(status)
    if any(marker in detail for marker in ('insufficient_quota', 'no credits remaining',
            'exceeded your current quota', 'credit balance is too low')):
        code = 'provider_quota_exhausted'
        message = prefix + ': model provider credits/quota exhausted; check billing and the credential used by this flow. No model decision returned.'
    elif any(marker in detail for marker in ('invalid_api_key', 'incorrect api key', 'invalid api key')):
        code = 'provider_credential_rejected'
        message = prefix + ': model provider API credential rejected; check the configured credential. No model decision returned.'
    elif status in (401, 403):
        code = 'authentication_failed'
        message = prefix + ': model endpoint authentication/access denied; check credentials and permissions.'
    elif status == 429 or 'rate_limit_exceeded' in detail or 'rate limit reached' in detail:
        code = 'rate_limited'
        message = prefix + ': model request rate limited; retry after the provider cooldown.'
    else:
        code = 'model_http_error'
        message = prefix + '; inspect model service logs for the underlying error (response details withheld).'
    return dict(code=code, http_status=status, message=message)


def read_model_http_error(error):
    try: body = error.read(16384)
    except Exception: body = b''
    finally: error.close()
    return classify_model_http(error.code, body)
