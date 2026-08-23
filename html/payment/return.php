<?php
require_once __DIR__ . '/payment_lib.php';
$id = (int)($_GET['id'] ?? 0);
try {
    $payment = paymentLoad($conn, $id);
} catch (Throwable $e) {
    http_response_code(404);
    $payment = null;
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WiFi payment</title>
<style>body{font-family:system-ui,sans-serif;max-width:650px;margin:4rem auto;padding:1rem}.box{border:1px solid #ccc;border-radius:12px;padding:1.5rem}.pending{color:#8a6200}.paid{color:#08752f}.failed{color:#a01818}</style></head><body>
<div class="box">
<?php if (!$payment): ?>
<h2>Payment not found</h2>
<?php else: $status = (string)$payment['status']; ?>
<h2>WiFi payment</h2>
<p>Provider: <strong><?=htmlspecialchars(strtoupper($payment['provider']))?></strong></p>
<p>Reference: <code><?=htmlspecialchars($payment['providerRequestId'])?></code></p>
<p class="<?=htmlspecialchars($status)?>">Status: <strong id="paymentStatus"><?=htmlspecialchars($status)?></strong></p>
<?php if ($status === 'pending'): ?><p id="hint">Complete the payment on your phone. This page will update after the provider confirms it.</p><?php endif; ?>
<?php if ($status === 'paid'): ?><p>Your WiFi plan is active. You may return to the captive portal.</p><?php endif; ?>
<?php if ($status === 'failed'): ?><p><?=htmlspecialchars((string)$payment['failureReason'])?></p><?php endif; ?>
<p><a href="/">Return to TaraSec WiFi</a></p>
<script>
<?php if ($status === 'pending'): ?>
setInterval(async()=>{try{const r=await fetch('/payment/status.php?id=<?=$id?>',{cache:'no-store'});const j=await r.json();if(j.status&&j.status!=='pending') location.reload();}catch(e){}},3000);
<?php endif; ?>
</script>
<?php endif; ?>
</div></body></html>
