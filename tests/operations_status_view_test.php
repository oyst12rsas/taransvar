<?php
require __DIR__.'/../html/gatekeeper/func/agentStatusView.php';
require __DIR__.'/../html/gatekeeper/func/operationsStatusView.php';
function checkOperationsView($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$failure = array('checked_at'=>1791712335, 'stage'=>'decision_validation', 'detail'=>'Daily reboot limit reached');
$agent = array('status'=>'resource_attention', 'last_assessment_status'=>'blocked',
    'last_failure'=>$failure, 'resource_pressure'=>array('triggered'=>true));
$issues = operationsStatusIssues($agent);
checkOperationsView(count($issues) === 1 && strpos($issues[0], 'resource-pressure') !== false,
    'Historical failure must not be a current blocker');
$html = operationsStatusDetails($agent);
checkOperationsView(strpos($html, '2026-10-11 09:52:15 UTC') !== false, 'Failure timestamp missing');
checkOperationsView(strpos($html, 'Historical failure') !== false, 'Historical context missing');
checkOperationsView(strpos($html, 'Daily reboot limit reached') !== false, 'Failure reason missing');
$agent['status'] = 'blocked';
$agent['error_stage'] = 'model_request';
$agent['error_detail'] = 'Model <unavailable>';
$issues = operationsStatusIssues($agent);
checkOperationsView(in_array('Operations worker is currently blocked.', $issues, true), 'Current blocker missing');
checkOperationsView(in_array('Blocker at model_request: Model <unavailable>', $issues, true), 'Current reason missing');
$html = operationsStatusDetails($agent);
checkOperationsView(strpos($html, 'Model &lt;unavailable&gt;') !== false, 'Blocker must be HTML escaped');
unset($agent['error_detail']);
checkOperationsView(strpos(implode(' ', operationsStatusIssues($agent)), 'not included') !== false,
    'Older snapshot must say its reason is unavailable');
$agent['status'] = 'model_stalled';
$agent['decision_feedback'] = 'No reboot needed; inspect <logs>';
checkOperationsView(strpos(implode(' ', operationsStatusIssues($agent)), 'Decision needs correction:') !== false,
    'Exhausted correction must give an actionable reason');
checkOperationsView(strpos(operationsStatusDetails($agent), 'inspect &lt;logs&gt;') !== false,
    'Decision feedback must be escaped');
echo "Operations status view checks passed.\n";

require __DIR__.'/../html/gatekeeper/func/unitsMore.php';
foreach (array(70 => null, 77 => null, 84 => null, 85 => 'yellow', 89 => 'yellow', 90 => 'red') as $percent => $severity) {
    $issues = collectUnitIssues(array('df' => '100G '.$percent.'G '.(100-$percent).'G'), 0);
    checkOperationsView(count($issues) === ($severity === null ? 0 : 1), 'Disk warning boundary mismatch');
    if ($severity !== null) {
        checkOperationsView($issues[0]['severity'] === $severity, 'Disk dot severity mismatch');
        checkOperationsView(strpos($issues[0]['message'], $percent.'% of disk used') !== false, 'Disk usage explanation missing');
    }
}
checkOperationsView(diskStatusPercent('11G 8.5G 2.5G') === 77, 'Decimal disk sizes mismatch');

$custom = array('df' => '100G 77G 23G', 'operationsAgent' => array('status' => 'monitoring', 'resource_pressure' => array('disk_threshold_percent' => 75)));
checkOperationsView(diskStatusThreshold($custom) === 75.0, 'Configured threshold missing');
checkOperationsView(collectUnitIssues($custom, 0)[0]['label'] === 'Disk usage', 'Configured maintenance warning missing');
