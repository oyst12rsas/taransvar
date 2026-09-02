<?php
declare(strict_types=1);
require_once __DIR__ . '/identity-common.php';

$provider=strtolower(trim((string)($_GET['provider'] ?? '')));
$appRedirect=identity_redirect_uri(trim((string)($_GET['app_redirect'] ?? 'tarasec://identity')));
$config=identity_config($provider);
if ($config['client_id']==='' || $config['client_secret']==='') identity_error(503,'identity_provider_not_configured');

$db=subscriber_db();
$state=identity_random();
$stateHash=hash('sha256',$state);
$stmt=$db->prepare("INSERT INTO tarasecOAuthState(stateHash,provider,appRedirect,expiresAt) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 10 MINUTE))");
$stmt->bind_param('sss',$stateHash,$provider,$appRedirect);
$stmt->execute();

$params=[
    'client_id'=>$config['client_id'],
    'redirect_uri'=>$config['callback'],
    'response_type'=>'code',
    'state'=>$state
];
if ($provider==='google') {
    $params['scope']='openid email profile';
    $params['prompt']='select_account';
} else {
    $params['scope']='email,public_profile';
}
header('Cache-Control: no-store');
header('Location: '.$config['authorize'].'?'.http_build_query($params),true,303);
