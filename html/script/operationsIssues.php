<?php
// Called inside the registered-router status transaction. No commands are executed here.
function storeOperationsIssues($conn, string $sender, $agent): array
{
    $ack = [];
    if (!is_array($agent) || !is_array($agent['issues'] ?? null)) return [];
    // Mixed deployment: heartbeat must still work before the additive migration.
    $schema = $conn->query('SELECT dbVersion FROM setup LIMIT 1')->fetch_assoc();
    if ((int)($schema['dbVersion'] ?? 0) < 100) return [];
    foreach (array_slice($agent['issues'], 0, 64) as $item) {
        if (!is_array($item) || !is_string($item['key'] ?? null) || strlen($item['key']) > 255 || !preg_match('/^[a-f0-9]{32}$/', (string)($item['incarnation'] ?? ''))
            || !in_array($item['status'] ?? '', ['open','pending','blocked','verification_pending','solved'], true)) continue;
        foreach (['discovered_at','last_seen_at','checked_at'] as $field)
            if (!is_numeric($item[$field] ?? null)) continue 2;
        $key = $item['key']; $discovered = (float)$item['discovered_at'];
        $seen = (float)$item['last_seen_at']; $checked = (float)$item['checked_at'];
        $description = substr((string)($item['description'] ?? ''), 0, 4000);
        $status = $item['status'];
        // Argument values remain on the node; executable is a review hint, not runnable repair text.
        $commands = json_encode(['executable'=>$item['command_executable'] ?? null,'arguments'=>'retained locally']);
        $verification = substr((string)($item['verification'] ?? ''), 0, 64);
        $blocker = substr((string)($item['blocker'] ?? ''), 0, 4000);
        $next = is_numeric($item['next_action_at'] ?? null) ? (float)$item['next_action_at'] : null;
        $solved = is_numeric($item['solved_at'] ?? null) ? (float)$item['solved_at'] : null;
        $stmt = $conn->prepare('INSERT INTO issue (nodeIp,issueKey,discoveredAt,lastSeenAt,checkedAt,description,status,commands,verification,blocker,nextActionAt,solvedAt) VALUES (INET_ATON(?),?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE issueId=LAST_INSERT_ID(issueId), lastSeenAt=IF(VALUES(checkedAt)>=checkedAt AND NOT (verification="manual" AND status="solved"),VALUES(lastSeenAt),lastSeenAt), description=IF(VALUES(checkedAt)>=checkedAt AND NOT (verification="manual" AND status="solved"),VALUES(description),description), commands=IF(VALUES(checkedAt)>=checkedAt AND NOT (verification="manual" AND status="solved"),VALUES(commands),commands), verification=IF(VALUES(checkedAt)>=checkedAt AND NOT (verification="manual" AND status="solved"),VALUES(verification),verification), blocker=IF(VALUES(checkedAt)>=checkedAt AND NOT (verification="manual" AND status="solved"),VALUES(blocker),blocker), nextActionAt=IF(VALUES(checkedAt)>=checkedAt AND NOT (verification="manual" AND status="solved"),VALUES(nextActionAt),nextActionAt), solvedAt=IF(VALUES(checkedAt)>=checkedAt AND NOT (verification="manual" AND status="solved"),VALUES(solvedAt),solvedAt), status=IF(VALUES(checkedAt)>=checkedAt AND NOT (verification="manual" AND status="solved"),VALUES(status),status), checkedAt=GREATEST(checkedAt,VALUES(checkedAt))');
        $stmt->bind_param('ssdddsssssdd', $sender,$key,$discovered,$seen,$checked,$description,$status,$commands,$verification,$blocker,$next,$solved);
        $stmt->execute(); $id = $conn->insert_id; $stmt->close();
        foreach (array_slice(is_array($item['history'] ?? null) ? $item['history'] : [], 0, 16) as $event) {
            if (!is_array($event) || !is_int($event['event_id'] ?? null) || $event['event_id'] < 1
                || !is_numeric($event['at'] ?? null) || !is_string($event['kind'] ?? null)) continue;
            $eventId=hash('sha256', $item['incarnation'].':'.$event['event_id']); $at=(float)$event['at']; $kind=substr($event['kind'],0,32);
            $detail=json_encode($event['detail'] ?? null);
            if (strlen($detail)>8000) continue;
            $stmt=$conn->prepare('INSERT IGNORE INTO issueReport (issueId,eventId,reportedAt,kind,detail) VALUES (?,?,?,?,?)');
            $stmt->bind_param('isdss',$id,$eventId,$at,$kind,$detail); $stmt->execute(); $stmt->close();
            $ack[$item['incarnation']] = max($ack[$item['incarnation']] ?? 0, $event['event_id']);
        }
    }
    return $ack;
}
