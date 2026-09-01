<?php
// Global TaraSec subscriber login for Android/iOS/web clients.

declare(strict_types=1);
require_once __DIR__ . '/subscriber-api-common.php';

$db = subscriber_db();
$identifier = trim((string)($_POST['identifier'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$deviceLabel = trim((string)($_POST['device_label'] ?? ''));

if ($identifier === '' || $password === '') {
    subscriber_reply(400, ['ok'=>false,'reason'=>'missing_credentials']);
}

$isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
if ($isEmail) {
    $stmt=$db->prepare("SELECT customerId,email,phone,passwordHash FROM hotspotCustomer
                        WHERE active=b'1' AND LOWER(email)=LOWER(?) LIMIT 2");
} else {
    $stmt=$db->prepare("SELECT customerId,email,phone,passwordHash FROM hotspotCustomer
                        WHERE active=b'1' AND phone=? LIMIT 2");
}
$stmt->bind_param('s',$identifier);
$stmt->execute();
$result=$stmt->get_result();
if ($result->num_rows !== 1) {
    subscriber_reply(401, ['ok'=>false,'reason'=>'invalid_credentials']);
}
$row=$result->fetch_assoc();
$hash=(string)($row['passwordHash'] ?? '');
if ($hash === '' || !password_verify($password,$hash)) {
    subscriber_reply(401, ['ok'=>false,'reason'=>'invalid_credentials']);
}

$customerId=(int)$row['customerId'];
$plainToken=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$tokenHash=hash('sha256',$plainToken);
$expiresAt=(new DateTimeImmutable('+90 days'))->format('Y-m-d H:i:s');
$stmt=$db->prepare("INSERT INTO hotspotSubscriberToken(customerId,tokenHash,deviceLabel,expiresAt)
                    VALUES(?,?,NULLIF(?,''),?)");
$stmt->bind_param('isss',$customerId,$tokenHash,$deviceLabel,$expiresAt);
$stmt->execute();
$stmt=$db->prepare("UPDATE hotspotCustomer SET lastLogin=NOW() WHERE customerId=?");
$stmt->bind_param('i',$customerId);
$stmt->execute();

subscriber_reply(200,[
    'ok'=>true,
    'token'=>$plainToken,
    'expires_at'=>$expiresAt,
    'customer_id'=>$customerId,
    'email'=>$row['email'],
    'phone'=>$row['phone']
]);
