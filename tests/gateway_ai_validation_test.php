<?php
require __DIR__.'/../html/script/gatewayAiValidation.php';
$question = "RECORDS: local stable unit summaries\n".json_encode(['owner_id'=>'6','unit_id'=>'20'])."\nGLOBAL TARASEC CONTEXT\n".json_encode(['owner_id'=>9,'unit_id'=>30]);
foreach ([
    [[], true],
    [[['owner_id'=>6,'unit_id'=>20]], true],
    [[['owner_id'=>'6','unit_id'=>'20']], true],
    [[['owner_id'=>'owner_id_example','unit_id'=>'unit_id_example']], false],
    [[['owner_id'=>6,'unit_id'=>21]], false],
    [[['owner_id'=>9,'unit_id'=>30]], false],
    [[['owner_id'=>6,'unit_id'=>'100.68.22.33']], false],
    [[['owner_id'=>6]], false],
    [[null], false],
] as [$units,$expected]) {
    if (gatewayAiUnitsSupported(['unit_assessments'=>$units], $question) !== $expected) throw new RuntimeException('Unit identity validation regression');
}
echo "Gateway identity validation checks passed\n";
