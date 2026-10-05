<?php
declare(strict_types=1);
// Run only through the timer on the central DB server, never through HTTP.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../html/dbfunc.php';
$db=getConnection();
$role=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
if (empty($role['central'])) exit;
$lock=$db->query("SELECT GET_LOCK('partner_incident_notify',0) acquired")->fetch_assoc();
if (empty($lock['acquired'])) exit;
try {
    $rows=$db->query("SELECT s.sessionId,INET_NTOA(r.ip) gateway,INET_NTOA(s.sourceIp) source,
        h.port,h.severity FROM demo5Session s JOIN partnerRouter r ON r.routerId=s.routerId
        JOIN hackReport h ON h.reportId=s.firstReportId
        WHERE s.notifiedAt IS NULL AND s.expiresAt>NOW() AND s.state NOT IN ('released','expired')
        AND r.demo5Enabled=1 LIMIT 20")->fetch_all(MYSQLI_ASSOC);
    foreach($rows as $r) {
        $url='http://'.$r['gateway'].'/script/report.php?'.http_build_query([
            'ip'=>$r['source'],'port'=>$r['port'],'severity'=>$r['severity']??7,
            'code'=>'from_dbserver','wt'=>'Receiver rejection during Demo 5; tag subsequent traffic']);
        $context=stream_context_create(['http'=>['timeout'=>3,'follow_location'=>0,'ignore_errors'=>true]]);
        $reply=@file_get_contents($url,false,$context);
        $headers=$http_response_header??[];
        if ($reply!==false && trim($reply)==='ok' && preg_match('/^HTTP\/\S+ 200\b/',$headers[0]??'')) {
            $q=$db->prepare('UPDATE demo5Session SET notifiedAt=NOW() WHERE sessionId=? AND notifiedAt IS NULL');
            $q->bind_param('s',$r['sessionId']);$q->execute();$q->close();
        }
    }
    // Expiry removes the restriction but does not falsely declare repaired tagging.
    $db->query("UPDATE partnerRouter SET restrictionUntil=NULL WHERE restrictionUntil<=NOW()");
} finally {$db->query("SELECT RELEASE_LOCK('partner_incident_notify')");}
