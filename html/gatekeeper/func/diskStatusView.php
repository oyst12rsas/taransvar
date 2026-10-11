<?php
function sizeToKB(string $str): int
{
    if (!preg_match('/^\s*([\d.]+)\s*([KMGT]?I?)?\s*$/i', $str, $m)) {
        return 0;
    }

    $value = (float)$m[1];
    $unit  = strtoupper($m[2] ?? '');

    $multiplier = match ($unit) {
        'K', 'KI' => 1,
        'M', 'MI' => 1000,
        'G', 'GI' => 1000000,
        'T', 'TI' => 1000000000,
        default   => 1,
    };

    return (int) round($value * $multiplier);
}

function diskStatusPercent($reported)
{
    $parts = preg_split('/\s+/', trim((string)$reported));
    $total = sizeToKB($parts[0] ?? '');
    $used = sizeToKB($parts[1] ?? '');
    return $total > 0 ? (int)round(100 * $used / $total) : 100;
}

function diskStatusThreshold($status)
{
    $value = $status['operationsAgent']['resource_pressure']['disk_threshold_percent'] ?? 85;
    return is_numeric($value) && $value > 0 && $value <= 100 ? (float)$value : 85;
}

function diskStatusIssue($reported, $threshold = 85)
{
    $percent = diskStatusPercent($reported);
    if ($percent < $threshold) return null;
    return array('severity' => $percent >= 90 ? 'red' : 'yellow', 'label' => 'Disk usage',
        'message' => $percent.'% of disk used (reported total/used/free: '.(string)$reported.'). '.
            ($percent >= 90 ? 'Disk space is critically low; free space or increase capacity.'
             : 'The automatic maintenance threshold ('.$threshold.'%) has been reached; check log rotation results and remaining disk pressure.'));
}
