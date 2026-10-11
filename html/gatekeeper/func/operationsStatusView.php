<?php
function operationsSecurityFindings($agent)
{
    if (!is_array($agent)) return array();
    $labels = array(
        'security_worker_missing' => 'Security/approval worker is not installed.',
        'security_config_missing' => 'Security/approval configuration is missing.',
        'security_snapshot_missing' => 'Security assessment snapshot is missing.',
        'security_enrollment_missing_or_unverified' => 'Node-specific security enrollment is missing or unverified.',
        'tarasec-agent-approvals.timer_not_installed' => 'Security/approval timer is not installed.'
    );
    return array_intersect_key($labels, array_flip(array_filter(
        $agent['deployment_findings'] ?? array(), 'is_string')));
}

function operationsIssueHtml($message, $agent)
{
    $html = agentStatusEscape($message);
    if ($message === 'Security agent deployment is incomplete.') {
        $html .= '<details><summary>Missing components</summary><ul>';
        foreach (operationsSecurityFindings($agent) as $detail)
            $html .= '<li>'.agentStatusEscape($detail).'</li>';
        $html .= '</ul></details>';
    }
    return $html;
}

// Report heartbeat freshness separately from the most recent AI assessment.
function operationsStatusIssues($agent)
{
    if (!is_array($agent)) return array('Operations report is unavailable or invalid.');
    $issues = array();
    $status = (string)($agent['status'] ?? 'unknown');
    if (in_array($status, array('stale', 'unavailable', 'unknown'), true))
        $issues[] = 'Operations report is '.$status.'.';
    elseif (isset($agent['age_seconds']) && (int)$agent['age_seconds'] > 180)
        $issues[] = 'Operations heartbeat is stale.';
    if (!empty($agent['resource_pressure']['triggered']))
        $issues[] = 'Configured resource-pressure threshold reached.';
    $messages = array(
        'model_budget_deferred' => 'AI assessment deferred: model-call budget reached. Local monitoring continues.',
        'blocked' => 'Operations worker is currently blocked.',
        'command_failed' => 'Current maintenance command failed.',
        'model_stalled' => 'Last AI assessment did not advance the task.'
    );
    if (isset($messages[$status])) $issues[] = $messages[$status];
    if ($status === 'blocked') {
        $stage = $agent['error_stage'] ?? null;
        $detail = $agent['error_detail'] ?? null;
        if (is_string($detail) && $detail !== '')
            $issues[] = 'Blocker'.(is_string($stage) && $stage !== '' ? ' at '.$stage : '').': '.$detail;
        else $issues[] = 'Blocker reason was not included in this report; inspect the node worker journal.';
    }
    if (is_array($agent['reboot'] ?? null)) {
        $reboot = $agent['reboot'];
        if (!empty($reboot['required']) || in_array($reboot['status'] ?? '', array('verification_pending','failed','awaiting_boot'), true)) {
            $issues[] = 'Reboot maintenance: '.str_replace('_', ' ', (string)($reboot['status'] ?? 'unknown')).'.';
            foreach (($reboot['blockers'] ?? array()) as $blocker)
                if (is_string($blocker)) $issues[] = 'Reboot: '.str_replace('_', ' ', $blocker).'.';
        }
    }
    $security = operationsSecurityFindings($agent);
    if ($security) $issues[] = 'Security agent deployment is incomplete.';
    foreach (($agent['deployment_summary'] ?? array()) as $message)
        if (is_string($message) && $message !== '' && !in_array($message, $security, true)) $issues[] = $message;
    if (empty($agent['deployment_summary'])) {
        foreach (($agent['deployment_findings'] ?? array()) as $finding)
            if (is_string($finding) && !isset($security[$finding])) $issues[] = str_replace('_', ' ', $finding);
    }
    return array_values(array_unique($issues));
}

function operationsStatusDetails($agent)
{
    if (!is_array($agent)) return 'Operations report is unavailable or invalid.';
    $rows = array(
        'Maintenance mode' => $agent['mode'] ?? 'Not reported',
        'Worker status' => $agent['status'] ?? 'unknown',
        'Last assessment result' => $agent['last_assessment_status'] ?? 'unknown'
    );
    if (isset($agent['age_seconds'])) $rows['Report age'] = (int)$agent['age_seconds'].' seconds';
    foreach (array('worker_heartbeat_at' => 'Worker heartbeat', 'assessment_checked_at' => 'Last AI assessment',
                   'deployment_checked_at' => 'Deployment findings checked') as $field => $label) {
        $timestamp = $agent[$field] ?? null;
        $rows[$label] = is_numeric($timestamp) && $timestamp > 0
            ? gmdate('Y-m-d H:i:s', (int)$timestamp).' UTC'
            : 'Not reported; findings may reflect an earlier assessment';
    }
    $failure = $agent['last_failure'] ?? null;
    if (is_array($failure)) {
        $stamp = $failure['checked_at'] ?? null;
        $rows['Recorded failure time'] = is_numeric($stamp) && $stamp > 0
            ? gmdate('Y-m-d H:i:s', (int)$stamp).' UTC' : 'Not reported';
        $rows['Recorded failure stage'] = $failure['stage'] ?? 'Not reported';
        $rows['Recorded failure reason'] = $failure['detail'] ?? 'Not reported';
        if (($agent['status'] ?? '') !== 'blocked')
            $rows['Failure context'] = 'Historical failure; the current worker status is shown above.';
    }
    if (is_array($agent['reboot'] ?? null)) {
        $rows['Reboot maintenance'] = str_replace('_', ' ', (string)($agent['reboot']['status'] ?? 'unknown'));
        if (!empty($agent['reboot']['target_kernel'])) $rows['Reboot target kernel'] = $agent['reboot']['target_kernel'];
    }
    $html = '<table>';
    foreach ($rows as $label => $value)
        $html .= '<tr><td>'.agentStatusEscape($label).'</td><td>'.agentStatusEscape($value).'</td></tr>';
    $html .= '</table>';
    $issues = operationsStatusIssues($agent);
    if ($issues) {
        $html .= '<p>Current reported issues:</p><ul>';
        foreach ($issues as $issue) $html .= '<li>'.operationsIssueHtml($issue, $agent).'</li>';
        $html .= '</ul>';
    }
    return $html;
}

