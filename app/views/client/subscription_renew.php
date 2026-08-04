<?php
$pageTitle = 'Renew Subscription';
$activeMenu = '';
$clientForLayout = $client;
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .plan-grid{display:grid;gap:14px;grid-template-columns:1fr}
  @media(min-width:760px){.plan-grid{grid-template-columns:repeat(2,1fr)}}
  .plan{background:#fff;border:2px solid #e2e8f0;border-radius:16px;padding:22px;text-align:center}
  .plan .price{font-size:2rem;font-weight:900;color:var(--peacock)}
  .plan .pay-btn{width:100%;margin-top:14px;padding:13px;border:0;border-radius:10px;font-weight:800;cursor:pointer;background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b}
  .gateway-warning{background:#fef9c3;border:1px solid #fde047;color:#713f12;padding:14px;border-radius:12px;margin-bottom:14px}
  .toast{position:fixed;top:20px;right:20px;background:#16a34a;color:#fff;padding:14px 18px;border-radius:12px;display:none;z-index:2000}
</style>

<div class="card">
  <h2 style="margin:0;color:var(--peacock)">Platform subscription</h2>
  <p style="color:#475569;margin:8px 0 0">
    <?php if ($active): ?>
      Your subscription is active until <strong><?= htmlspecialchars((string)($client['subscription_valid_until'] ?? '')) ?></strong>.
      <a href="<?= APP_URL ?>/dashboard.php">Return to dashboard</a>
    <?php else: ?>
      Your SaaS access has expired. Renew below to unlock your dashboard and QR review links.
    <?php endif; ?>
  </p>
</div>

<?php if (!$razorpayConfigured): ?>
  <div class="gateway-warning">
    <strong>Online renewal is unavailable.</strong> Razorpay has not been configured. Please contact support.
  </div>
<?php endif; ?>

<?php if (empty($plans)): ?>
  <div class="card"><p>No subscription plans configured yet.</p></div>
<?php else: ?>
  <div class="plan-grid">
    <?php foreach ($plans as $plan): ?>
      <div class="plan">
        <h3 style="margin:0;color:var(--peacock)"><?= htmlspecialchars((string)$plan['name']) ?></h3>
        <p style="color:#64748b;font-size:.9rem"><?= htmlspecialchars((string)($plan['billing_period'] ?? '')) ?> · <?= (int)$plan['duration_days'] ?> days access</p>
        <div class="price">&#8377;<?= (int)$plan['price_inr'] ?></div>
        <p style="color:#475569;font-size:.85rem"><?= htmlspecialchars((string)($plan['description'] ?? '')) ?></p>
        <button type="button" class="pay-btn sub-pay" data-plan-id="<?= (int)$plan['id'] ?>"
          <?= !$razorpayConfigured ? 'disabled' : '' ?>>Pay &amp; renew</button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div id="toast" class="toast"></div>

<?php if ($razorpayConfigured): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function(){
  const csrf = <?= json_encode($csrfToken) ?>;
  function toast(msg, ok){
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.style.display = 'block';
    el.style.background = ok ? '#16a34a' : '#dc2626';
    setTimeout(()=>{ el.style.display='none'; }, 4000);
  }
  document.querySelectorAll('.sub-pay').forEach(btn => {
    btn.addEventListener('click', async function(){
      if (btn.disabled) return;
      btn.disabled = true;
      const fd = new FormData();
      fd.append('csrf_token', csrf);
      fd.append('plan_id', btn.getAttribute('data-plan-id') || '0');
      try{
        const res = await fetch('<?= APP_URL ?>/subscription_create_order.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) { toast(data.message || 'Order failed', false); btn.disabled=false; return; }
        const options = {
          key: data.key_id,
          amount: data.amount,
          currency: data.currency,
          name: data.name,
          description: data.description,
          order_id: data.order_id,
          prefill: data.prefill || {},
          theme: data.theme || {},
          handler: async function (response){
            const v = new FormData();
            v.append('csrf_token', csrf);
            v.append('razorpay_order_id', response.razorpay_order_id);
            v.append('razorpay_payment_id', response.razorpay_payment_id);
            v.append('razorpay_signature', response.razorpay_signature);
            const r2 = await fetch('<?= APP_URL ?>/subscription_razorpay_verify.php', { method: 'POST', body: v });
            const d2 = await r2.json();
            if (d2.ok) {
              toast(d2.message || 'Renewed!', true);
              window.location.href = '<?= APP_URL ?>/dashboard.php';
            } else {
              toast(d2.message || 'Verification failed', false);
            }
            btn.disabled = false;
          },
          modal: { ondismiss: function(){ btn.disabled = false; } }
        };
        new Razorpay(options).open();
      }catch(e){
        toast('Network error', false);
        btn.disabled = false;
      }
    });
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
