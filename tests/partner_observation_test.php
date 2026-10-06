<?php
require_once __DIR__.'/../html/script/partnerObservationLib.php';
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);echo "PASS: $message\n";}
$c=['minimum_connections'=>20,'minimum_malicious'=>5,'minimum_receivers'=>2,'alarm_ratio'=>0.5];
$a=['untagged'=>10,'maliciousUntagged'=>8];$b=['untagged'=>10,'maliciousUntagged'=>7];
check(partnerObservationDecision([$a,$b],$c)['status']==='alarm','Independent receivers with high malicious ratio alarm');
check(partnerObservationDecision([$a],$c)['status']!=='alarm','Single receiver cannot alarm');
check(partnerObservationDecision([['untagged'=>100,'maliciousUntagged'=>5],$b],$c)['status']!=='alarm','Clean traffic dilutes ratio');
check(partnerObservationDecision([['untagged'=>10,'maliciousUntagged'=>0],['untagged'=>10,'maliciousUntagged'=>0]],$c)['status']!=='alarm','Policy-only rejections do not alarm');
check(partnerObservationDecision([],$c)['ratio']===null,'Missing observations remain unknown');
try {partnerObservationDecision([['untagged'=>1,'maliciousUntagged'=>2]],$c);throw new RuntimeException('Invalid counts accepted');}
catch(InvalidArgumentException $e) {echo "PASS: impossible counts rejected\n";}
