"""Mode is the authority; legacy enable flags are ignored, never combined."""


def maintenance_enabled(policy):
    # Selecting demo mode is the owner's maintenance authorization, including
    # required reboots. Never combine it with a legacy allow_reboot flag: older
    # installations can retain allow_reboot=False even after demo enrollment.
    # Production also permits typed maintenance; readiness and budgets are
    # enforced by the action, not by another enable switch here.
    return policy.get('mode') in ('demo', 'production')


def experimental_enabled(policy):
    return policy.get('mode') == 'demo'


def effective_policy(policy):
    result = dict(policy)
    for key in ('execute', 'allow_reboot', 'allow_experimental_commands'):
        result.pop(key, None)
    result['resource_protection'] = dict(policy.get('resource_protection', {}))
    result['resource_protection'].pop('enabled', None)
    return result
