<?php
session_start();
require_once __DIR__ . '/payment_lib.php';
paymentEnsureSchema($conn);
$stmt = $conn->query('SELECT * FROM plans ORDER BY price ASC');
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
$cfg = paymentConfig();
$mpesaAvailable = paymentCfg($cfg, 'MPESA_CONSUMER_KEY') && paymentCfg($cfg, 'MPESA_SHORTCODE') && paymentCfg($cfg, 'MPESA_PASSKEY');
$gcashAvailable = paymentCfg($cfg, 'GCASH_API_BASE') && paymentCfg($cfg, 'GCASH_CLIENT_ID') && paymentCfg($cfg, 'GCASH_PARTNER_ID');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TaraSec WiFi payment</title>
<style>body{font-family:system-ui,sans-serif;margin:0;background:#f4f6f8}.wrap{max-width:900px;margin:auto;padding:2rem}.plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:1rem}.pay{margin-top:2rem;background:#fff;padding:1.25rem;border-radius:12px}.row{margin:.7rem 0}label{display:block;font-weight:600;margin-bottom:.25rem}input,select,button{font:inherit;padding:.65rem;width:100%;box-sizing:border-box}button{cursor:pointer;background:#1565c0;color:white;border:0;border-radius:6px}.muted{color:#666;font-size:.9rem}</style></head><body><div class="wrap">
<h1>TaraSec WiFi plans</h1>
<p>Select a plan and pay using a locally supported wallet.</p>
<div class="plans">
<?php foreach ($plans as $p): ?>
<div class="card"><h3><?=htmlspecialchars($p['name'])?></h3><p><?=htmlspecialchars((string)($p['description'] ?? ''))?></p><p><strong><?=htmlspecialchars((string)$p['price'])?></strong></p><button type="button" onclick="choosePlan(<?= (int)$p['id'] ?>,'<?=htmlspecialchars(addslashes((string)$p['name']), ENT_QUOTES)?>')">Choose</button></div>
<?php endforeach; ?>
</div>
<div class="pay">
<h2>Payment</h2>
<form method="post" action="/payment/start.php">
<div class="row"><label>Plan</label><select name="plan_id" id="plan_id" required><option value="">Choose a plan</option><?php foreach($plans as $p): ?><option value="<?=(int)$p['id']?>"><?=htmlspecialchars($p['name'])?></option><?php endforeach; ?></select></div>
<div class="row"><label>Payment provider</label><select name="provider" required>
<?php if ($mpesaAvailable): ?><option value="mpesa">M-Pesa (Kenya)</option><?php endif; ?>
<?php if ($gcashAvailable): ?><option value="gcash">GCash (Philippines)</option><?php endif; ?>
<?php if (!$mpesaAvailable && !$gcashAvailable): ?><option value="" disabled selected>No provider configured yet</option><?php endif; ?>
</select></div>
<div class="row"><label>Phone number</label><input name="phone" value="<?=htmlspecialchars((string)($_SESSION['phone'] ?? ''))?>" placeholder="e.g. 0712345678 / +63..."></div>
<div class="row"><label>Email (optional)</label><input type="email" name="email" value="<?=htmlspecialchars((string)($_SESSION['email'] ?? ''))?>"></div>
<button type="submit" <?=(!$mpesaAvailable && !$gcashAvailable)?'disabled':''?>>Start payment</button>
</form>
<p class="muted">Internet access is granted only after TaraSec receives and verifies the wallet provider's server-to-server payment confirmation.</p>
</div>
<p><a href="/">Back to TaraSec</a></p>
</div><script>function choosePlan(id,name){document.getElementById('plan_id').value=id;document.querySelector('.pay').scrollIntoView({behavior:'smooth'});}</script></body></html>
