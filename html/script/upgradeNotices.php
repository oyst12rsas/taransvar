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
    $version = $assessment['installed_version'];
    if ($version === null && $color === 'green') $color = 'yellow';
    $text = 'Installed TaraSec release: '.($version === null ? 'not reported' : $version).'.';
    if ($assessment['catalog_status'] !== 'ok') $text .= ' Upgrade notice catalog unavailable.';
    if ($messages) $text .= ' '.implode(' ', $messages);
    return ['color'=>$color, 'message'=>$text, 'assessment'=>$assessment];
}
