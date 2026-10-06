<?php
declare(strict_types=1);
require __DIR__.'/../html/script/serviceDiscoveryCommon.php';
function check(bool $value, string $message): void { if (!$value) throw new Exception($message); }
function rejects(array $config): void { try { taraAccountServices($config); } catch (RuntimeException $e) { return; } throw new Exception('Invalid provider accepted'); }
check(taraAccountServices([])['source'] === 'tarasec.org', 'Absent local services use central default');
$cfg = ['identity_api_base'=>'https://owner.example/api/v1/identity/','subscriber_api_base'=>'https://owner.example/api/v1/subscriber/'];
check(taraAccountServices($cfg)['source'] === 'gateway', 'Configured local services take precedence');
check(taraAccountServices($cfg)['identity_api_base'] === 'https://owner.example/api/v1/identity', 'Normalize API base');
rejects(['identity_api_base'=>$cfg['identity_api_base']]);
foreach (['http://owner.example/api','https://user:pass@owner.example/api','https://owner.example/api?redirect=evil','https://owner.example/api#x','https://other.example/api'] as $bad) {
    rejects(['identity_api_base'=>$bad,'subscriber_api_base'=>$cfg['subscriber_api_base']]);
}
check(taraAdminServices([], [])['agent_api'] === 'https://tarasec.org/ops/agent/api.php', 'Missing local admin service uses central');
$admin = ['admin_api_url'=>'https://owner.example/ops/agent/api.php','admin_sign_in_url'=>'https://owner.example/ops/agent/gatekeeper.php'];
check(taraAdminServices([], $admin)['agent_api'] === $admin['admin_api_url'], 'Local admin service takes precedence');
try { taraAdminServices([], ['admin_api_url'=>$admin['admin_api_url']]); throw new Exception('Partial admin configuration accepted'); } catch (RuntimeException $e) {}
echo "Service discovery boundary tests passed\n";
