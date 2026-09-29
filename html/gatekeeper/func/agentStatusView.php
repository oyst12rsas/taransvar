<?php
function agentStatusView($agent)
{
    if ($agent === 'report disabled')
        return array('color' => 'yellow', 'label' => 'Report disabled', 'reason' => 'Agent status reporting is disabled.', 'issue' => false);
    if (!is_array($agent))
        return array('color' => 'yellow', 'label' => 'Status unavailable', 'reason' => 'Agent status is unavailable.', 'issue' => true);

    $state = (string)($agent['status'] ?? 'unknown');
    $ssh = is_array($agent['ssh_protection'] ?? null) ? $agent['ssh_protection'] : array();
    $age = isset($agent['age_seconds']) ? (int)$agent['age_seconds'] : null;
    $pending = $agent['pending_operator_messages'] ?? array();
    $messages = is_array($pending) ? array_values(array_filter($pending, 'is_string')) : array();
    if (!empty($agent['ssh_attack_ongoing']))
        return array('color' => 'red', 'label' => 'Attack observed', 'reason' => 'Agent reports an ongoing SSH attack.', 'issue' => true);
    if (!empty($ssh['password_alone_possible']))
        return array('color' => 'yellow', 'label' => 'Needs review', 'reason' => 'SSH password alone may authenticate; a public key is not required.', 'issue' => true);
    if ($age !== null && $age > 180)
        return array('color' => 'yellow', 'label' => 'Stale assessment', 'reason' => 'Agent assessment is '.$age.' seconds old.', 'issue' => true);
    if ($state !== 'ok' || count($messages))
        return array('color' => 'yellow', 'label' => 'Needs review', 'reason' => count($messages) ? implode('; ', $messages) : 'Agent status: '.$state.'.', 'issue' => true);
    return array('color' => 'green', 'label' => 'Healthy', 'reason' => 'Agent checks report no pending findings.', 'issue' => false);
}

function agentStatusEscape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function agentStatusDetails($agent)
{
    $view = agentStatusView($agent);
    $rows = array(array('Assessment', '<span style="color:'.$view['color'].'">&#9679;</span> '.agentStatusEscape($view['label']).' — '.agentStatusEscape($view['reason'])));
    if (!is_array($agent)) return '<table>'.agentStatusRows($rows).'</table>';
    $ssh = is_array($agent['ssh_protection'] ?? null) ? $agent['ssh_protection'] : array();
    $forwarding = is_array($agent['forwarding'] ?? null) ? $agent['forwarding'] : array();
    $rows[] = array('Mode', agentStatusEscape($agent['mode'] ?? 'unknown'));
    $rows[] = array('Overall status', agentStatusEscape($agent['status'] ?? 'unknown'));
    $rows[] = array('SSH protection', agentStatusEscape($ssh['status'] ?? 'unknown'));
    $rows[] = array('SSH authentication', !empty($ssh['password_alone_possible'])
        ? 'Password alone may authenticate; public key not required'
        : 'Password-only authentication not reported');
    $rows[] = array('Authentication methods', agentStatusEscape($ssh['authentication_methods'] ?? 'unknown'));
    $rows[] = array('Firewall checks', 'IPv4: '.(empty($ssh['ipv4_firewall_checked']) ? 'not checked' : 'checked').
        '; IPv6: '.(empty($ssh['ipv6_firewall_checked']) ? 'not checked' : 'checked'));
    $rows[] = array('Approval service', agentStatusEscape($agent['approval_service'] ?? 'unknown'));
    $rows[] = array('Assessment age', isset($agent['age_seconds']) ? (int)$agent['age_seconds'].' seconds' : 'unknown');
    $rows[] = array('Ongoing SSH attack', !empty($agent['ssh_attack_ongoing']) ? 'Reported' : 'Not reported');
    $rows[] = array('Recovery console verified', !empty($agent['recovery_console_verified']) ? 'Yes' : 'No');
    if (array_key_exists('is_gateway', $forwarding))
        $rows[] = array('Agent gateway role', $forwarding['is_gateway'] ? 'Gateway' : 'Node');
    foreach (array('pending_operator_messages' => 'For operator', 'actions' => 'Actions', 'findings' => 'SSH findings') as $field => $label) {
        $values = $field === 'findings' ? ($ssh[$field] ?? array()) : ($agent[$field] ?? array());
        if (is_array($values) && count($values))
            $rows[] = array($label, agentStatusEscape(json_encode($values, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)));
    }
    return '<table>'.agentStatusRows($rows).'</table>';
}

function agentStatusRows($rows)
{
    $html = '';
    foreach ($rows as $row)
        $html .= '<tr><td>'.agentStatusEscape($row[0]).'</td><td>'.$row[1].'</td></tr>';
    return $html;
}
