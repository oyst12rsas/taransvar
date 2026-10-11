"""Mode is the authority; legacy enable flags are ignored, never combined."""


def maintenance_enabled(policy):
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
