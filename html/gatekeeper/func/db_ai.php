<?php
//db_ai.php

require_once("../taraLib.php");

function aiDecodeStoredResponse($raw)
{
    $outer = json_decode($raw ?? '', true);
    if (!is_array($outer)) return null;
    $text = $outer['text'] ?? '';
    $text = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```\s*$/', '', $text);
    $data = json_decode($text, true);
    return is_array($data) ? $data : null;
}

function aiTableExists($conn, $name)
{
    $stmt = $conn->prepare("select 1 from information_schema.tables where table_schema=database() and table_name=? limit 1");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function aiPercent($confidence)
{
    if ($confidence === null || $confidence === '') return '-';
    return round(((float)$confidence) * 100) . '%';
}

function db_ai()
{
    $conn = getConnection();

    print '<h3>Recent AI assessments</h3>';
    $sql = "select aiResponseId, TIMESTAMPDIFF(SECOND, created, NOW()) AS age, seconds, response from aiResponse order by aiResponseId desc limit 10";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();

    print '<table><tr><td>ID</td><td>Time</td><td>Run time</td><td>Category</td><td>Confidence</td><td>Summary</td></tr>';
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data = aiDecodeStoredResponse($row['response']);
            $summary = is_array($data) ? ($data['summary'] ?? '') : '(Could not decode assessment JSON)';
            $category = is_array($data) ? ($data['category'] ?? '') : '';
            $confidence = is_array($data) ? ($data['confidence'] ?? '') : '';
            if (is_numeric($confidence)) {
                $confidence = ((float)$confidence <= 1 ? round(((float)$confidence) * 100) : round((float)$confidence)) . '%';
            }
            $time = ($row['age'] !== null ? age($row['age']) : 'Error');
            print '<tr><td><a href="index.php?f=aiRecord&id='.(int)$row['aiResponseId'].'">'.(int)$row['aiResponseId'].'</a></td>';
            print '<td>'.htmlspecialchars($time).'</td><td>'.(int)$row['seconds'].' s</td>';
            print '<td>'.htmlspecialchars((string)$category).'</td><td>'.htmlspecialchars((string)$confidence).'</td>';
            print '<td>'.htmlspecialchars((string)$summary).'</td></tr>';
        }
    }
    print '</table>';
    if ($result) $result->free();
    $stmt->close();

    if (aiTableExists($conn, 'aiUnitAssessment')) {
        print '<h3>Latest suspected units</h3>';
        print '<p><em>AI candidates only — not confirmed infection state.</em></p>';
        $sql = "select a.* from aiUnitAssessment a join (select ownerId,unitId,max(aiResponseId) latestResponse from aiUnitAssessment group by ownerId,unitId) l on l.ownerId=a.ownerId and l.unitId=a.unitId and l.latestResponse=a.aiResponseId order by coalesce(a.confidence,0) desc,a.severity desc,a.created desc limit 100";
        $result = $conn->query($sql);
        print '<table><tr><td>Owner</td><td>Unit</td><td>Confidence</td><td>Severity</td><td>Category</td><td>Summary</td><td>AI response</td></tr>';
        while ($row = $result->fetch_assoc()) {
            print '<tr><td>'.(int)$row['ownerId'].'</td><td>'.(int)$row['unitId'].'</td><td>'.htmlspecialchars(aiPercent($row['confidence'])).'</td>';
            print '<td>'.htmlspecialchars((string)($row['severity'] ?? '-')).'</td><td>'.htmlspecialchars((string)($row['category'] ?? '')).'</td>';
            print '<td>'.htmlspecialchars((string)($row['summary'] ?? '')).'</td><td><a href="index.php?f=aiRecord&id='.(int)$row['aiResponseId'].'">#'.(int)$row['aiResponseId'].'</a></td></tr>';
        }
        print '</table>';
        $result->free();
    }

    if (aiTableExists($conn, 'aiBotnetCandidate')) {
        print '<h3>Latest botnet candidates</h3>';
        print '<p><em>Correlation hypotheses only — membership is not treated as confirmed.</em></p>';
        $sql = "select b.* from aiBotnetCandidate b join (select candidateKey,max(aiResponseId) latestResponse from aiBotnetCandidate group by candidateKey) l on l.candidateKey=b.candidateKey and l.latestResponse=b.aiResponseId order by coalesce(b.confidence,0) desc,b.created desc limit 100";
        $result = $conn->query($sql);
        print '<table><tr><td>Candidate</td><td>Confidence</td><td>Members</td><td>Summary</td><td>AI response</td></tr>';
        while ($row = $result->fetch_assoc()) {
            $members = json_decode($row['membersJson'] ?? '', true);
            $membersText = is_array($members) ? json_encode($members, JSON_UNESCAPED_SLASHES) : (string)($row['membersJson'] ?? '');
            print '<tr><td>'.htmlspecialchars((string)$row['candidateKey']).'</td><td>'.htmlspecialchars(aiPercent($row['confidence'])).'</td>';
            print '<td>'.htmlspecialchars($membersText).'</td><td>'.htmlspecialchars((string)($row['summary'] ?? '')).'</td>';
            print '<td><a href="index.php?f=aiRecord&id='.(int)$row['aiResponseId'].'">#'.(int)$row['aiResponseId'].'</a></td></tr>';
        }
        print '</table>';
        $result->free();
    }

    $conn->close();
}

?>