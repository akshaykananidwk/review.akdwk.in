<?php
require_once __DIR__ . '/../../helpers/subscription_helper.php';

$pageTitle = 'Recharge Wallet';
$activeMenu = 'recharge';
$clientForLayout = $client;
$validUntil = trim((string)($client['subscription_valid_until'] ?? ''));
$isRenewFlow = isset($_GET['renew']) && (string)$_GET['renew'] === '1';
$accessActive = $validUntil !== '' && strcmp($validUntil, date('Y-m-d')) >= 0;
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .plan-grid{display:grid;gap:14px;grid-template-columns:1fr}
  @media(min-width:760px){.plan-grid{grid-template-columns:repeat(3,1fr)}}
  .plan{position:relative;background:#fff;border:2px solid #e2e8f0;border-radius:16px;padding:22px;text-align:center;transition:all .2s}
  .plan:hover{border-color:var(--deepyellow);transform:translateY(-2px);box-shadow:0 12px 30px rgba(0,95,143,.12)}
  .plan.popular{border-color:var(--deepyellow);box-shadow:0 14px 32px rgba(244,180,0,.18)}
  .plan .badge{position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:var(--deepyellow);color:#1e293b;padding:4px 14px;border-radius:999px;font-weight:800;font-size:.75rem;letter-spacing:.5px}
  .plan h3{margin:6px 0 4px;color:var(--peacock);font-size:1.1rem}
  .plan .desc{color:#64748b;font-size:.85rem;min-height:34px}
  .plan .price{font-size:2.4rem;font-weight:900;color:var(--peacock);line-height:1}
  .plan .price small{font-size:.9rem;color:#64748b;font-weight:600}
  .plan .credits{margin:14px 0 6px;font-weight:700;color:#0f172a}
  .plan .bonus{display:inline-block;background:#dcfce7;color:#166534;padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700;margin-bottom:10px}
  .plan .pay-btn{width:100%;margin-top:14px;padding:13px;border:0;border-radius:10px;font-weight:800;cursor:pointer;background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b;font-size:1rem}
  .plan .pay-btn:disabled{opacity:.6;cursor:wait}
  .gateway-warning{background:#fef9c3;border:1px solid #fde047;color:#713f12;padding:14px;border-radius:12px;margin-bottom:14px}
  .pill-paid{background:#dcfce7;color:#166534}
  .pill-failed{background:#fee2e2;color:#991b1b}
  .pill-created{background:#fef3c7;color:#92400e}
  .pill-cancelled,.pill-refunded{background:#e2e8f0;color:#475569}
  .toast{position:fixed;top:20px;right:20px;background:#0f172a;color:#fff;padding:14px 18px;border-radius:12px;box-shadow:0 12px 28px rgba(0,0,0,.25);z-index:2000;display:none;max-width:320px}
  .toast.show{display:block;animation:slideIn .3s}
  .toast.success{background:#16a34a}
  .toast.error{background:#dc2626}
  @keyframes slideIn{from{transform:translateX(60px);opacity:0}to{transform:translateX(0);opacity:1}}
</style>

<?php if ($isRenewFlow && !$accessActive): ?>
  <div class="gateway-warning" style="background:#fef2f2;border-color:#fecaca;color:#991b1b">
    <strong>Your platform validity has expired.</strong> Recharge any plan below to restore QR reviews and dashboard access (credits + validity, like mobile recharge).
  </div>
<?php elseif ($validUntil !== ''): ?>
  <div class="msg" style="margin-bottom:14px">
    Platform active until <strong><?= htmlspecialchars($validUntil) ?></strong>.
    <?php if (!$accessActive): ?> (expired — recharge to continue)<?php endif; ?>
  </div>
<?php endif; ?>

<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
  <section class="card"><div>Wallet Balance</div><div class="v">&#8377;<?= (int)$client['wallet_balance'] ?></div></section>
  <section class="card"><div>Price Per Review</div><div class="v">&#8377;<?= (int)$pricePerReview ?></div></section>
  <section class="card"><div>Reviews You Can Run Now</div><div class="v"><?= (int)floor((int)$client['wallet_balance'] / max(1, $pricePerReview)) ?></div></section>
  <section class="card"><div>Valid Until</div><div class="v" style="font-size:1.15rem"><?= $validUntil !== '' ? htmlspecialchars($validUntil) : '—' ?></div></section>
</div>

<div class="card">
  <h3>Recharge Your Wallet</h3>
  <p style="color:#475569;margin:0 0 14px">Choose a plan (credits + validity days). Payment is via Razorpay. On success you get wallet credits and validity extended — same as mobile recharge (30 days, 365 days, etc.).</p>

  <?php if (!$razorpayConfigured): ?>
    <div class="gateway-warning">
      <strong>Online recharge is currently unavailable.</strong> The Razorpay payment gateway has not been configured by the administrator yet. Please contact support to top up manually.
    </div>
  <?php endif; ?>

  <?php if (empty($plans)): ?>
    <p style="color:#64748b">No active plans available right now. Please check back soon.</p>
  <?php else: ?>
    <div class="plan-grid">
      <?php foreach ($plans as $plan): ?>
        <?php $totalCredits = (int)$plan['credits'] + (int)$plan['bonus_credits']; ?>
        <div class="plan <?= (int)$plan['is_popular'] === 1 ? 'popular' : '' ?>">
          <?php if ((int)$plan['is_popular'] === 1): ?><span class="badge">MOST POPULAR</span><?php endif; ?>
          <h3><?= htmlspecialchars((string)$plan['name']) ?></h3>
          <div class="desc"><?= htmlspecialchars((string)($plan['description'] ?? '')) ?></div>
          <div class="price">&#8377;<?= (int)$plan['price_inr'] ?><br><small>one-time</small></div>
          <div class="credits"><?= (int)$plan['credits'] ?> credits</div>
          <?php if ((int)$plan['bonus_credits'] > 0): ?>
            <span class="bonus">+ <?= (int)$plan['bonus_credits'] ?> bonus</span>
          <?php endif; ?>
          <div style="color:#475569;font-size:.85rem">≈ <?= (int)floor($totalCredits / max(1, $pricePerReview)) ?> reviews</div>
          <div style="color:#005f8f;font-size:.88rem;font-weight:700;margin-top:6px">
            📅 <?= htmlspecialchars(formatValidityDaysLabel(max(1, (int)($plan['duration_days'] ?? 30)))) ?>
          </div>
          <button type="button"
                  class="pay-btn"
                  data-plan-id="<?= (int)$plan['id'] ?>"
                  data-plan-name="<?= htmlspecialchars((string)$plan['name']) ?>"
                  <?= !$razorpayConfigured ? 'disabled' : '' ?>>
            Pay &#8377;<?= (int)$plan['price_inr'] ?>
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Recent Payments</h3>
  <?php if (empty($payments)): ?>
    <p style="color:#475569">You haven't made any online payments yet. Start with a plan above.</p>
  <?php else: ?>
    <div style="overflow:auto">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Reference</th>
            <th style="text-align:right">Amount</th>
            <th style="text-align:right">Credits</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= htmlspecialchars((string)($p['paid_at'] ?? $p['created_at'])) ?></td>
              <td style="font-family:monospace;font-size:.85rem"><?= htmlspecialchars((string)($p['gateway_payment_id'] ?? '—')) ?></td>
              <td style="text-align:right;font-weight:700">&#8377;<?= (int)$p['amount_inr'] ?></td>
              <td style="text-align:right">
                <?php $tot = (int)$p['credits_credited'] + (int)$p['bonus_credited']; ?>
                <?= $tot > 0 ? '+' . $tot : '—' ?>
              </td>
              <td><span class="pill pill-<?= htmlspecialchars((string)$p['status']) ?>"><?= htmlspecialchars(ucfirst((string)$p['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div id="toast" class="toast"></div>

<?php if ($razorpayConfigured): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function(){
  const csrf = <?= json_encode($csrfToken) ?>;
  const verifyUrl = <?= json_encode(APP_URL . '/razorpay_verify.php') ?>;
  const orderUrl  = <?= json_encode(APP_URL . '/client_recharge.php') ?>;

  const toast = document.getElementById('toast');
  function showToast(msg, type){
    toast.textContent = msg;
    toast.className = 'toast show ' + (type || '');
    setTimeout(function(){ toast.className = 'toast'; }, 5000);
  }

  document.querySelectorAll('.pay-btn').forEach(function(btn){
    btn.addEventListener('click', async function(){
      const planId = btn.getAttribute('data-plan-id');
      const planName = btn.getAttribute('data-plan-name');
      const originalText = btn.textContent;
      btn.disabled = true;
      btn.textContent = 'Preparing payment…';

      try {
        const fd = new FormData();
        fd.append('action', 'create_order');
        fd.append('plan_id', planId);
        fd.append('csrf_token', csrf);

        const r = await fetch(orderUrl, { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
        const data = await r.json();

        if (!data.ok) {
          showToast(data.message || 'Could not start payment.', 'error');
          btn.disabled = false;
          btn.textContent = originalText;
          return;
        }

        const options = {
          key:         data.key_id,
          amount:      data.amount,
          currency:    data.currency,
          name:        data.name,
          description: data.description,
          order_id:    data.order_id,
          prefill:     data.prefill,
          theme:       data.theme,
          handler: async function(response){
            try {
              const vfd = new FormData();
              vfd.append('csrf_token', csrf);
              vfd.append('razorpay_order_id',   response.razorpay_order_id);
              vfd.append('razorpay_payment_id', response.razorpay_payment_id);
              vfd.append('razorpay_signature',  response.razorpay_signature);
              const vr = await fetch(verifyUrl, { method: 'POST', body: vfd, headers: { 'Accept': 'application/json' } });
              const vdata = await vr.json();
              if (vdata.ok) {
                showToast(vdata.message || 'Payment successful!', 'success');
                setTimeout(function(){ window.location.reload(); }, 1500);
              } else {
                showToast('Verification failed: ' + (vdata.message || 'Unknown error'), 'error');
                btn.disabled = false;
                btn.textContent = originalText;
              }
            } catch(e) {
              showToast('Verification error. If your wallet does not update in 1 minute, please contact support.', 'error');
            }
          },
          modal: {
            ondismiss: function(){
              btn.disabled = false;
              btn.textContent = originalText;
            }
          }
        };
        const rzp = new Razorpay(options);
        rzp.on('payment.failed', function(resp){
          showToast('Payment failed: ' + (resp.error && resp.error.description ? resp.error.description : 'Unknown'), 'error');
          btn.disabled = false;
          btn.textContent = originalText;
        });
        rzp.open();
      } catch(e){
        showToast('Network error. Please try again.', 'error');
        btn.disabled = false;
        btn.textContent = originalText;
      }
    });
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
