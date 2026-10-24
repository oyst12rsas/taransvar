<?php
declare(strict_types=1);
/** A rollout decision is data only; it never contains executable commands. */
function upgradeRolloutEvaluate(array $policy, array &$state, array $reports, string $ip, string $offered, int $version, int $now): array {
    $generation=(int)($policy['generation']??0);
    if (($state['generation']??-1)!==$generation) $state=['generation'=>$generation];
    $tests=$policy['canaries']??[];
    $base=['allowed'=>false,'generation'=>$generation,'reason'=>'Rollout paused'];
    if (($policy['paused']??true)!==false) {unset($state['healthy_since']);$state['last_observed_at']=$now;return $base;}
    if (!is_array($tests)||!$tests) return array_replace($base,['reason'=>'No test units assigned']); // No test machines can never establish readiness.
    $candidate=$state['candidate_revision']??'';
    if ($candidate!=='') {
        $healthy=true;
        foreach ($tests as $test) {
            $r=$reports[$test]??[];$m=$r['software']['managed_upgrade']??[];
            $fresh=is_int($r['received_at']??null)&&$now-$r['received_at']>=0&&$now-$r['received_at']<=180;
            $matches=($m['revision']??'')===$candidate&&($m['generation']??-1)===$generation;
            if ($matches&&($m['status']??'')==='failed') $state['failed']=true;
            $h=$m['health']??[];
            $healthFresh=is_numeric($h['checked_at']??null)&&$now-$h['checked_at']>=0&&$now-$h['checked_at']<=180&&($h['ok']??false)===true;
            $healthy=$healthy&&$healthFresh&&$fresh&&$matches&&($m['status']??'')==='verified'&&($m['scheduler']['status']??'')!=='blocked'
                &&(string)($r['knl']??'')==='1'&&(string)($r['lnk']??'')==='1';
        }
        if (!$healthy || $now-($state['last_observed_at']??0)>180) unset($state['healthy_since']);
        if ($healthy) $state['healthy_since']??=$now;
        $state['last_observed_at']=$now;
        $soak=max(3600,(int)($policy['soak_seconds']??86400));
        if (empty($state['failed'])&&$healthy&&$now-($state['healthy_since']??$now)>=$soak) {
            $state['stable_revision']=$candidate;
            $state['stable_version']=$state['candidate_version'];
        }
    }
    $isTest=in_array($ip,$tests,true);
    if ($isTest&&preg_match('/^[a-f0-9]{40}$/D',$offered)&&$version>0
        && ($candidate===''||($state['stable_revision']??'')===$candidate) && $offered!==$candidate) {
        $state['candidate_revision']=$offered;$state['candidate_version']=$version;
        unset($state['healthy_since'],$state['failed']);$state['last_observed_at']=$now;
    }
    // A failed candidate stops the fleet as well as test retries until the operator resets it.
    if (!empty($state['failed'])) return array_replace($base,['reason'=>'Test-machine failure; rollout held until reset']);
    $target=$isTest?($state['candidate_revision']??''):($state['stable_revision']??'');
    return array_replace($base,['allowed'=>$target!=='','target_revision'=>$target,
        'cohort'=>$isTest?'canary':'fleet','reason'=>$target!==''?'Eligible pinned release':'Waiting for healthy test machines and observation period']);
}
function upgradeRolloutFiles(): string { return '/var/lib/tarasec-rollout'; }
function upgradeRolloutDecision(mysqli $db,string $ip,string $revision,int $version): array {
    $dir=upgradeRolloutFiles();
    if (!is_file($dir.'/policy.json')) return ['allowed'=>false,'reason'=>'Configure daily rollout under Gatekeeper Setup → Upgrade rollout'];
    $lock=fopen($dir.'/lock','c'); if (!$lock||!flock($lock,LOCK_EX)) throw new RuntimeException('Rollout lock unavailable');
    try {
        $policy=json_decode(file_get_contents($dir.'/policy.json'),true,512,JSON_THROW_ON_ERROR);
        $state=is_file($dir.'/state.json')?json_decode(file_get_contents($dir.'/state.json'),true,512,JSON_THROW_ON_ERROR):[];
        $reports=[];
        foreach ($policy['canaries']??[] as $test) {
            if (!filter_var($test,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) throw new RuntimeException('Invalid test address');
            $q=$db->prepare('SELECT UNIX_TIMESTAMP(partnerStatusReceived) received,status FROM partnerRouter WHERE ip=INET_ATON(?) ORDER BY partnerStatusReceived DESC LIMIT 1');
            $q->bind_param('s',$test);$q->execute();$row=$q->get_result()->fetch_assoc();$q->close();
            if ($row) { $status=json_decode((string)$row['status'],true);if(is_array($status))$reports[$test]=array_replace($status,['received_at'=>(int)$row['received']]); }
        }
        $answer=upgradeRolloutEvaluate($policy,$state,$reports,$ip,$revision,$version,time());
        $tmp=tempnam($dir,'state-');file_put_contents($tmp,json_encode($state,JSON_THROW_ON_ERROR));chmod($tmp,0660);rename($tmp,$dir.'/state.json');
        return $answer;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
