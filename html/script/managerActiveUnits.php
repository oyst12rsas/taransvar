<?php
declare(strict_types=1);

function mergeManagerActiveUnits(array $dhcp,array $observed): array {
    $byIp=[];
    foreach (array_merge($dhcp,$observed) as $row) {
        $ip=(string)($row['lastIp'] ?? '');
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || $ip==='0.0.0.0') continue;
        if (!isset($byIp[$ip])) { $byIp[$ip]=$row; continue; }
        // DHCP supplies hardware details; traffic observations can be newer.
        foreach (['hostname','vendor','mac'] as $field) {
            if (empty($byIp[$ip][$field]) && !empty($row[$field])) $byIp[$ip][$field]=$row[$field];
        }
        if (strcmp((string)$row['lastSeen'],(string)$byIp[$ip]['lastSeen'])>0)
            $byIp[$ip]['lastSeen']=$row['lastSeen'];
    }
    $units=array_values($byIp);
    usort($units,fn(array $a,array $b): int => strcmp((string)$b['lastSeen'],(string)$a['lastSeen']));
    return array_slice($units,0,100);
}

function managerActiveUnits(mysqli $db): array {
    $dhcp=$db->query("SELECT COALESCE(hostname,'') AS hostname, COALESCE(vendorClass,'') AS vendor,
        clientMac AS mac, lastSeen, INET_NTOA(currentIp) AS lastIp
        FROM dhcpClientState WHERE lastSeen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ORDER BY lastSeen DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
    // Canonical unit observations also cover static-address and routed VPN clients.
    // A configured VPN peer alone is not evidence of recent activity.
    $observed=$db->query("SELECT COALESCE(hostname,'') AS hostname, '' AS vendor, '' AS mac,
        lastSeen, INET_NTOA(ipAddress) AS lastIp FROM unit
        WHERE lastSeen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ORDER BY lastSeen DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
    // Use indexable recent ranges rather than scanning all historical flows.
    // Only local unit addresses qualify; do not list arbitrary Internet sources.
    $traffic=$db->query("SELECT MAX(COALESCE(u.hostname,'')) AS hostname, '' AS vendor, '' AS mac,
        MAX(t.seen) AS lastSeen, INET_NTOA(u.ipAddress) AS lastIp
        FROM unit u JOIN (
            SELECT ipFrom, lastSeen AS seen FROM traffic
            WHERE lastSeen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            UNION ALL
            SELECT ipFrom, created AS seen FROM traffic
            WHERE lastSeen IS NULL AND created >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ) t ON t.ipFrom=u.ipAddress
        GROUP BY u.ipAddress ORDER BY lastSeen DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
    return mergeManagerActiveUnits($dhcp,array_merge($observed,$traffic));
}
