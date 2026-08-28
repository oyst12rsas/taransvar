<?php

function liveAuditHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function liveAuditTable($title, $rows)
{
    $html = '<h3>'.liveAuditHtml($title).'</h3><table>';
    foreach ($rows as $key => $value)
        $html .= '<tr><th>'.liveAuditHtml($key).'</th><td>'.liveAuditHtml($value).'</td></tr>';
    $html .= '</table>';
    return $html;
}

function liveAudit()
{
    $nInfectionId = isset($_GET['infectionId']) ? intval($_GET['infectionId']) : 0;
    $nReportId = isset($_GET['reportId']) ? intval($_GET['reportId']) : 0;
    $conn = getConnection();

    $report = null;
    if ($nReportId > 0)
    {
        $sql = "SELECT reportId, created, inet_ntoa(ip) AS ip, port, inet_ntoa(partnerIp) AS partnerIp, partnerPort, status, handledTime, lastSeen, sentGlobalDB, ipOwnerId, severity, botnetId, partnerId, infectionId, remoteUnitId, unitId, why FROM hackReport WHERE reportId = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $nReportId);
        $stmt->execute();
        $result = $stmt->get_result();
        $report = $result ? $result->fetch_assoc() : null;
        if ($report && !$nInfectionId && !empty($report['infectionId']))
            $nInfectionId = intval($report['infectionId']);
        $stmt->close();
    }

    $infection = null;
    if ($nInfectionId > 0)
    {
        $sql = "SELECT infectionId, inet_ntoa(ip) AS ip, inet_ntoa(nettmask) AS nettmask, status, CAST(active AS UNSIGNED) AS active, inserted, lastSeen, unitId, severity, botnetId, infoSharePartner, infoSharePartners FROM internalInfections WHERE infectionId = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $nInfectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $infection = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }

    $statusParts = array('Updated '.date('H:i:s'));
    if ($nInfectionId)
        $statusParts[] = 'infectionId '.$nInfectionId;
    if ($nReportId)
        $statusParts[] = 'reportId '.$nReportId;
    CXmlCommand::setInnerHTML('liveAuditStatus', '', liveAuditHtml(implode(' · ', $statusParts)));

    if ($infection)
    {
        $state = ($infection['active'] ? 'ACTIVE / INFECTED' : 'INACTIVE / CLEAN');
        $html = liveAuditTable('Current internalInfections state', array(
            'infectionId' => $infection['infectionId'],
            'Current state' => $state,
            'Status' => $infection['status'],
            'Severity' => $infection['severity'],
            'IP' => $infection['ip'],
            'Netmask' => $infection['nettmask'],
            'Unit ID' => $infection['unitId'],
            'Botnet' => $infection['botnetId'],
            'Inserted' => $infection['inserted'],
            'Last seen' => $infection['lastSeen'],
            'Private share' => $infection['infoSharePartner'],
            'Public share' => $infection['infoSharePartners']
        ));
        CXmlCommand::setInnerHTML('liveAuditCurrent', '', $html);
    }
    else
        CXmlCommand::setInnerHTML('liveAuditCurrent', '', $nInfectionId ? '<p>No internalInfections record found for this ID.</p>' : '<p>Select an internalInfections record, or select a hackReport that points to one.</p>');

    if ($report)
    {
        $html = liveAuditTable('Selected hackReport evidence', array(
            'reportId' => $report['reportId'],
            'Status' => $report['status'],
            'Severity' => $report['severity'],
            'Why' => $report['why'],
            'Source' => trim($report['ip'].':'.$report['port'], ':'),
            'Partner' => trim($report['partnerIp'].':'.$report['partnerPort'], ':'),
            'Unit ID' => $report['unitId'],
            'Remote unit ID' => $report['remoteUnitId'],
            'infectionId' => $report['infectionId'],
            'Created' => $report['created'],
            'Last seen' => $report['lastSeen'],
            'Handled' => $report['handledTime'],
            'Sent global DB' => $report['sentGlobalDB']
        ));
        CXmlCommand::setInnerHTML('liveAuditReport', '', $html);
    }
    else
        CXmlCommand::setInnerHTML('liveAuditReport', '', $nReportId ? '<p>No hackReport found for this ID.</p>' : '<p>No individual hackReport selected.</p>');

    $relatedHtml = '<h3>Evidence timeline</h3><table><tr><th>ID</th><th>When</th><th>Source</th><th>Status</th><th>Severity</th><th>Why</th><th>Watch</th></tr>';
    $found = 0;
    if ($nInfectionId > 0)
    {
        $sql = "SELECT reportId, created, lastSeen, inet_ntoa(ip) AS ip, port, status, severity, why FROM hackReport WHERE infectionId = ? ORDER BY reportId DESC LIMIT 50";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $nInfectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc()))
        {
            $found++;
            $when = $row['lastSeen'] ?: $row['created'];
            $watch = 'index.php?f=liveAudit&infectionId='.$nInfectionId.'&reportId='.$row['reportId'];
            $relatedHtml .= '<tr><td>'.liveAuditHtml($row['reportId']).'</td><td>'.liveAuditHtml($when).'</td><td>'.liveAuditHtml($row['ip'].':'.$row['port']).'</td><td>'.liveAuditHtml($row['status']).'</td><td>'.liveAuditHtml($row['severity']).'</td><td>'.liveAuditHtml($row['why']).'</td><td><a href="'.liveAuditHtml($watch).'">watch</a></td></tr>';
        }
        $stmt->close();
    }
    if (!$found)
        $relatedHtml .= '<tr><td colspan="7">No linked hackReport evidence yet.</td></tr>';
    $relatedHtml .= '</table>';
    CXmlCommand::setInnerHTML('liveAuditRelated', '', $relatedHtml);

    $conn->close();
}

?>
