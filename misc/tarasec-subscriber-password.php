#!/usr/bin/env php
<?php
// Set or replace a global TaraSec subscriber password by email.
// CLI only: sudo php misc/tarasec-subscriber-password.php user@example.com

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only\n"); exit(2); }
$email=trim((string)($argv[1] ?? ''));
if (!filter_var($email,FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR,"Usage: php misc/tarasec-subscriber-password.php user@example.com\n");
    exit(2);
}
fwrite(STDOUT,"New TaraSec password: ");
if (stripos(PHP_OS_FAMILY,'Windows') === false) system('stty -echo');
$password=rtrim((string)fgets(STDIN),"\r\n");
if (stripos(PHP_OS_FAMILY,'Windows') === false) system('stty echo');
fwrite(STDOUT,"\n");
if (strlen($password) < 8) { fwrite(STDERR,"Password must be at least 8 characters.\n"); exit(2); }

$dbBootstrap=getenv('TARASEC_DB_BOOTSTRAP') ?: __DIR__.'/../html/db_connect.php';
if (!is_file($dbBootstrap)) { fwrite(STDERR,"DB bootstrap not found: $dbBootstrap\n"); exit(1); }
require $dbBootstrap;
$db=$mysqli ?? $conn ?? $db ?? null;
if (!($db instanceof mysqli)) { fwrite(STDERR,"No mysqli handle from DB bootstrap.\n"); exit(1); }

$stmt=$db->prepare("SELECT customerId FROM hotspotCustomer WHERE active=b'1' AND LOWER(email)=LOWER(?) LIMIT 2");
$stmt->bind_param('s',$email);
$stmt->execute();
$res=$stmt->get_result();
if ($res->num_rows !== 1) {
    fwrite(STDERR,$res->num_rows===0 ? "No active global subscriber with that email.\n" : "Email is ambiguous; fix duplicate global subscriber records first.\n");
    exit(1);
}
$customerId=(int)$res->fetch_assoc()['customerId'];
$hash=password_hash($password,PASSWORD_DEFAULT);
$stmt=$db->prepare("UPDATE hotspotCustomer SET passwordHash=? WHERE customerId=?");
$stmt->bind_param('si',$hash,$customerId);
$stmt->execute();
fwrite(STDOUT,"Password updated for TaraSec subscriber #$customerId ($email).\n");
