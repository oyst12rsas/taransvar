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
