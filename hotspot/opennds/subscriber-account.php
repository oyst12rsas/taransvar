<?php
// Read-only global TaraSec subscriber account snapshot for Android/iOS/web.

declare(strict_types=1);
require_once __DIR__ . '/subscriber-api-common.php';

$db=subscriber_db();
$subscriber=subscriber_require($db);
$customerId=(int)$subscriber['customerId'];

$stmt=$db->prepare("SELECT COALESCE(a.balanceCredits,0) balanceCredits,c.email,c.phone,c.created,c.lastLogin,
                           f.creditLimitCredits,f.debtCredits,f.status creditStatus
                    FROM hotspotCustomer c
                    LEFT JOIN hotspotCreditAccount a ON a.customerId=c.customerId
                    LEFT JOIN hotspotCreditFacility f ON f.customerId=c.customerId
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

$limit=(float)($account['creditLimitCredits'] ?? 0);
$debt=(float)($account['debtCredits'] ?? 0);
$status=(string)($account['creditStatus'] ?? 'disabled');
$available=($status === 'active') ? max(0.0,round($limit-$debt,6)) : 0.0;

subscriber_reply(200,[
    'ok'=>true,
    'customer_id'=>$customerId,
    'email'=>$account['email'],
    'phone'=>$account['phone'],
    'balance_credits'=>(string)$account['balanceCredits'],
    'created'=>$account['created'],
    'last_login'=>$account['lastLogin'],
    'credit_facility'=>[
        'status'=>$status,
        'credit_limit_credits'=>number_format($limit,6,'.',''),
        'debt_credits'=>number_format($debt,6,'.',''),
        'available_credit'=>number_format($available,6,'.',''),
        'draw_enabled'=>($status === 'active' && $available > 0.0000005),
        'draw_endpoint'=>'subscriber-credit-draw.php'
    ],
    'sessions'=>$sessions,
    'payment'=>[
        'enabled'=>false,
        'reason'=>'payment_provider_not_configured'
    ]
]);
