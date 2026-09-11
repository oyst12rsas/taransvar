<?php

function dbServerDemoEsc($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dbServerDemoTime($value)
{
    if (!$value) return '&mdash;';
    $ts = strtotime((string)$value . ' UTC');
    if ($ts === false) return dbServerDemoEsc($value);
    return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
}

function dbServerDemoStateLabel($state)
{
    $labels = array(
        'setup' => 'SETUP',
        'active' => 'ACTIVE',
        'contained' => 'CONTAINED',
        'releasing' => 'RELEASING',
        'closed' => 'COMPLETED'
    );
    return isset($labels[$state]) ? $labels[$state] : strtoupper((string)$state);
}

function dbServerDemos()
{
    $conn = null;
    try
    {
        $conn = getConnection();
        $conn->query("SET time_zone='+00:00'");

        $tableResult = $conn->query("SHOW TABLES LIKE 'demoAssistanceSession'");
        $hasDemoSessions = $tableResult && $tableResult->num_rows > 0;
        if ($tableResult) $tableResult->free();

        if (!$hasDemoSessions)
        {
            $conn->close();
            return;
        }

        $sql = "SELECT d.sessionId,d.name,d.threshold,d.state,d.targetIp,d.startsAt,d.blockAt,d.releaseAt,d.closedAt,d.createdAt,d.updatedAt,"
             . "COUNT(p.participantId) participantCount,"
             . "SUM(CASE WHEN p.severity>d.threshold THEN 1 ELSE 0 END) infectedCount,"
             . "SUM(CASE WHEN p.decision='silent' THEN 1 ELSE 0 END) silentCount,"
             . "SUM(CASE WHEN p.decision='recovered' THEN 1 ELSE 0 END) recoveredCount "
             . "FROM demoAssistanceSession d LEFT JOIN demoAssistanceParticipant p ON p.sessionId=d.sessionId "
             . "GROUP BY d.sessionId,d.name,d.threshold,d.state,d.targetIp,d.startsAt,d.blockAt,d.releaseAt,d.closedAt,d.createdAt,d.updatedAt "
             . "ORDER BY d.sessionId DESC LIMIT 100";
        $result = $conn->query($sql);

        $current = array();
        $history = array();
        while ($row = $result->fetch_assoc())
        {
            if ($row['state'] === 'closed') $history[] = $row;
            else $current[] = $row;
        }
        $result->free();
        $conn->close();
    }
    catch (Throwable $e)
    {
        if ($conn) $conn->close();
        error_log('dbServerDemos: ' . $e->getMessage());
        return;
    }
?>
<style>
.gk-demo-overview{margin:1.2rem 0 2rem}.gk-demo-overview h2{margin-bottom:.45rem}.gk-demo-note{margin:.2rem 0 1rem;color:#555}.gk-demo-table-wrap{overflow-x:auto;margin-bottom:1.5rem}.gk-demo-table{border-collapse:collapse;width:100%;min-width:760px}.gk-demo-table th,.gk-demo-table td{border:1px solid #d8d8d8;padding:.45rem .55rem;text-align:left;vertical-align:top}.gk-demo-table th{background:#f4f4f4}.gk-demo-state{font-weight:bold;white-space:nowrap}.gk-demo-state-active{color:#a00000}.gk-demo-state-contained,.gk-demo-state-releasing{color:#9a5a00}.gk-demo-state-closed{color:#28752d}.gk-demo-empty{padding:.7rem;border:1px solid #ddd;background:#fafafa;margin-bottom:1.5rem}
</style>
<div class="gk-demo-overview">
    <h2>Demo activity</h2>
    <p class="gk-demo-note">Central overview of current and completed community demos registered on this DB server.</p>

    <h3>Current demos</h3>
<?php if (count($current) === 0) { ?>
    <div class="gk-demo-empty">No demo is currently running.</div>
<?php } else { ?>
    <div class="gk-demo-table-wrap"><table class="gk-demo-table">
        <tr><th>ID</th><th>Demo</th><th>Status</th><th>Target</th><th>Participants</th><th>Infected</th><th>Silent</th><th>Started</th><th>Next event</th></tr>
<?php foreach ($current as $row) {
    $nextEvent = '&mdash;';
    if ($row['state'] === 'active' && $row['blockAt']) $nextEvent = 'Contain: ' . dbServerDemoTime($row['blockAt']);
    elseif (($row['state'] === 'contained' || $row['state'] === 'releasing') && $row['releaseAt']) $nextEvent = 'Release: ' . dbServerDemoTime($row['releaseAt']);
?>
        <tr>
            <td><?php print (int)$row['sessionId']; ?></td>
            <td><?php print dbServerDemoEsc($row['name']); ?></td>
            <td class="gk-demo-state gk-demo-state-<?php print dbServerDemoEsc($row['state']); ?>"><?php print dbServerDemoEsc(dbServerDemoStateLabel($row['state'])); ?></td>
            <td><?php print dbServerDemoEsc($row['targetIp']); ?></td>
            <td><?php print (int)$row['participantCount']; ?></td>
            <td><?php print (int)$row['infectedCount']; ?> &gt; <?php print (int)$row['threshold']; ?></td>
            <td><?php print (int)$row['silentCount']; ?></td>
            <td><?php print dbServerDemoTime($row['startsAt'] ?: $row['createdAt']); ?></td>
            <td><?php print $nextEvent; ?></td>
        </tr>
<?php } ?>
    </table></div>
<?php } ?>

    <h3>Demo history</h3>
<?php if (count($history) === 0) { ?>
    <div class="gk-demo-empty">No completed demos have been recorded yet.</div>
<?php } else { ?>
    <div class="gk-demo-table-wrap"><table class="gk-demo-table">
        <tr><th>ID</th><th>Demo</th><th>Status</th><th>Target</th><th>Participants</th><th>Infected</th><th>Recovered</th><th>Started</th><th>Completed</th></tr>
<?php foreach (array_slice($history, 0, 50) as $row) { ?>
        <tr>
            <td><?php print (int)$row['sessionId']; ?></td>
            <td><?php print dbServerDemoEsc($row['name']); ?></td>
            <td class="gk-demo-state gk-demo-state-closed">COMPLETED</td>
            <td><?php print dbServerDemoEsc($row['targetIp']); ?></td>
            <td><?php print (int)$row['participantCount']; ?></td>
            <td><?php print (int)$row['infectedCount']; ?> &gt; <?php print (int)$row['threshold']; ?></td>
            <td><?php print (int)$row['recoveredCount']; ?></td>
            <td><?php print dbServerDemoTime($row['startsAt'] ?: $row['createdAt']); ?></td>
            <td><?php print dbServerDemoTime($row['closedAt'] ?: $row['updatedAt']); ?></td>
        </tr>
<?php } ?>
    </table></div>
<?php } ?>
</div>
<?php
}

?>