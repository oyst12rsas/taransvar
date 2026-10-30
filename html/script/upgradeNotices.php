<?php
// Notices are data only. They never contain commands, source URLs or local grants.
function taraSecUpgradeAssessment(array $status, ?array $catalog = null): array
{
    $software = is_array($status['software'] ?? null) ? $status['software'] : [];
    $version = $software['version'] ?? null;
    if (!is_int($version) || $version < 0) $version = null;
    $role = ($status['role'] ?? '') === 'global_db' ? 'database' : ($status['role'] ?? 'unknown');
    $result = ['checked_at'=>time(), 'installed_version'=>$version, 'pending'=>[], 'catalog_status'=>'ok'];
    if ($catalog === null) {
        $path = '/etc/tarasec/upgrade-notices.json';
        if (!file_exists($path)) $path = __DIR__.'/../upgrade-notices.json';
        $raw = @file_get_contents($path, false, null, 0, 65537);
        $catalog = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
    }
    if (!is_array($catalog) || !is_array($catalog['notices'] ?? null) || count($catalog['notices']) > 8) {
        $result['catalog_status'] = 'unavailable';
        return $result;
    }
    foreach ($catalog['notices'] as $item) {
        if (!is_array($item) || !is_string($item['id'] ?? null) ||
            !preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $item['id']) ||
            !is_int($item['minimum_version'] ?? null) || $item['minimum_version'] < 1 ||
            !in_array($item['severity'] ?? '', ['security','important','routine'], true) ||
            !is_array($item['roles'] ?? null) || !$item['roles'] || count($item['roles']) > 3 ||
            array_diff($item['roles'], ['database','gateway','node']) || !is_string($item['note'] ?? null) ||
            strlen($item['note']) > 192 || !is_string($item['network_impact'] ?? '') ||
            strlen($item['network_impact'] ?? '') > 128) {
            $result['catalog_status'] = 'unavailable';
            $result['pending'] = [];
            return $result;
        }
        // Unknown role cannot establish that a role-specific notice is irrelevant.
        if ($role !== 'unknown' && !in_array($role, $item['roles'], true)) continue;
        if ($version !== null && $version >= $item['minimum_version']) continue;
        $result['pending'][] = array_intersect_key($item, array_flip(
            ['id','minimum_version','severity','note','network_impact']));
    }
    return $result;
}
function taraSecUpgradeView(array $status): array
{
    // Reevaluate at display time: new DB notices affect even offline/legacy units.
    $assessment = taraSecUpgradeAssessment($status);
    $color = $assessment['catalog_status'] !== 'ok' ? 'yellow' : 'green';
    $messages = [];
    foreach ($assessment['pending'] as $item) {
        if (in_array($item['severity'], ['security','important'], true)) $color = 'red';
        elseif ($color !== 'red') $color = 'yellow';
        $messages[] = strtoupper($item['severity']).': '.$item['note'].' '.($item['network_impact'] ?? '').
            ' Required release: '.$item['minimum_version'].'.';
    }
    $google = $status['software']['components']['google']['details'] ?? null;
    if (is_array($google) && ($google['approval_worker'] ?? '') === 'needs_configuration') {
        if ($color === 'green') $color = 'yellow';
        $messages[] = 'Optional approval worker needs local policy configuration; existing Google web login is independent.';
    }
    $managed=$status['software']['managed_upgrade']??[];
    if (is_array($managed)&&($managed['status']??'')==='failed') {
        $color='red';$messages[]='Daily upgrade failed verification; rollout is held for this candidate. See Upgrade rollout on the central server.';
    } elseif (is_array($managed)&&($managed['status']??'')==='pending') {
        if ($color==='green')$color='yellow';
        foreach($managed['pending']??[] as $entry) if(is_array($entry))
            $messages[]='Daily setup prerequisite: '.substr((string)($entry['component']??''),0,32).': '.substr((string)($entry['reason']??''),0,180);
    }
    if (is_array($managed)&&!empty($managed['withheld_tasks'])) {
        $color='red';
        foreach($managed['withheld_tasks'] as $task) if(is_array($task))
            $messages[]='Task skipped: '.substr((string)($task['component']??''),0,40).' — '.substr((string)($task['state']??''),0,40).'.';
    }
    if (is_array($managed)&&($managed['scheduler']['status']??'')==='blocked') {
        $color='red';$messages[]='Daily upgrade scheduler blocked: '.substr((string)($managed['scheduler']['reason']??''),0,160).'.';
    }
    $version = $assessment['installed_version'];
    if ($version === null && $color === 'green') $color = 'yellow';
    $text = 'Installed TaraSec release: '.($version === null ? 'not reported' : $version).'.';
    $partial = [];
    foreach (($status['software']['components'] ?? []) as $name=>$component) {
        if (!is_array($component) || ($component['status'] ?? '') !== 'partial') continue;
        if ($name === 'operations') {
            $partial[] = 'operations agent activation';
            $check = $component['details']['connection_check'] ?? '';
            if ($check === 'provider_quota_exhausted') $text .= ' Model provider credits/quota exhausted; restore billing for the credential used by Flowise.';
            elseif ($check === 'provider_credential_rejected') $text .= ' Model provider API credential rejected; repair the credential used by the configured flow.';
            elseif ($check === 'rate_limited') $text .= ' Model request rate limited; retry after the provider cooldown.';
            $text .= ' Resume the installed operations agent on this server with sudo ./tarasec upgrade --configure-operations.';
        } elseif (in_array($name, ['google','runtime','database','gatekeeper','taralink'], true)) $partial[] = $name;
    }
    if ($partial) $text .= ' Combined release is incomplete because of '.implode(', ', $partial).'; this does not mean completed components are missing.';

    if ($assessment['catalog_status'] !== 'ok') $text .= ' Upgrade notice catalog unavailable.';
    if ($messages) $text .= ' '.implode(' ', $messages);
    return ['color'=>$color, 'message'=>$text, 'assessment'=>$assessment];
}
