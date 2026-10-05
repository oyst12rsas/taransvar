<?php
declare(strict_types=1);

// Demo 5 uses ordinary report.php ingestion. Only DB-enabled, bounded exercises
// can escalate automatically; production partner policy is deliberately separate.
function partnerIncidentTransition(array $session, ?int $tag, int $observedAt, int $now, int $grace): string {
    if ($observedAt < (int)$session['createdEpoch'] || $observedAt > $now + 5) return 'ignore';
    if ($tag === null) return 'unknown';
    if ($tag > 0) return 'tagged';
    if (empty($session['notifiedEpoch'])) return 'reported';
    return $observedAt >= (int)$session['notifiedEpoch'] + $grace ? 'restrict' : 'reported';
}

function partnerIncidentRecord(mysqli $db, int $reportId, string $source, int $port,
    string $receiver, ?int $tag, ?int $observedAt): void {
    $role = $db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (empty($role['central'])) return;
    // Old senders without on-wire evidence remain unknown, never assumed untagged.
    $s = $db->prepare("SELECT s.*,UNIX_TIMESTAMP(s.created) createdEpoch,
        UNIX_TIMESTAMP(s.notifiedAt) notifiedEpoch,c.graceSeconds,c.restrictionSeconds
        FROM demo5Session s JOIN partnerRouter r ON r.routerId=s.routerId AND r.demo5Enabled=1
        JOIN demo5Configuration c ON c.routerId=s.routerId
        WHERE s.sourceIp=INET_ATON(?) AND s.receiverIp=INET_ATON(?)
          AND s.expiresAt>NOW() AND s.state NOT IN ('released','expired') FOR UPDATE");
    $db->begin_transaction();
    try {
        $s->bind_param('ss',$source,$receiver); $s->execute();
        $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        foreach ($rows as $row) {
            $id = $row['sessionId'];
            $decision = $observedAt === null ? 'unknown' : partnerIncidentTransition($row,$tag,$observedAt,time(),max(15,min(120,(int)$row['graceSeconds'])));
            if ($decision === 'ignore') continue;
            $at = $observedAt ?? time();
            $e = $db->prepare("INSERT IGNORE INTO partnerIncidentEvidence
                (sessionId,reportId,receiverIp,sourcePort,observedTag,observedAt)
                VALUES (?,?,INET_ATON(?),?,?,FROM_UNIXTIME(?))");
            $e->bind_param('sisiii',$id,$reportId,$receiver,$port,$tag,$at); $e->execute();
            $new = $e->affected_rows > 0; $e->close();
            if (!$new) continue;
            $state = !empty($row['restrictedAt']) ? 'blacklisted' :
                ($decision === 'tagged' ? 'tagging_observed' : ($decision === 'unknown' ? 'tag_unknown' : 'reported'));
            $u = $db->prepare("UPDATE demo5Session SET firstReportId=COALESCE(firstReportId,?),
                latestReportId=?,firstReportAt=COALESCE(firstReportAt,FROM_UNIXTIME(?)),state=?
                WHERE sessionId=?");
            $u->bind_param('iiiss',$reportId,$reportId,$at,$state,$id); $u->execute(); $u->close();
            if ($decision === 'tagged' || $tag === 0) {
                $col = $decision === 'tagged' ? 'lastTaggedAt' : 'lastUntaggedAt';
                $u=$db->prepare("UPDATE demo5Session SET $col=FROM_UNIXTIME(?) WHERE sessionId=?");
                $u->bind_param('is',$at,$id); $u->execute(); $u->close();
            }
            if ($decision === 'tagged' && empty($row['restrictedAt'])) {
                $u=$db->prepare("UPDATE partnerRouter SET taggingState='observed',taggingStateUpdated=NOW() WHERE routerId=? AND (restrictionUntil IS NULL OR restrictionUntil<=NOW())");
                $u->bind_param('i',$row['routerId']); $u->execute(); $u->close();
            }
            if ($decision === 'restrict' && empty($row['restrictedAt'])) {
                $duration=max(30,min(300,(int)$row['restrictionSeconds']));
                $u=$db->prepare("UPDATE demo5Session SET restrictedAt=NOW(),
                    restrictionUntil=LEAST(expiresAt,DATE_ADD(NOW(),INTERVAL ? SECOND)),state='blacklisted'
                    WHERE sessionId=?");
                $u->bind_param('is',$duration,$id); $u->execute(); $u->close();
                $u=$db->prepare("UPDATE partnerRouter r JOIN demo5Session s ON s.routerId=r.routerId
                    SET r.taggingState='failed',r.taggingStateUpdated=NOW(),
                    r.restrictionUntil=s.restrictionUntil,
                    r.restrictionReason='Demo 5: repeated untagged rejection after partner notification'
                    WHERE s.sessionId=?");
                $u->bind_param('s',$id); $u->execute(); $u->close();
                $u=$db->prepare("INSERT IGNORE INTO partnerRestrictionDelivery(sessionId,receiverIp)
                    SELECT ?,receiverIp FROM partnerRestrictionReceiver WHERE enabled=1
                    AND receiverIp<>INET_ATON(?) AND receiverIp<>INET_ATON(?)");
                $central=(string)($_SERVER['SERVER_ADDR']??'127.0.0.1');
                $u->bind_param('sss',$id,$source,$central); $u->execute(); $u->close();
            }
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
