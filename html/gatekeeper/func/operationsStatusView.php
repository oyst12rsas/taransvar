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
    if ($status === 'model_stalled' && is_string($agent['decision_feedback'] ?? null))
        $issues[] = 'Decision needs correction: '.$agent['decision_feedback'];
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

function operationsStatusDetails($agent, bool $includeIssues = true)
{
    if (!is_array($agent)) return 'Operations report is unavailable or invalid.';
    $rows = array(
        'Maintenance mode' => $agent['mode'] ?? 'Not reported',
        'Worker status' => $agent['status'] ?? 'unknown',
        'Last assessment result' => $agent['last_assessment_status'] ?? 'unknown'
    );
    if (is_string($agent['decision_feedback'] ?? null) && $agent['decision_feedback'] !== '')
        $rows['Last decision correction'] = $agent['decision_feedback'];
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
    if ($includeIssues) $html .= operationsMaintenanceDetails($agent);

    $issues = operationsStatusIssues($agent);
    if ($issues) {
        $html .= '<p>Current reported issues:</p><ul>';
        foreach ($issues as $issue) $html .= '<li>'.operationsIssueHtml($issue, $agent).'</li>';
        $html .= '</ul>';
    }
    return $html;
}



function operationsMaintenanceDetails($agent): string
{
    if (!is_array($agent)) return '<p>Operations report is unavailable.</p>';
    $html = '<p>Worker: '.agentStatusEscape($agent['status'] ?? 'unknown').'. Last assessment: '.agentStatusEscape($agent['last_assessment_status'] ?? 'unknown').'.</p>';
    $reboot = $agent['reboot'] ?? null;
    if (is_array($reboot)) {
        $html .= '<p>Reboot: '.agentStatusEscape(str_replace('_',' ',(string)($reboot['status'] ?? 'unknown'))).'</p>';
        if (!empty($reboot['target_kernel'])) $html .= '<p>Target kernel: '.agentStatusEscape($reboot['target_kernel']).'</p>';
        foreach (($reboot['blockers'] ?? []) as $blocker) {
            $label = $blocker === 'interactive_sessions_active_or_unknown'
                ? 'Interactive sessions are active, or their absence could not be verified. Reboot is deferred.'
                : str_replace('_',' ',(string)$blocker);
            $html .= '<p>Reboot blocker: '.agentStatusEscape($label).'</p>';
        }
    }
    foreach (array_slice(is_array($agent['issues'] ?? null) ? $agent['issues'] : [], 0, 64) as $item) {
        if (!is_array($item)) continue;
        $html .= '<details><summary>'.agentStatusEscape($item['description'] ?? 'Issue').' — '.agentStatusEscape($item['status'] ?? 'unknown').'</summary>';
        foreach (['discovered_at'=>'Discovered', 'checked_at'=>'Last update', 'next_action_at'=>'Next action', 'solved_at'=>'Solved'] as $key=>$label)
            if (is_numeric($item[$key] ?? null)) $html .= '<p>'.$label.': '.gmdate('Y-m-d H:i:s', (int)$item[$key]).' UTC</p>';
        if (!empty($item['status_explanation'])) $html .= '<p>'.agentStatusEscape($item['status_explanation']).'</p>';
        if (!empty($item['verification_needed'])) $html .= '<p>Required check: '.agentStatusEscape($item['verification_needed']).'</p>';
        if (!empty($item['blocker'])) $html .= '<p>Blocker: '.agentStatusEscape($item['blocker']).'</p>';
        $html .= '<p>Verification: '.agentStatusEscape($item['verification'] ?? 'unknown').'</p><ul>';
        foreach (array_slice($item['history'] ?? [], 0, 16) as $event) {
            $detail = $event['detail'] ?? null;
            $text = is_string($detail) ? $detail : implode('; ', array_filter([
                isset($detail['worker_status']) ? 'Worker: '.str_replace('_',' ',$detail['worker_status']) : '',
                isset($detail['action']) ? 'Action: '.$detail['action'] : '',
                isset($detail['exit_code']) ? 'Exit code: '.$detail['exit_code'] : '',
                !empty($detail['blocker']) ? 'Blocker: '.$detail['blocker'] : '',
                !empty($detail['next_check']) ? 'Next check: '.$detail['next_check'] : ''
            ]));
            $stamp = is_numeric($event['at'] ?? null) ? gmdate('Y-m-d H:i:s',(int)$event['at']).' UTC — ' : '';
            $html .= '<li>'.agentStatusEscape($stamp.($event['kind'] ?? '').': '.$text).'</li>';
        }
        $html .= '</ul><p>Complete received history is retained in issueReport. Command arguments remain on the node.</p></details>';
    }

    if (empty($agent['issues'])) $html .= '<p>No issues in this list.</p>';
    return $html;
}

function operationsStoredIssues($conn, string $ip, bool $includeSolved = true, int $offset = 0): array
{
    // Additive rollout: old databases continue to show the ordinary status page.
    $version = $conn->query('SELECT dbVersion FROM setup LIMIT 1')->fetch_assoc();
    if ((int)($version['dbVersion'] ?? 0) < 100) return [];
    $filter = $includeSolved ? '' : ' AND status<>"solved"';
    $stmt = $conn->prepare('SELECT * FROM issue WHERE nodeIp=INET_ATON(?)'.$filter.' ORDER BY status="solved", discoveredAt, issueId LIMIT 65 OFFSET ?');
    $offset = max(0, $offset);
    $stmt->bind_param('si', $ip, $offset); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $items=[];
    foreach ($rows as $row) {
        $id=(int)$row['issueId'];
        $stmt=$conn->prepare('SELECT reportedAt,kind,detail FROM issueReport WHERE issueId=? ORDER BY reportId DESC LIMIT 16');
        $stmt->bind_param('i',$id); $stmt->execute(); $events=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        $items[]=['key'=>$row['issueKey'],'description'=>$row['description'],'status'=>$row['status'],
            'discovered_at'=>$row['discoveredAt'],'checked_at'=>$row['checkedAt'],'next_action_at'=>$row['nextActionAt'],
            'solved_at'=>$row['solvedAt'],'verification'=>$row['verification'],'blocker'=>$row['blocker'],
            'history'=>array_map(fn($event)=>['at'=>$event['reportedAt'],'kind'=>$event['kind'],'detail'=>json_decode($event['detail'],true)],array_reverse($events))];
    }
    return $items;
}
