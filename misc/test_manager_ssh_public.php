<?php
require __DIR__ . '/../html/script/managerSshCommon.php';
$public = managerSshClientState([
    'enabled'=>true,'port'=>5822,'sshPort'=>5822,'remainingSeconds'=>123,
    'private'=>['port'=>5822],'token'=>'must-not-leak','listener'=>true,
]);
if (str_contains(json_encode($public), '5822') || isset($public['port']) ||
    isset($public['sshPort']) || isset($public['private']) || isset($public['token'])) {
    throw new RuntimeException('Private SSH details leaked');
}
if ($public['mfaRequired'] !== true || $public['approvalUrl'] !== 'https://tarasec.org/ops/agent/' ||
    $public['remainingSeconds'] !== 123) throw new RuntimeException('Missing MFA handoff');
if (managerSshClientState([])['enabled'] !== false) throw new RuntimeException('Missing status enabled access');
echo "SSH public-state privacy and MFA handoff checks passed\n";
