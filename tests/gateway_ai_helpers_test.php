<?php
// Load only the pure helper definitions; the production include expects a
// deployed gateway layout and opens no connection in this test.
$source = file_get_contents(__DIR__.'/../html/gatekeeper/func/db_ai.php');
$start = strpos($source, 'function aiDecodeStoredResponse(');
$end = strpos($source, 'function db_ai(');
if ($start === false || $end === false) throw new RuntimeException('Helpers not found');
eval(substr($source, $start, $end-$start));
foreach ([[0.85,'85%'],[85,'85%'],[0,'0%'],[1,'100%'],[100,'100%'],[null,'-'],['unknown','-'],[-1,'-'],[8500,'-']] as [$input,$expected]) {
    if (aiPercent($input) !== $expected) throw new RuntimeException('Confidence regression');
}
$assessment = ['confidence'=>85,'summary'=>'Expected demo activity'];
if (aiDecodeStoredResponse(json_encode(['source'=>'gateway_local','assessment'=>$assessment])) !== $assessment)
    throw new RuntimeException('Gateway envelope regression');
if (aiDecodeStoredResponse(json_encode(['text'=>json_encode($assessment)])) !== $assessment)
    throw new RuntimeException('Legacy response regression');
echo "Confidence and assessment-envelope checks passed\n";
