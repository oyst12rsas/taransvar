<?php
// Validate model identities against the local evidence actually sent, not global
// context or a model's own explanation. Reject the whole result: dropping an
// invalid unit alone would leave its unsupported block recommendation intact.
function gatewayAiAllowedUnits(string $question): array
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
    return $allowed;
}

function gatewayAiIdentityConstraint(string $question): string
{
    $keys = array_keys(gatewayAiAllowedUnits($question));
    if (!$keys) {
        return "\nLOCAL IDENTITY AVAILABILITY: No stable local owner_id/unit_id pairs were supplied. " .
            "unit_assessments MUST be []. Do not claim a managed local unit is probing, infected or compromised. " .
            "Global context, demo sessions and previous severity cannot supply a missing local identity. " .
            "Assess any IP/node observations separately and state the missing evidence. " .
            "This does not establish that the gateway or its clients are clean.\n";
    }
    return "\nALLOWED LOCAL UNIT PAIRS (owner_id:unit_id): " . implode(', ', $keys) .
        ". Only assess these exact pairs where supported by observations; never invent identifiers or use example placeholders.\n";
}

function gatewayAiUnitsSupported(array $assessment, string $question): bool
{
    $allowed = gatewayAiAllowedUnits($question);
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
