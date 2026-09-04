<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/dbfunc.php';

$out = [
    'ok' => true,
    'demo' => true,
    'packages' => [],
    'usage' => null
];

try {
    $conn = getConnection();

    $sql = "select label,quotaMB,priceKsh,currency,cast(isDemo as unsigned) isDemo from hotspotPricePackage where cast(enabled as unsigned)=1 order by sortOrder,quotaMB";
    if ($res = $conn->query($sql)) {
        while ($r = $res->fetch_assoc()) {
            $out['packages'][] = [
                'label' => (string)$r['label'],
                'quota_mb' => (int)$r['quotaMB'],
                'price_ksh' => (float)$r['priceKsh'],
                'currency' => (string)$r['currency'],
                'demo' => ((int)$r['isDemo'] === 1)
            ];
        }
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $stmt = $conn->prepare(
            "select s.sessionid,s.username,coalesce(h.usageMB,0) totalMiB,h.quotaMB,h.subscriptionType,coalesce(c.uploadKiB,0) uploadKiB,coalesce(c.downloadKiB,0) downloadKiB from session s join hotspotSubscriber h on h.username=s.username left join hotspotUsageCheckpoint c on c.sessionId=s.sessionid where s.ip=? and s.active=1 order by s.sessionid desc limit 1"
        );
        if ($stmt) {
            $stmt->bind_param('s', $ip);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($r = $res->fetch_assoc())) {
                $sessionMiB = (((float)$r['uploadKiB']) + ((float)$r['downloadKiB'])) / 1024.0;
                $quota = (float)$r['quotaMB'];
                $total = (float)$r['totalMiB'];
                $out['usage'] = [
                    'username' => (string)$r['username'],
                    'session_mib' => round($sessionMiB, 2),
                    'total_mib' => round($total, 2),
                    'quota_mib' => round($quota, 2),
                    'remaining_mib' => $quota > 0 ? round(max(0, $quota - $total), 2) : null,
                    'subscription_type' => (string)$r['subscriptionType']
                ];
            }
        }
    }
    $conn->close();
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['error'] = 'Hotspot pricing/usage data is not available yet. Run db/migrate_hotspot_pricing.sql.';
}

echo json_encode($out, JSON_UNESCAPED_SLASHES);
