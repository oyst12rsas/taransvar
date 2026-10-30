<?php
require_once __DIR__.'/../html/script/upgradeNotices.php';
function ensure($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$notice=['id'=>'security-fix','minimum_version'=>2,'roles'=>['node'],'severity'=>'security',
         'note'=>'Upgrade the protocol parser','network_impact'=>'Older senders affect other units'];
$catalog=['notices'=>[$notice]];
$a=taraSecUpgradeAssessment(['role'=>'node','software'=>['version'=>1]],$catalog);
ensure(count($a['pending'])===1,'Old version must be warned');
ensure($a['pending'][0]['network_impact']===$notice['network_impact'],'Network impact must survive');
ensure(taraSecUpgradeAssessment(['role'=>'node','software'=>['version'=>2]],$catalog)['pending']===[],'Current version clears');
ensure(taraSecUpgradeAssessment(['role'=>'gateway','software'=>['version'=>1]],$catalog)['pending']===[],'Role targeting');
ensure(count(taraSecUpgradeAssessment(['role'=>'node'],$catalog)['pending'])===1,'Legacy missing version is not current');
ensure(count(taraSecUpgradeAssessment([],$catalog)['pending'])===1,'Unknown role must not suppress notices');
$notice['minimum_version']='2';
ensure(taraSecUpgradeAssessment([],['notices'=>[$notice]])['catalog_status']==='unavailable','Reject invalid catalog');
$view=taraSecUpgradeView(['role'=>'node']);
ensure($view['color']==='red','Bundled important release marks legacy node red');
ensure(str_contains($view['message'],'not reported'),'Explain unknown version');
require_once __DIR__.'/../html/gatekeeper/func/unitsMore.php';
$issues=collectUnitIssues([],0);
ensure(count(array_filter($issues, fn($i)=>$i['label']==='TaraSec upgrade' && $i['severity']==='red'))===1,'Unit issue must explain upgrade red dot');
$view=taraSecUpgradeView(['role'=>'node','software'=>['version'=>999,'components'=>['google'=>['details'=>['approval_worker'=>'needs_configuration']]]]]);
ensure($view['color']==='yellow','Optional worker setup must remain visible');
ensure(str_contains($view['message'],'existing Google web login is independent'),'Explain separate login/worker state');
echo "Upgrade notice checks passed\n";

$partial=taraSecUpgradeView(['software'=>['version'=>0,'components'=>[
 'runtime'=>['status'=>'completed','installed_version'=>9],
 'operations'=>['status'=>'partial','installed_version'=>0,'details'=>['activation'=>'pending_model_configuration']]]]]);
ensure(str_contains($partial['message'],'operations agent activation'),'Explain incomplete composite release');
ensure(str_contains($partial['message'],'--configure-operations'),'Targeted activation recovery command missing');
ensure($partial['color']==='red','Partial deployment must not pretend the release is current');

$quota=taraSecUpgradeView(['software'=>['version'=>0,'components'=>['operations'=>[
 'status'=>'partial','details'=>['connection_check'=>'provider_quota_exhausted']]]]]);
ensure(str_contains($quota['message'],'credits/quota exhausted'),'Provider quota failure must reach Issues');
