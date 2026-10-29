<?php
require __DIR__.'/../html/script/managerSshApprovalTarget.php';
$good=['version'=>1,'server_ref'=>str_repeat('a',64),'approval_url'=>'https://example.test/ops/agent/'];
if(managerSshApprovalTargetRead('/nonexistent-test-target')!==null)throw new RuntimeException('Missing target fallback');
$out=managerSshApprovalTargetValidate($good+['port'=>5822,'token'=>'private']);
if(isset($out['port'])||isset($out['token']))throw new RuntimeException('Private metadata leaked');
foreach([
    ['server_ref'=>'wt-qw1'],['server_ref'=>''],['approval_url'=>'http://example.test/ops/agent/'],
    ['approval_url'=>'https://user:secret@example.test/ops/agent/'],
    ['approval_url'=>'https://example.test/ops/agent/?server=other'],
    ['approval_url'=>'https://example.test/ops/agent/#other'],
]as$bad){
    try{managerSshApprovalTargetValidate(array_replace($good,$bad));}
    catch(RuntimeException $e){continue;}
    throw new RuntimeException('Unsafe target accepted');
}
echo "SSH target identity, HTTPS and no-fallback checks passed\n";
