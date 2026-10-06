<?php
declare(strict_types=1);
// Ingest actual local decoy firewall logs; no app-supplied incident evidence.
function partnerObservationCollectRejections(mysqli $db,string $base): void {
    $p=proc_open(['journalctl','-k','--since','120 seconds ago','-n','500','-o','json','--no-pager'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('Cannot read receiver journal');
    fclose($pipes[0]);$raw=stream_get_contents($pipes[1]);fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);
    if(proc_close($p)!==0) throw new RuntimeException('Receiver journal unavailable: '.$error);
    $processed=0;
    foreach(explode("\n",trim($raw)) as $line) {
        $j=json_decode($line,true);$msg=(string)($j['MESSAGE']??'');
        if (!str_contains($msg,'TARASEC_HONEYPOT_REJECT:') || empty($j['__CURSOR'])) continue;
        if (!preg_match('/SRC=([0-9.]+).*DST=([0-9.]+).*PROTO=TCP.*SPT=(\d+).*DPT=(\d+)/',$msg,$m)) continue;
        $at=(int)((int)($j['__REALTIME_TIMESTAMP']??0)/1000000);
        if ($at<time()-60 || $at>time()+5) continue;
        [$all,$src,$dst,$sport,$dport]=$m;$sport=(int)$sport;$dport=(int)$dport;
        $hash=hash('sha256',$j['__CURSOR']);
        $db->begin_transaction();
        try {
            $q=$db->prepare('SELECT syslogId FROM partnerObservationLocalEvidence WHERE cursorHash=?');
            $q->bind_param('s',$hash);$q->execute();$exists=$q->get_result()->fetch_row();$q->close();
            if($exists){$db->commit();continue;}
            if($processed>=10){$db->commit();break;}
            $processed++;
            $q=$db->prepare("INSERT INTO syslog(senderIp,senderPort,hostname,tag,message,rawmessage,isSyslog,created)
                VALUES(INET_ATON(?),0,?,'kernel',?,?,1,FROM_UNIXTIME(?))");
            $host=gethostname()?:'receiver';$q->bind_param('ssssi',$dst,$host,$msg,$line,$at);$q->execute();$sid=$db->insert_id;$q->close();
            $q=$db->prepare("INSERT INTO syslogThreat(syslogId,is_attack,action,src_ip,src_port,dst_ip,dst_port,protocol,service,description,severity)
                VALUES(?,1,'deny',INET_ATON(?),?,INET_ATON(?),?,'TCP','iptables','Operator-designated decoy rejection',7)");
            $q->bind_param('isisi',$sid,$src,$sport,$dst,$dport);$q->execute();$q->close();
            $q=$db->prepare('INSERT INTO partnerObservationLocalEvidence(cursorHash,syslogId) VALUES(?,?)');
            $q->bind_param('si',$hash,$sid);$q->execute();$q->close();$db->commit();
        } catch(Throwable $e) {$db->rollback();throw $e;}
        // Correlate tag with the full observed tuple; missing capture stays unknown.
        $q=$db->prepare('SELECT tag FROM traffic WHERE ipFrom=INET_ATON(?) AND portFrom=? AND ipTo=INET_ATON(?) AND portTo=?
            AND tag IS NOT NULL AND UNIX_TIMESTAMP(COALESCE(lastSeen,created)) BETWEEN ?-5 AND ?+5
            ORDER BY COALESCE(lastSeen,created) DESC LIMIT 1');
        $q->bind_param('sisiii',$src,$sport,$dst,$dport,$at,$at);$q->execute();$packet=$q->get_result()->fetch_assoc();$q->close();
        $args=['ip'=>$src,'port'=>$sport,'severity'=>7,'code'=>'iptables','wt'=>'Operator-designated decoy rejection','observed_at'=>$at];
        if($packet) $args['observed_tag']=(int)$packet['tag'];
        $q=$db->prepare('SELECT INET_NTOA(ip) ip FROM partnerRouter WHERE (INET_ATON(?) & nettmask)=(ip & nettmask) ORDER BY BIT_COUNT(nettmask) DESC LIMIT 1');
        $q->bind_param('s',$src);$q->execute();$owner=$q->get_result()->fetch_assoc();$q->close();
        $destinations=[$base];if($owner) $destinations[]='http://'.$owner['ip'];
        $ok=true;
        foreach(array_unique($destinations) as $destination) {
            $reply=@file_get_contents($destination.'/script/report.php?'.http_build_query($args),false,
                stream_context_create(['http'=>['timeout'=>3,'follow_location'=>0,'ignore_errors'=>true]]));
            if(trim((string)$reply)!=='ok'||!preg_match('/^HTTP\/\S+ 200\b/',($http_response_header??[])[0]??'')) $ok=false;
        }
        if($ok) {
            $q=$db->prepare("UPDATE syslogThreat SET handled=b'1',handling='Receiver decoy report delivered' WHERE syslogId=?");
            $q->bind_param('i',$sid);$q->execute();$q->close();
        } // Failed reports remain pending for the ordinary cron retry path.
    }
    $db->query('DELETE FROM partnerObservationLocalEvidence WHERE created<DATE_SUB(NOW(),INTERVAL 1 DAY) LIMIT 1000');
}
