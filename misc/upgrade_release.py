"""Installed release accounting: fetching source never advances deployed versions."""
import json
import re

AUTOMATIC_FEATURES = {'database', 'runtime', 'taralink', 'gatekeeper', 'operations', 'google'}
RISK_LEVELS = {'none': 0, 'low': 1, 'medium': 2, 'high': 3}
FEATURE_RISK = {'database': 'medium', 'runtime': 'low', 'taralink': 'medium',
                'gatekeeper': 'low', 'operations': 'medium', 'google': 'high',
                'ssh-window': 'high', 'ssh-key': 'high', 'ssh-auth': 'high', 'honeypot': 'high'}


def upgrade_risk(name, release=None, context=None):
    # Unknown features/ratings require confirmation. Release metadata may raise
    # risk, but cannot lower a control's built-in minimum classification.
    baseline = FEATURE_RISK.get(name, 'high')
    if name == 'google' and (context or {}).get('google_existing') is True:
        baseline = 'medium'  # Refresh only; enrollment is independently forbidden.
    declared = (release or {}).get('risks', {}).get(name, baseline)
    if not isinstance(declared, str) or declared not in RISK_LEVELS: return 'high'
    return max((baseline, declared), key=RISK_LEVELS.get)


def demo_automatic(name, mode, release=None, context=None):
    return mode == 'demo' and RISK_LEVELS[upgrade_risk(name, release, context)] <= RISK_LEVELS['medium']



def release_info(repo):
    release = json.loads((repo / 'release.json').read_text())
    if type(release.get('version')) is not int or release['version'] < 1:
        raise ValueError('Invalid release version')
    components = release.get('components')
    if not isinstance(components, dict) or any(
            role not in components or not isinstance(components[role], list) or
            not components[role] or any(c not in AUTOMATIC_FEATURES for c in components[role])
            for role in ('database', 'gateway', 'node')):
        raise ValueError('Invalid release component list')
    if not isinstance(release.get('risks', {}), dict):
        raise ValueError('Invalid release risk classification')
    return release


def deployment_status(state, release):
    required = set()
    for role in state.get('roles', []):
        required.update(release['components'][role])
    features = state.get('features', {})
    versions = [features.get(name, {}).get('installed_version', 0) for name in required]
    if any(type(v) is not int or v < 0 for v in versions):
        raise ValueError('Invalid installed component version')
    managed = state.get('managed_policy', {}).get('enabled') is True
    return {'managed_policy': state.get('managed_policy', {}), 'managed_upgrade': state.get('managed_upgrade', {}), 'version': min(versions) if versions else 0,
            'available_version': release['version'], 'roles': state.get('roles', []),
            'mode': state.get('mode', 'unconfigured'),
            'automatic_enabled': managed or state.get('mode') == 'demo',
            'upgrade_policy': 'daily_staged' if managed else 'automatic_through_medium' if state.get('mode') == 'demo' else 'confirmation_each_upgrade',
            'components': {name: {k: v for k, v in record.items() if k in
                          ('installed_version', 'installed_revision', 'status', 'details')}
                           for name, record in features.items() if name in AUTOMATIC_FEATURES}}


def automatic_features(state, applicable, mode=None, release=None):
    mode = state.get('mode') if mode is None else mode
    if mode != 'demo':
        raise ValueError('Production upgrades require user confirmation for every upgrade')
    selected = [name for name in applicable if demo_automatic(name, mode, release, state) and
                state.get('features', {}).get(name, {}).get('choice') != 'never']
    if release is not None:
        dependencies = {'runtime': ['database'], 'taralink': ['runtime'],
                        'gatekeeper': ['database', 'runtime'], 'operations': ['runtime']}
        # Do not deploy a dependent against an unapproved/high-risk older schema.
        while True:
            blocked = [name for name in selected if any(
                dep in applicable and dep not in selected and
                state.get('features', {}).get(dep, {}).get('installed_version', 0) < release['version']
                for dep in dependencies.get(name, []))]
            if not blocked: break
            selected = [name for name in selected if name not in blocked]
    return selected
