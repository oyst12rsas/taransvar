<?php
function unitActivityOverview($conn)
{
    if (!isAdmin()) return '';
    $result = $conn->query("SELECT R.routerId, P.name, INET_NTOA(R.ip) AS ip, R.status, R.partnerStatusReceived, TIMESTAMPDIFF(SECOND,R.partnerStatusReceived,NOW()) AS age FROM partnerRouter R JOIN partner P ON P.partnerId=R.partnerId ORDER BY P.name,R.routerId");
    $groups = ['Active client traffic' => '', 'Missing reports (over 200 seconds or never received)' => '', 'Other reporting units' => ''];
    $escape = function($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
    while ($row = $result->fetch_assoc()) {
        $missing = $row['age'] === null || (int)$row['age'] > 200;
        $fresh = !$missing && (int)$row['age'] <= 130 && (int)$row['age'] >= 0;
        $status = json_decode($row['status'] ?? '', true) ?: [];
        $activity = $status['unitActivity'] ?? [];
        $clients = $activity['clients'] ?? null;
        $ssh = $activity['ssh'] ?? null;
        $group = $missing ? 'Missing reports (over 200 seconds or never received)' : ($fresh && is_int($clients) && $clients > 0 ? 'Active client traffic' : 'Other reporting units');
        $sshText = 'Unknown';
        if (is_array($ssh)) {
            $items = [];
            foreach ($ssh as $session) {
                $items[] = ($session['user'] ?? '?').' ('.($session['terminal'] ?? '?').')';
            }
            $sshText = count($ssh).(count($ssh) ? ': '.implode(', ', $items) : '');
        }
        $suffix = !$fresh ? ' (stale)' : '';
        $groups[$group] .= '<tr><td><a href="index.php?f=unitsMore&amp;id='.(int)$row['routerId'].'">'.$escape($row['name']).'</a></td><td>'.$escape($row['ip']).'</td><td>'.$escape($row['partnerStatusReceived'] ?? 'Never').' / '.$escape($row['age'] === null ? 'unknown' : $row['age'].'s').'</td><td>'.$escape(($clients === null ? 'Unknown' : $clients).$suffix).'</td><td>'.$escape($sshText.$suffix).'</td></tr>';
    }
    $result->free();
    $html = '<p>Client activity: routed connection packet changes observed in the last five minutes; local server traffic is excluded. SSH: authenticated sessions, including idle and non-terminal sessions. Unknown means unavailable or awaiting a baseline.</p>';
    foreach ($groups as $title => $rows) {
        $html .= '<h3>'.$escape($title).'</h3>'.($rows ? '<table><tr><th>Unit</th><th>IP</th><th>Last report / age</th><th>Active clients</th><th>SSH logins</th></tr>'.$rows.'</table>' : '<p>None.</p>');
    }
    return $html;
}
