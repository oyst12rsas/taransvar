<?php
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
    $assessment = (string)($agent['last_assessment_status'] ?? $status);
    $messages = array(
        'model_budget_deferred' => 'AI assessment deferred: model-call budget reached. Local monitoring continues.',
        'blocked' => 'Last AI assessment was blocked.',
        'command_failed' => 'Last maintenance command failed.',
        'model_stalled' => 'Last AI assessment did not advance the task.'
    );
    if (isset($messages[$assessment])) $issues[] = $messages[$assessment];
    elseif (isset($messages[$status])) $issues[] = $messages[$status];
    foreach (($agent['deployment_summary'] ?? array()) as $message)
        if (is_string($message) && $message !== '') $issues[] = $message;
    if (empty($agent['deployment_summary'])) {
        foreach (($agent['deployment_findings'] ?? array()) as $finding)
            if (is_string($finding)) $issues[] = str_replace('_', ' ', $finding);
    }
    return array_values(array_unique($issues));
}

function operationsStatusDetails($agent)
{
    if (!is_array($agent)) return 'Operations report is unavailable or invalid.';
    $rows = array(
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
    $html = '<table>';
    foreach ($rows as $label => $value)
        $html .= '<tr><td>'.agentStatusEscape($label).'</td><td>'.agentStatusEscape($value).'</td></tr>';
    $html .= '</table>';
    $issues = operationsStatusIssues($agent);
    if ($issues) {
        $html .= '<p>Outstanding findings from the reported assessment:</p><ul>';
        foreach ($issues as $issue) $html .= '<li>'.agentStatusEscape($issue).'</li>';
        $html .= '</ul>';
    }
    return $html;
}
