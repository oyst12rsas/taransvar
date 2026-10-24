<?php
require __DIR__.'/../html/script/upgradeRolloutLib.php';
require __DIR__.'/../html/script/upgradeNotices.php';
function verify($x,$message){if(!$x)throw new Exception($message);}
$p=['paused'=>false,'canaries'=>['100.68.176.110'],'generation'=>1,'soak_seconds'=>3600];$s=[];$sha=str_repeat('a',40);$new=str_repeat('b',40);$now=10000;
$a=upgradeRolloutEvaluate($p,$s,[],'100.68.51.247',$sha,8,$now);verify(!$a['allowed'],'Fleet cannot nominate or skip test stage');
$a=upgradeRolloutEvaluate($p,$s,[],'100.68.176.110',$sha,8,$now);verify($a['allowed']&&$a['cohort']==='canary','Designated test gets candidate');
$r=['100.68.176.110'=>['received_at'=>$now,'knl'=>'1','lnk'=>'1','software'=>['managed_upgrade'=>['revision'=>$sha,'generation'=>1,'status'=>'verified','health'=>['ok'=>true,'checked_at'=>$now]]]]];
for($t=$now;$t<=$now+3600;$t+=60){$r['100.68.176.110']['received_at']=$t;$r['100.68.176.110']['software']['managed_upgrade']['health']=['ok'=>true,'checked_at'=>$t];$a=upgradeRolloutEvaluate($p,$s,$r,'100.68.51.247',$sha,8,$t);if($t<$now+3600)verify(!$a['allowed'],'No early promotion');}
verify($a['allowed']&&$a['target_revision']===$sha,'Healthy exact revision promotes after soak');
$a=upgradeRolloutEvaluate($p,$s,$r,'100.68.176.110',$new,9,$now+3601);verify($a['target_revision']===$new,'Next candidate is independent');
$r['100.68.176.110']['software']['managed_upgrade']=['revision'=>$new,'generation'=>1,'status'=>'failed'];$r['100.68.176.110']['received_at']=$now+3602;
$a=upgradeRolloutEvaluate($p,$s,$r,'100.68.51.247',$new,9,$now+3602);verify(!$a['allowed']&&!empty($s['failed']),'Failure holds fleet');
$r['100.68.176.110']['software']['managed_upgrade']['status']='verified';
$a=upgradeRolloutEvaluate($p,$s,$r,'100.68.176.110',$new,9,$now+3603);verify(!$a['allowed'],'Failure is latched until reset');
$p['generation']=2;$a=upgradeRolloutEvaluate($p,$s,$r,'100.68.176.110',$new,9,$now+3604);verify($a['allowed'],'Explicit reset allows testing again');
// Evidence for another generation cannot promote a candidate.
for($t=$now+3604;$t<=$now+7604;$t+=60){$r['100.68.176.110']['received_at']=$t;$r['100.68.176.110']['software']['managed_upgrade']['health']=['ok'=>true,'checked_at'=>$t];$a=upgradeRolloutEvaluate($p,$s,$r,'100.68.51.247',$new,9,$t);}verify(!$a['allowed'],'Wrong-generation receipts are not proof');
// Fresh minute reports are insufficient without fresh post-deployment health checks.
$p['generation']=3;$s=[];$r['100.68.176.110']['software']['managed_upgrade']=['revision'=>$new,'generation'=>3,'status'=>'verified','health'=>['ok'=>true,'checked_at'=>1]];
upgradeRolloutEvaluate($p,$s,$r,'100.68.176.110',$new,9,$now+8000);
for($t=$now+8000;$t<=$now+12000;$t+=60){$r['100.68.176.110']['received_at']=$t;$a=upgradeRolloutEvaluate($p,$s,$r,'100.68.51.247',$new,9,$t);}verify(!$a['allowed'],'Stale health cannot promote');
$p['paused']=true;$a=upgradeRolloutEvaluate($p,$s,$r,'100.68.176.110',$new,9,$now+7700);verify(!$a['allowed'],'Pause blocks test dispatch too');
$view=taraSecUpgradeView(['software'=>['version'=>999,'managed_upgrade'=>['status'=>'verified','withheld_tasks'=>[['component'=>'tarakernel','state'=>'requires_local_testing']]]]]);
verify($view['color']==='red'&&str_contains($view['message'],'requires_local_testing'),'Skipped tasks have a red dot');
echo "Staged rollout and task visibility checks passed\n";
