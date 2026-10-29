<?php
// Validate model identities against the local evidence actually sent, not global
// context or a model's own explanation. Reject the whole result: dropping an
// invalid unit alone would leave its unsupported block recommendation intact.
function gatewayAiUnitsSupported(array $assessment, string $question): bool
{
    $allowed = [];
    $local = false;
    foreach (explode("\n", $question) as $line) {
        if (str_starts_with($line, 'RECORDS: ')) {
            $title = substr($line, 9);
            $local = in_array($title, [
                'local stable unit summaries', 'local unit destination fingerprints',
                'owner-confirmed exact-unit reports',
                'local observations for exact demo correlation (bounded sample, not complete totals)'
            ], true);
            continue;
        }
        if (str_starts_with($line, 'GLOBAL TARASEC CONTEXT')) $local = false;
        if (!$local) continue;
        $row = json_decode($line, true);
        if (is_array($row) && gatewayAiUnitId($row['owner_id'] ?? null) && gatewayAiUnitId($row['unit_id'] ?? null)) {
            $allowed[(string)$row['owner_id'].':'.(string)$row['unit_id']] = true;
        }
    }
    foreach ($assessment['unit_assessments'] ?? [] as $unit) {
        if (!is_array($unit) || !gatewayAiUnitId($unit['owner_id'] ?? null) || !gatewayAiUnitId($unit['unit_id'] ?? null) ||
            !isset($allowed[(string)$unit['owner_id'].':'.(string)$unit['unit_id']])) return false;
    }
    return true;
}

function gatewayAiUnitId($id): bool
{
    return (is_int($id) || is_string($id)) && preg_match('/^[0-9]+$/D', (string)$id) === 1;
}
