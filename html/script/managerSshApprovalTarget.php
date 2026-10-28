<?php
declare(strict_types=1);
const TARA_SSH_APPROVAL_TARGET = '/var/lib/tarasec-ssh-approval/target.json';
function managerSshApprovalTargetValidate(array $value): array {
    $ref = (string)($value['server_ref'] ?? '');
    $url = (string)($value['approval_url'] ?? '');
    $parts = parse_url($url);
    if (($value['version'] ?? null) !== 1 || !preg_match('/^[a-f0-9]{64}$/D', $ref)
        || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !str_ends_with($parts['path'] ?? '', '/')) {
        throw new RuntimeException('SSH approval target unavailable');
    }
    return ['server_ref'=>$ref, 'approval_url'=>$url];
}
function managerSshApprovalTargetRead(string $path=TARA_SSH_APPROVAL_TARGET): ?array {
    if (!file_exists($path)) return null;
    $info = lstat($path);
    if (!$info || is_link($path) || ($info['mode'] & 0170000) !== 0100000
        || $info['uid'] !== 0 || ($info['mode'] & 0022) !== 0 || $info['size'] > 4096)
        throw new RuntimeException('SSH approval target unavailable');
    $raw = file_get_contents($path);
    $value = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($value)) throw new RuntimeException('SSH approval target unavailable');
    return managerSshApprovalTargetValidate($value);
}
