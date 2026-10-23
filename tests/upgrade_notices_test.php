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
echo "Upgrade notice checks passed\n";
