<?php
// Read-only global TaraSec subscriber account snapshot for Android/iOS/web.

declare(strict_types=1);
require_once __DIR__ . '/subscriber-api-common.php';
require_once __DIR__ . '/payment-service-client.php';

$db=subscriber_db();
$subscriber=subscriber_require($db);
$customerId=(int)$subscriber['customerId'];

$stmt=$db->prepare("SELECT COALESCE(a.balanceCredits,0) balanceCredits,c.email,c.phone,c.created,c.lastLogin
                    FROM hotspotCustomer c
                    LEFT JOIN hotspotCreditAccount a ON a.customerId=c.customerId
                    WHERE c.customerId=? LIMIT 1");
$stmt->bind_param('i',$customerId);
$stmt->execute();
$account=$stmt->get_result()->fetch_assoc();
if (!$account) subscriber_reply(404,['ok'=>false,'reason'=>'subscriber_not_found']);

$stmt=$db->prepare("SELECT s.sessionId,g.name gatewayName,g.countryCode,g.priceLabel,
                           s.priceCreditsPerMiB,s.startedAt,s.endedAt,s.bytesUp,s.bytesDown,
                           s.chargedCredits
                    FROM hotspotSession s
                    JOIN hotspotGateway g ON g.gatewayId=s.providerGatewayId
                    WHERE s.customerId=?
                    ORDER BY s.startedAt DESC,s.sessionId DESC LIMIT 50");
$stmt->bind_param('i',$customerId);
$stmt->execute();
$res=$stmt->get_result();
$sessions=[];
while ($row=$res->fetch_assoc()) {
    $bytes=(int)$row['bytesUp']+(int)$row['bytesDown'];
    $sessions[]=[
        'session_id'=>(int)$row['sessionId'],
        'hotspot'=>$row['gatewayName'],
        'country_code'=>$row['countryCode'],
        'price_label'=>$row['priceLabel'],
        'price_credits_per_mib'=>(string)$row['priceCreditsPerMiB'],
        'started_at'=>$row['startedAt'],
        'ended_at'=>$row['endedAt'],
        'bytes'=>$bytes,
        'mib'=>number_format($bytes/1048576.0,3,'.',''),
        'charged_credits'=>(string)$row['chargedCredits']
    ];
}

$credit=[
    'status'=>'unavailable',
    'credit_limit_credits'=>'0.000000',
    'debt_credits'=>'0.000000',
    'available_credit'=>'0.000000',
    'draw_enabled'=>false,
    'draw_endpoint'=>'subscriber-credit-draw.php'
];
try {
    $central=tarasec_payment_request('summary','GET',['subscriber_ref'=>(string)$customerId]);
    $credit=[
        'status'=>(string)$central['status'],
        'credit_limit_credits'=>(string)$central['credit_limit'],
        'debt_credits'=>(string)$central['debt'],
        'available_credit'=>(string)$central['available_credit'],
        'draw_enabled'=>(bool)$central['draw_enabled'],
        'draw_endpoint'=>'subscriber-credit-draw.php'
    ];
} catch (Throwable $e) {
    error_log('TaraSec central credit summary unavailable: '.$e->getMessage());
}

subscriber_reply(200,[
    'ok'=>true,
    'customer_id'=>$customerId,
    'email'=>$account['email'],
    'phone'=>$account['phone'],
    'balance_credits'=>(string)$account['balanceCredits'],
    'created'=>$account['created'],
    'last_login'=>$account['lastLogin'],
    'credit_facility'=>$credit,
    'sessions'=>$sessions,
    'payment'=>[
        'enabled'=>false,
        'reason'=>'payment_provider_not_configured'
    ]
]);
