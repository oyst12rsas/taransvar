"""Installed release accounting: fetching source never advances deployed versions."""
import json
import re

AUTOMATIC_FEATURES = {'database', 'runtime', 'taralink', 'gatekeeper', 'operations'}


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
    return release


def deployment_status(state, release):
    required = set()
    for role in state.get('roles', []):
        required.update(release['components'][role])
    features = state.get('features', {})
    versions = [features.get(name, {}).get('installed_version', 0) for name in required]
    if any(type(v) is not int or v < 0 for v in versions):
        raise ValueError('Invalid installed component version')
    return {'version': min(versions) if versions else 0,
            'available_version': release['version'], 'roles': state.get('roles', []),
            'automatic_enabled': state.get('automatic_enabled') is True,
            'components': {name: {k: v for k, v in record.items() if k in
                          ('installed_version', 'installed_revision', 'status')}
                           for name, record in features.items() if name in AUTOMATIC_FEATURES}}


def automatic_features(state, applicable):
    if state.get('automatic_enabled') is not True:
        raise ValueError('Automatic upgrades have not been enabled by the owner')
    return [name for name in applicable if name in AUTOMATIC_FEATURES and
            state.get('features', {}).get(name, {}).get('choice') == 'yes' and
            (state['features'][name].get('installed_once') is True or state['features'][name].get('installed_version', 0) > 0)]
