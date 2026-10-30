<?php
require_once __DIR__ . '/../deploymentMode.php'; tarasecRequireDemo();
require_once __DIR__ . '/webPokeLib.php';
ini_set('display_errors', '0');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new InvalidArgumentException('POST required');
    $path = '/run/tarasec-web-poke-bootstrap.json';
    $pending = is_readable($path) ? json_decode((string)file_get_contents($path), true) : null;
    if (!is_array($pending)) throw new DomainException('No local administrator enrollment is running');
    $input = webPokeInput();
    webPokeReply(200, webPokeBootstrapProof($pending, (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($input['proof'] ?? '')));
} catch (Throwable $e) { webPokeError($e); }
