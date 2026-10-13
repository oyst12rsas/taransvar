<?php
// Manual reports are bound to setup.adminIP, never a browser-supplied target.
function manualIssueServer($conn): array
{
    $row = $conn->query('SELECT INET_NTOA(adminIP) ip, nickname, dbVersion FROM setup LIMIT 1')->fetch_assoc();
    if (!$row || !filter_var($row['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || (int)$row['dbVersion'] < 100)
        throw new RuntimeException('Update this server to database version 100 before reporting issues.');
    return $row;
}

function manualIssueSubmit($conn, bool $admin, array &$session, array $post): string
{
    if (!$admin) throw new RuntimeException('Administrator login required.');
    if (!is_string($post['csrf'] ?? null) || !is_string($session['manualIssueCsrf'] ?? null)
        || !hash_equals($session['manualIssueCsrf'], $post['csrf']))
        throw new RuntimeException('The form expired. Reload and try again.');
    $server = manualIssueServer($conn);
    $text = trim(is_string($post['description'] ?? null) ? $post['description'] : '');
    if (strlen($text) < 10 || strlen($text) > 4000 || preg_match('//u', $text) !== 1)
        throw new RuntimeException('Enter 10–4000 bytes of text describing the problem or verification result.');
    $now = microtime(true);
    $conn->begin_transaction();
    try {
        if (($post['action'] ?? '') === 'verify') {
            $key = is_string($post['key'] ?? null) ? $post['key'] : '';
            $stmt = $conn->prepare('SELECT issueId FROM issue WHERE nodeIp=INET_ATON(?) AND issueKey=? AND verification="manual" AND status<>"solved" FOR UPDATE');
            $stmt->bind_param('ss', $server['ip'], $key); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$row) throw new RuntimeException('Open manual issue not found on this server.');
            $id = (int)$row['issueId'];
            $stmt = $conn->prepare('UPDATE issue SET status="solved", solvedAt=?, checkedAt=?, nextActionAt=NULL, blocker=NULL WHERE issueId=?');
            $stmt->bind_param('ddi', $now, $now, $id); $stmt->execute(); $stmt->close();
            $kind = 'admin_verified_solved'; $eventId = hash('sha256', random_bytes(32));
        } else {
            // CSRF token also makes refresh/retry of a successful submission idempotent.
            $incarnation = substr(hash('sha256', $session['manualIssueCsrf']), 0, 32);
            $key = 'manual:'.$incarnation;
            $stmt = $conn->prepare('INSERT INTO issue (nodeIp,issueKey,discoveredAt,lastSeenAt,checkedAt,description,status,verification,nextActionAt) VALUES (INET_ATON(?),?,?,?,?,?,"open","manual",?) ON DUPLICATE KEY UPDATE issueId=LAST_INSERT_ID(issueId)');
            $stmt->bind_param('ssdddsd', $server['ip'], $key, $now, $now, $now, $text, $now);
            $stmt->execute(); $id = (int)$conn->insert_id; $stmt->close();
            $kind = 'discovered'; $eventId = hash('sha256', $incarnation.':1');
        }
        $detail = json_encode(['source'=>'server_admin', 'userId'=>(int)($session['userid'] ?? 0), 'evidence'=>$text], JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare('INSERT IGNORE INTO issueReport (issueId,eventId,reportedAt,kind,detail) VALUES (?,?,?,?,?)');
        $stmt->bind_param('isdss', $id, $eventId, $now, $kind, $detail); $stmt->execute(); $stmt->close();
        $conn->commit();
        return $kind === 'discovered' ? 'Issue recorded. The operations agent will collect it on its next check.' : 'Resolution verified and recorded.';
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
}
