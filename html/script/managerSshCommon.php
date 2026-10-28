<?php
declare(strict_types=1);

const TARA_SSH_STATUS = '/run/tarasec-app-ssh/status.json';
const TARA_SSH_SET = 'tarasec_app_ssh';

function managerSshSource(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}
function managerSshDuration(int $seconds): bool { return in_array($seconds, [300,600,900], true); }
function managerSshAuthorized(array $row): bool {
    return (int)($row['active'] ?? 0) === 1 && empty($row['rejectedTime'])
        && (empty($row['expires']) || strtotime($row['expires']) > time());
}
function managerSshPublic(string $path = TARA_SSH_STATUS): array {
    $raw = is_readable($path) ? file_get_contents($path) : false;
    $state = is_string($raw) && strlen($raw) < 131072 ? json_decode($raw, true) : null;
    if (!is_array($state) || !isset($state['updated']) || abs(time()-(int)$state['updated']) > 30) {
        return ['enabled'=>false, 'error'=>'ssh_worker_unavailable'];
    }
    return $state;
}
// Insert after global security policy, immediately before this port's existing SSH policy.
// Refuse an unrecognised firewall rather than bypassing arbitrary owner rules.
function managerSshRulePosition(string $rules, int $port): int {
    $index = 0;
    $firstBroadAccept = null;
    foreach (explode("\n", $rules) as $line) {
        if (!str_starts_with($line, '-A INPUT ')) continue;
        $index++;
        // A blanket interface/loopback ACCEPT must not bypass the gate either.
        if ($firstBroadAccept === null && str_ends_with($line, ' -j ACCEPT')
            && !str_contains($line, '--dport') && !str_contains($line, '--dports')
            && (!str_contains($line, '-p ') || str_contains($line, '-p tcp '))) {
            $firstBroadAccept = $index;
        }
        // A normal firewall rebuild emits source-specific SSH ACCEPT rules.
        // Those must also be behind the timed gate. Earlier source DROP rules
        // remain authoritative. Recovery requires console access.
        if (str_contains($line,'-p tcp ') && str_ends_with($line,' -j ACCEPT')
            && preg_match('/ --dport '.preg_quote((string)$port, '/').'(?: |$)/', $line)) return $firstBroadAccept ?? $index;
        if (str_contains($line, 'TARASEC_SSH_') && preg_match('/ --dport '.preg_quote((string)$port, '/').'(?: |$)/', $line)) return $firstBroadAccept ?? $index;
        if (preg_match('/^-A INPUT -p tcp(?: -m tcp)? --dport '.preg_quote((string)$port, '/').' -j (?:ACCEPT|REJECT --reject-with tcp-reset)$/D', $line)) return $firstBroadAccept ?? $index;
    }
    throw new RuntimeException('Configured SSH firewall policy not found');
}
function managerSshRemaining(string $saved, int $port): int {
    foreach (explode("\n", $saved) as $line) {
        if (preg_match('/^add '.TARA_SSH_SET.' '.(int)$port.' timeout ([0-9]+)(?: |$)/D', $line, $m)
            && (int)$m[1] > 0 && (int)$m[1] <= 900) return (int)$m[1];
    }
    return 0;
}

/** Only explicitly public fields may leave the node; never return listener ports. */
function managerSshClientState(array $state): array {
    return [
        'enabled' => ($state['enabled'] ?? false) === true,
        'error' => $state['error'] ?? null,
        'remainingSeconds' => max(0, (int)($state['remainingSeconds'] ?? 0)),
        'listener' => ($state['listener'] ?? false) === true,
        'gateEnforced' => ($state['gateEnforced'] ?? false) === true,
        'mfaRequired' => true,
        'approvalUrl' => 'https://tarasec.org/ops/agent/',
    ];
}
