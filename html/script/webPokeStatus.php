<?php
// Retired v1 route: a guessed/browser-created token must never grant status access.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
echo json_encode(['ok'=>false,'error'=>'Use the DB-authorized gateway demo session']);
