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
