<?php
/** Non-secret local owner view for TaraSec managed services. */
function managedReadConfig(string $path): array {
    if (!is_readable($path)) return [];
    $v = parse_ini_file($path, false, INI_SCANNER_RAW);
    return is_array($v) ? $v : [];
}
function serviceActive(string $name): bool {
    // Fixed allow-list only; no user input reaches systemctl.
    $allowed = ['netbird','wazuh-agent','opennds'];
    if (!in_array($name, $allowed, true)) return false;
    exec('/bin/systemctl is-active --quiet ' . escapeshellarg($name), $out, $code);
    return $code === 0;
}
$cfg = managedReadConfig('/etc/tarasec-managed.conf');
$managed = (($cfg['MANAGED'] ?? '0') === '1');
$paymentAvailable = (($cfg['PAYMENT_AVAILABLE'] ?? '0') === 'true' || ($cfg['PAYMENT_AVAILABLE'] ?? '0') === '1');
$paymentConfigured = (($cfg['PAYMENT_CONFIGURED'] ?? '0') === '1');
$contact = $cfg['PAYMENT_CONTACT_URL'] ?? 'https://tarasec.org/';
function badge(bool $ok, string $yes='Connected', string $no='Not connected'): string {
    return '<strong style="color:' . ($ok ? '#167c31' : '#9b2c2c') . '">' . htmlspecialchars($ok ? $yes : $no) . '</strong>';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>TaraSec Managed Services</title>
<style>body{font-family:Arial,sans-serif;max-width:850px;margin:40px auto;padding:0 20px;color:#20252b}.card{border:1px solid #d8dde3;border-radius:10px;padding:18px;margin:14px 0}.btn{display:inline-block;background:#1266d3;color:#fff;text-decoration:none;padding:10px 14px;border-radius:6px}code{background:#f2f4f6;padding:2px 5px}</style></head><body>
<h1>TaraSec Managed Services</h1>
<?php if (!$managed): ?>
<div class="card"><h2>Managed services are available</h2><p>This hotspot is currently operating without TaraSec managed enrollment. Enrollment can add a management VPN, SOC monitoring and automatic payment integration. Enrollment is optional and requires a one-time token issued to the hotspot owner.</p><p>Run <code>sudo misc/managed_enroll.sh --token ... --country KE</code> (or <code>PH</code>) after receiving a token from TaraSec.</p></div>
<?php else: ?>
<div class="card"><h2>Installation</h2><p>ID: <strong><?=htmlspecialchars((string)($cfg['INSTALLATION_ID'] ?? ''))?></strong></p></div>
<div class="card"><h2>Management VPN</h2><p><?=badge(serviceActive('netbird'))?></p></div>
<div class="card"><h2>SOC monitoring</h2><p><?=badge(serviceActive('wazuh-agent'))?></p><p>Agent: <?=htmlspecialchars((string)($cfg['WAZUH_AGENT_NAME'] ?? $cfg['WAZUH_AGENT_ID'] ?? ''))?></p></div>
<div class="card"><h2>Captive portal</h2><p><?=badge(serviceActive('opennds'),'OpenNDS running','OpenNDS not active')?></p></div>
<div class="card"><h2>Automatic payments</h2>
<?php if ($paymentConfigured): ?><p><?=badge(true,'Configured','')?></p>
<?php elseif ($paymentAvailable): ?><p>Automatic payment integration is available for this hotspot. Kenya can use M-Pesa; Philippine deployments can use supported GCash/payment-gateway integration.</p><p><a class="btn" href="<?=htmlspecialchars($contact)?>">Request payment setup</a></p>
<?php else: ?><p>Payment integration is not currently enabled for this installation.</p><?php endif; ?>
</div>
<?php endif; ?>
<p><a href="/">Back to hotspot</a></p></body></html>
