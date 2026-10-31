<?php
require_once __DIR__ . '/../html/script/webPokeLegacyGuard.php';
function check(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); }
function rejected(callable $call, string $type): void {
    try { $call(); } catch (Throwable $e) { check($e instanceof $type, 'Unexpected failure type'); return; }
    throw new RuntimeException('Expected authorization failure');
}
$identity = bin2hex(random_bytes(32)); $calls = 0; $now = time();
$allow = function() use (&$calls) { ++$calls; };
$deny = function() use (&$calls) { ++$calls; throw new DomainException('Denied'); };
$outage = function() use (&$calls) { ++$calls; throw new RuntimeException('Offline'); };
try {
    webPokeLegacyAuthorize($identity, $allow, $now);
    webPokeLegacyAuthorize($identity, $outage, $now+9);
    check($calls === 1, 'Fresh grant should avoid another DB call');
    rejected(fn() => webPokeLegacyAuthorize($identity, $outage, $now+10), RuntimeException::class);
    rejected(fn() => webPokeLegacyAuthorize($identity, $outage, $now+11), RuntimeException::class);
    check($calls === 3, 'Outage must not refresh or cache an expired grant');
    rejected(fn() => webPokeLegacyAuthorize($identity, $deny, $now+12), DomainException::class);
    rejected(fn() => webPokeLegacyAuthorize($identity, $allow, $now+13), DomainException::class);
    check($calls === 4, 'Explicit denial must replace the expired grant');
    webPokeLegacyAuthorize($identity, $allow, $now+22);
    check($calls === 5, 'Expired denial must recheck policy');
    rejected(fn() => webPokeLegacyAuthorize($identity . ':other-peer', $deny, $now+22), DomainException::class);
    rejected(fn() => webPokeLegacyAuthorize($identity . ':changed-config', $deny, $now+22), DomainException::class);
    check($calls === 7, 'Grants must not cross peer/config identities');
    echo "Legacy policy cache: expiry, denial, outage, recovery and identity isolation passed.\n";
} finally {
    foreach ([$identity, $identity . ':other-peer', $identity . ':changed-config'] as $id) {
        @unlink(webPokeDirectory() . '/legacyaccess-' . hash('sha256', hash('sha256', $id)) . '.json');
    }
}
