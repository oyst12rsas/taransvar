"""Deterministic local reporting survives model transport failure; no model text."""
import time

LABELS = {
    'minute_reporter_missing_security_status_field': 'Minute reporter lacks security-agent status.',
    'minute_reporter_missing_operations_status_field': 'Minute reporter lacks operations-agent status.',
    'security_worker_missing': 'Security/approval worker is not installed.',
    'security_config_missing': 'Security/approval configuration is missing.',
    'security_snapshot_missing': 'Security assessment snapshot is missing.',
    'security_enrollment_missing_or_unverified': 'Node-specific security enrollment is missing or unverified.',
    'tarasec-agent-approvals.timer_not_installed': 'Security/approval timer is not installed.',
    'tarasec-operations-agent.timer_not_active': 'Operations timer is inactive; pilot testing may require this.',
}

def local_report(state, now=None):
    findings = state.get('deployment_findings', [])
    result = state.get('diagnostics', {}).get('deployment_status', {}).get('result', {})
    return {'checked_at': int(time.time() if now is None else now),
        'assessment_checked_at': int(state.get('checked_at', 0)),
        'engine': 'operations_worker', 'status': state.get('status', 'unknown'),
        'error_stage': state.get('error_stage'),
        'deployment_checked_at': result.get('checked_at'),
        'deployment_findings': [item for item in findings if isinstance(item, str)][:24],
        'deployment_summary': [LABELS.get(item, 'Deployment check: ' + item)
                               for item in findings if isinstance(item, str)][:24],
        'central_report_verified': False}

def stage_error(error, stage, provider=None):
    if isinstance(error, TimeoutError):
        if stage == 'model_request':
            return 'Model request timed out (' + ('openai' if provider == 'openai' else 'flowise') + '); local deployment report preserved; no decision returned.'
        return 'Timeout during ' + stage + '; inspect the recorded stage.'
    return None
