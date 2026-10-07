<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../html/script/unitLinkRequestCommon.php';
require_once __DIR__.'/../html/dbfunc.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $cfg=unitLinkConfig();
    if ($cfg['mode']!=='service_handoff') exit;
    $db=getConnection();
    if (!(int)$db->query("SELECT GET_LOCK('tarasec:unit-link-worker',0)")->fetch_row()[0]) exit;
    try {
        $provider=taraAccountServices()['identity_api_base'];
        $db->query("DELETE FROM unitLinkRequest WHERE expiresAt<NOW()-INTERVAL 1 DAY");
        $rows=$db->query("SELECT * FROM unitLinkRequest WHERE state IN ('pending','applied') AND expiresAt>NOW() ORDER BY created LIMIT 10")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            if ($r['gatewayId']!==$cfg['gateway_id'] || $r['provider']!==$provider) {
                $q=unitLinkQuery($db,"UPDATE unitLinkRequest SET state='cancelled' WHERE requestId=?",[$r['requestId']]); $q->close(); continue;
            }
            try {
                $body=['gateway_id'=>$cfg['gateway_id'],'request_id'=>$r['requestId'],'poll_token'=>unitPollToken($r['requestId'],$cfg)];
                if ($r['state']==='pending') {
                    $reply=unitBrokerCall($provider,['action'=>'poll']+$body);
                    if (($reply['status'] ?? '')==='pending') continue;
                    if (($reply['status'] ?? '')!=='approved') {
                        $q=unitLinkQuery($db,"UPDATE unitLinkRequest SET state='cancelled' WHERE requestId=? AND state='pending'",[$r['requestId']]); $q->close(); continue;
                    }
                    if (!unitApplyApproval($db,$r,$cfg,(string)($reply['subject'] ?? ''))) continue;
                }
                unitBrokerCall($provider,['action'=>'ack']+$body);
                $q=unitLinkQuery($db,"UPDATE unitLinkRequest SET state='acked' WHERE requestId=? AND state='applied'",[$r['requestId']]); $q->close();
            } catch (Throwable $e) { error_log('Node-link collection will retry; no credential or identity logged'); }
        }
    } finally { $db->query("SELECT RELEASE_LOCK('tarasec:unit-link-worker')"); $db->close(); }
} catch (Throwable $e) { fwrite(STDERR,"Node-link collection unavailable; check configuration and schema.\n"); exit(1); }
