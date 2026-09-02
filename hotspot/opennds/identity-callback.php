<?php
declare(strict_types=1);
require_once __DIR__ . '/identity-common.php';

$state=trim((string)($_GET['state'] ?? ''));
$code=trim((string)($_GET['code'] ?? ''));
if ($state==='' || $code==='') identity_error(400,'incomplete_identity_callback');
$db=subscriber_db();
$stateHash=hash('sha256',$state);
$stmt=$db->prepare("SELECT oauthStateId,provider,appRedirect FROM tarasecOAuthState WHERE stateHash=? AND usedAt IS NULL AND expiresAt>NOW() LIMIT 1");
$stmt->bind_param('s',$stateHash);
$stmt->execute();
$row=$stmt->get_result()->fetch_assoc();
if (!$row) identity_error(400,'identity_state_invalid_or_expired');
$stateId=(int)$row['oauthStateId'];
$db->query("UPDATE tarasecOAuthState SET usedAt=NOW() WHERE oauthStateId=".$stateId." AND usedAt IS NULL AND expiresAt>NOW()");
if ($db->affected_rows !== 1) identity_error(400,'identity_state_already_used');
$config=identity_config((string)$row['provider']);

$token=identity_http($config['token'],'POST',[
    'code'=>$code,
    'client_id'=>$config['client_id'],
    'client_secret'=>$config['client_secret'],
    'redirect_uri'=>$config['callback'],
    'grant_type'=>'authorization_code'
]);

$provider=$config['provider'];
$subject=''; $email=null; $emailVerified=false; $displayName=null;
if ($provider==='google') {
    $idToken=(string)($token['id_token'] ?? '');
    if ($idToken==='') identity_error(502,'google_id_token_missing');
    $profile=identity_http('https://oauth2.googleapis.com/tokeninfo','GET',['id_token'=>$idToken]);
    if (!hash_equals((string)$config['client_id'],(string)($profile['aud'] ?? ''))) identity_error(401,'google_token_audience_invalid');
    $subject=(string)($profile['sub'] ?? '');
    $email=isset($profile['email'])?strtolower((string)$profile['email']):null;
    $emailVerified=filter_var($profile['email_verified'] ?? false,FILTER_VALIDATE_BOOLEAN);
    $displayName=isset($profile['name'])?(string)$profile['name']:null;
} else {
    $access=(string)($token['access_token'] ?? '');
    if ($access==='') identity_error(502,'facebook_access_token_missing');
    $profile=identity_http('https://graph.facebook.com/v23.0/me','GET',[
        'fields'=>'id,name,email',
        'access_token'=>$access
    ]);
    $subject=(string)($profile['id'] ?? '');
    $email=isset($profile['email'])?strtolower((string)$profile['email']):null;
    $displayName=isset($profile['name'])?(string)$profile['name']:null;
    // Facebook authenticates the provider identity but does not provide a
    // portable email_verified claim. Do not mark its email verified here.
    $emailVerified=false;
}
if ($subject==='') identity_error(502,'identity_subject_missing');

$db->begin_transaction();
$stmt=$db->prepare("SELECT identityId FROM tarasecIdentityProvider WHERE provider=? AND providerSubject=? FOR UPDATE");
$stmt->bind_param('ss',$provider,$subject);
$stmt->execute();
$existing=$stmt->get_result()->fetch_assoc();
if ($existing) {
    $identityId=(int)$existing['identityId'];
    $stmt=$db->prepare("UPDATE tarasecIdentityProvider SET emailAtProvider=? WHERE provider=? AND providerSubject=?");
    $stmt->bind_param('sss',$email,$provider,$subject);
    $stmt->execute();
    if ($emailVerified && $email !== null && $email !== '') {
        $stmt=$db->prepare("UPDATE tarasecIdentity SET primaryEmail=COALESCE(primaryEmail,?), emailVerifiedAt=CASE WHEN primaryEmail IS NULL OR primaryEmail=? THEN COALESCE(emailVerifiedAt,NOW()) ELSE emailVerifiedAt END, displayName=COALESCE(displayName,?) WHERE identityId=?");
        $stmt->bind_param('sssi',$email,$email,$displayName,$identityId);
        $stmt->execute();
    }
} else {
    $stmt=$db->prepare("INSERT INTO tarasecIdentity(primaryEmail,emailVerifiedAt,displayName) VALUES(?,IF(?=1,NOW(),NULL),?)");
    $verifiedInt=$emailVerified?1:0;
    $stmt->bind_param('sis',$email,$verifiedInt,$displayName);
    $stmt->execute();
    $identityId=(int)$db->insert_id;
    $stmt=$db->prepare("INSERT INTO tarasecIdentityProvider(identityId,provider,providerSubject,emailAtProvider) VALUES(?,?,?,?)");
    $stmt->bind_param('isss',$identityId,$provider,$subject,$email);
    $stmt->execute();
}
$appCode=identity_random();
$appCodeHash=hash('sha256',$appCode);
$stmt=$db->prepare("INSERT INTO tarasecIdentityCode(identityId,codeHash,expiresAt) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 2 MINUTE))");
$stmt->bind_param('is',$identityId,$appCodeHash);
$stmt->execute();
$db->commit();

$separator=str_contains((string)$row['appRedirect'],'?')?'&':'?';
header('Cache-Control: no-store');
header('Location: '.$row['appRedirect'].$separator.http_build_query(['code'=>$appCode]),true,303);
