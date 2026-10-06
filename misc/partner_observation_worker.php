<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit;
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../html/dbfunc.php';
require_once __DIR__.'/../html/script/partnerObservationLib.php';
$db=getConnection();
$lock=$db->query("SELECT GET_LOCK('partner_observation_worker',0) acquired")->fetch_assoc();
if (empty($lock['acquired'])) exit;
try {
    $role=$db->query('SELECT CAST(isGlobalDbServer AS UNSIGNED) central FROM setup LIMIT 1')->fetch_assoc();
    if (!empty($role['central'])) {
        $cfg=partnerObservationConfig();if (empty($cfg['enabled'])) exit;
        $rows=$db->query('SELECT o.observationId,INET_NTOA(o.sourceIp) source,
            h.port,h.severity FROM partnerObservation o JOIN hackReport h ON h.reportId=o.reportId
            WHERE o.notifiedAt IS NULL AND o.expiresAt>NOW() LIMIT 20')->fetch_all(MYSQLI_ASSOC);
        foreach($rows as $r) {
            $url='http://'.$r['source'].'/script/report.php?'.http_build_query([
                'ip'=>$r['source'],'port'=>$r['port'],'severity'=>$r['severity'],
                'code'=>'from_dbserver','wt'=>'Partner observation: receiver reported malicious untagged traffic; identify and tag the offending unit']);
            $reply=@file_get_contents($url,false,stream_context_create(['http'=>['timeout'=>3,'follow_location'=>0,'ignore_errors'=>true]]));
            if (trim((string)$reply)==='ok' && preg_match('/^HTTP\/\S+ 200\b/',($http_response_header??[])[0]??'')) {
                $q=$db->prepare('UPDATE partnerObservation SET notifiedAt=NOW(),status=\'observing\' WHERE observationId=? AND notifiedAt IS NULL');
                $q->bind_param('i',$r['observationId']);$q->execute();$q->close();
            }
        }
        $rows=$db->query("SELECT observationId FROM partnerObservation WHERE expiresAt>NOW() AND notifiedAt IS NOT NULL AND status<>'alarm' LIMIT 100")->fetch_all(MYSQLI_ASSOC);
        foreach($rows as $r) {
            $q=$db->prepare('SELECT * FROM partnerObservationSample WHERE observationId=? AND checkedAt>DATE_SUB(NOW(),INTERVAL 90 SECOND)');
            $q->bind_param('i',$r['observationId']);$q->execute();$samples=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
            $decision=partnerObservationDecision($samples,$cfg);
            if ($decision['status']==='alarm') {
                $q=$db->prepare("UPDATE partnerObservation SET status='alarm' WHERE observationId=?");
                $q->bind_param('i',$r['observationId']);$q->execute();$q->close();
                error_log('TaraSec partner alarm: observation='.$r['observationId'].' malicious_untagged_ratio='.$decision['ratio']);
                $warning='Partner tagging alarm: observation '.$r['observationId'].'; high malicious rejection ratio among untagged connections. Review partner observation evidence.';
                $q=$db->prepare('INSERT INTO warning(warning) VALUES(?)');$q->bind_param('s',$warning);$q->execute();$q->close();
            }
        }
        $db->query("UPDATE partnerObservation SET status='expired_insufficient_evidence' WHERE expiresAt<=NOW() AND status<>'alarm' AND status<>'expired_insufficient_evidence'");
        exit;
    }
    $cfg=json_decode(file_get_contents($argv[1]??'/etc/tarasec/partner-restrictions.json'),true,512,JSON_THROW_ON_ERROR);
    $base=rtrim((string)$cfg['db_url'],'/');$token=(string)$cfg['node_token'];
    if (!filter_var($base,FILTER_VALIDATE_URL)||!in_array(parse_url($base,PHP_URL_SCHEME),['http','https'],true)||strlen($token)<32) throw new RuntimeException('Invalid receiver configuration');
    $url=$base.'/script/partnerObservation.php';
    $options=['timeout'=>5,'follow_location'=>0,'ignore_errors'=>true,'header'=>"X-TaraSec-Node-Token: $token\r\n"];
    $raw=file_get_contents($url,false,stream_context_create(['http'=>$options]));
    if (!preg_match('/^HTTP\/\S+ 200\b/',($http_response_header??[])[0]??'')) throw new RuntimeException('Observation feed failed');
    $feed=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    foreach(array_slice($feed['observations']??[],0,20) as $r) {
        $ip=$r['source_ip'];$start=(int)$r['start_epoch'];$end=min(time(),(int)$r['end_epoch']);
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||$start>=$end||$end-$start>600) continue;
        // Only complete, consistently tagged flow records within the window.
        // Unknown/mixed tags cannot enter the untagged denominator.
        $q=$db->prepare("SELECT f.*, EXISTS(SELECT 1 FROM syslogThreat s JOIN syslog l ON l.syslogId=s.syslogId
            WHERE s.src_ip=INET_ATON(?) AND s.src_port=f.portFrom AND s.dst_ip=f.ipTo AND s.dst_port=f.portTo
            AND CAST(s.is_attack AS UNSIGNED)=1 AND s.severity>=7
            AND LOWER(s.protocol)='tcp'
            AND l.message NOT LIKE '%SSH_DENIED%' AND l.message NOT LIKE '%access policy%'
            AND UNIX_TIMESTAMP(l.created) BETWEEN ? AND ?) malicious,
            EXISTS(SELECT 1 FROM syslogThreat s JOIN syslog l ON l.syslogId=s.syslogId
            WHERE s.src_ip=INET_ATON(?) AND s.src_port=f.portFrom AND s.dst_ip=f.ipTo AND s.dst_port=f.portTo
            AND LOWER(s.protocol)='tcp'
            AND l.message LIKE '%SSH_DENIED%' AND UNIX_TIMESTAMP(l.created) BETWEEN ? AND ?) policyDenied
            FROM (SELECT portFrom,ipTo,portTo,
              CASE WHEN COUNT(tag)=COUNT(*) AND MIN(tag)=MAX(tag) THEN MIN(tag) ELSE NULL END observedTag
              FROM traffic WHERE ipFrom=INET_ATON(?) AND UNIX_TIMESTAMP(created)>=?
              AND UNIX_TIMESTAMP(COALESCE(lastSeen,created))<=? GROUP BY portFrom,ipTo,portTo) f LIMIT 100001");
        $q->bind_param('siisiisii',$ip,$start,$end,$ip,$start,$end,$ip,$start,$end);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        // A capped scan must not masquerade as a complete denominator.
        if (count($rows)>100000) continue;
        $sample=['id'=>(int)$r['id'],'untagged'=>0,'tagged'=>0,'unknownTag'=>0,'maliciousUntagged'=>0,'policyDeniedUntagged'=>0];
        foreach($rows as $flow) {
            if ($flow['observedTag']===null) {$sample['unknownTag']++;continue;}
            if ((int)$flow['observedTag']>0) {$sample['tagged']++;continue;}
            $sample['untagged']++;
            if ($flow['policyDenied']) $sample['policyDeniedUntagged']++;
            elseif ($flow['malicious']) $sample['maliciousUntagged']++;
        }
        $post=$options;$post['method']='POST';$post['header'].="Content-Type: application/json\r\n";$post['content']=json_encode($sample);
        file_get_contents($url,false,stream_context_create(['http'=>$post]));
        if (!preg_match('/^HTTP\/\S+ 200\b/',($http_response_header??[])[0]??'')) throw new RuntimeException('Observation upload failed');
    }
} finally {$db->query("SELECT RELEASE_LOCK('partner_observation_worker')");}
