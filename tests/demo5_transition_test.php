<?php
require_once __DIR__.'/../html/script/partnerIncidentLib.php';
function expect(string $want,string $got,string $case):void {if($want!==$got)throw new RuntimeException("$case: expected $want, got $got");}
$s=['createdEpoch'=>100,'notifiedEpoch'=>null];
expect('reported',partnerIncidentTransition($s,0,110,110,30),'first untagged attack');
expect('unknown',partnerIncidentTransition($s,null,110,110,30),'no evidence is not zero');
expect('tagged',partnerIncidentTransition($s,42,110,110,30),'healthy observed tag');
expect('ignore',partnerIncidentTransition($s,0,99,110,30),'old report cannot start exercise');
expect('ignore',partnerIncidentTransition($s,0,120,110,30),'future evidence rejected');
$s['notifiedEpoch']=120;
expect('reported',partnerIncidentTransition($s,0,149,149,30),'notification grace');
expect('restrict',partnerIncidentTransition($s,0,150,150,30),'repeated zero after grace');
expect('unknown',partnerIncidentTransition($s,null,150,150,30),'unknown after grace still unknown');
expect('tagged',partnerIncidentTransition($s,1,150,150,30),'tagged after notification');
echo "Demo 5 transition checks passed\n";
