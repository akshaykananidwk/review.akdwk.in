<?php
require_once __DIR__ . '/../../helpers/subscription_helper.php';

$pageTitle = 'Wallet — ' . ($client['business_name'] ?? '');
$activeMenu = 'businesses';
$validUntil = trim((string)($client['subscription_valid_until'] ?? ''));
$accessBadge = validityStatusBadge($validUntil !== '' ? $validUntil : null);
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .wallet-grid{display:grid;gap:12px;grid-template-columns:1fr}
  @media(min-width:760px){.wallet-grid{grid-template-columns:1.4fr 1fr}}
  .pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700}
  .pill-credit{background:#dcfce7;color:#166534}
  .pill-debit{background:#fee2e2;color:#991b1b}
  .small{font-size:.85rem;color:#475569}
  .status{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.8rem;font-weight:700}
  .active{background:#dcfce7;color:#166534}.suspended{background:#fee2e2;color:#991b1b}
  .validity-panel{display:none;margin-top:8px;padding:10px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0}
  .validity-panel.show{display:block}
</style>

<?php if ($flash !== ''): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0;color:#005f8f"><?= htmlspecialchars($client['business_name']) ?></h2>
      <div class="small"><?= htmlspecialchars($client['email']) ?> &middot; <?= htmlspecialchars((string)$client['mobile']) ?> &middot; <?= htmlspecialchars($client['category_name']) ?></div>
    </div>
    <a href="<?= APP_URL ?>/admin_businesses.php" class="btn btn-ghost">&larr; Back to businesses</a>
  </div>
</div>

<div class="wallet-grid">
  <section class="card">
    <h3 style="margin:0 0 8px;color:#005f8f">Wallet Balance</h3>
    <div style="font-size:2.6rem;font-weight:800;color:#005f8f">&#8377;<?= (int)$client['wallet_balance'] ?></div>
    <div class="small">Plan valid until: <strong><?= $validUntil !== '' ? htmlspecialchars($validUntil) : '—' ?></strong>
      <span class="status <?= htmlspecialchars($accessBadge['class']) ?>" style="margin-left:6px"><?= htmlspecialchars($accessBadge['label']) ?></span>
    </div>
    <div class="small">Total transactions: <strong><?= (int)$total ?></strong></div>
  </section>

  <section class="card">
    <h3 style="margin:0 0 8px;color:#005f8f">Admin Recharge (offline / friend)</h3>
    <p class="small" style="margin:0 0 10px">Add credits without Razorpay — e.g. ₹500 for a friend. Client gets WhatsApp: amount credited + new balance + valid until date.</p>
    <form method="post" style="display:grid;gap:8px" id="topupForm">
      <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
      <input type="hidden" name="action" value="admin_topup">
      <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
        <div><label>Amount (₹ = credits)</label><input type="number" name="amount" min="1" step="1" required placeholder="500"></div>
        <div><label>Description (optional)</label><input type="text" name="description" placeholder="e.g. Cash payment — friend"></div>
      </div>
      <div>
        <label>Validity on recharge</label>
        <select name="validity_mode" id="validity_mode">
          <option value="extend_days">Extend from today (add days)</option>
          <option value="set_date">Set exact expiry date</option>
        </select>
      </div>
      <div id="panel_extend_days" class="validity-panel show">
        <label>Add days (mobile recharge style)</label>
        <input type="number" name="validity_days" min="0" step="1" value="30" placeholder="30 = 1 month, 365 = 1 year">
        <div class="small" style="margin-top:4px">0 = only credits, validity unchanged. Otherwise days stack on current valid date.</div>
      </div>
      <div id="panel_set_date" class="validity-panel">
        <label>Expires on</label>
        <input type="date" name="validity_until_date" id="validity_until_date">
        <div class="small" style="margin-top:4px">Platform active until end of this date.</div>
      </div>
      <button class="btn btn-primary" type="submit">Recharge &amp; notify WhatsApp</button>
    </form>
  </section>
</div>

<div class="card">
  <h3 style="margin:0 0 8px;color:#005f8f">Deduct credits</h3>
  <form method="post" style="display:grid;gap:8px;max-width:480px">
    <input type="hidden" name="client_id" value="<?= (int)$client['id'] ?>">
    <div style="display:grid;gap:8px;grid-template-columns:1fr 1fr">
      <div><label>Amount</label><input type="number" name="amount" min="1" step="1" required></div>
      <div><label>&nbsp;</label>
        <input type="hidden" name="action" value="admin_deduct">
        <button class="btn btn-ghost" type="submit" style="width:100%">Deduct</button>
      </div>
    </div>
    <div><label>Description</label><input type="text" name="description" placeholder="Reason for deduction"></div>
  </form>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:#005f8f">Transaction History</h3>
  <?php if (empty($rows)): ?>
    <p class="small">No transactions yet for this client.</p>
  <?php else: ?>
    <div style="overflow:auto">
      <table>
        <thead><tr><th>#</th><th>Date</th><th>Type</th><th>Source</th><th>Description</th><th>Admin</th><th style="text-align:right">Amount</th><th style="text-align:right">Balance After</th></tr></thead>
        <tbody>
          <?php $sr = (int)(($page - 1) * 25) + 1; foreach ($rows as $r): ?>
            <tr>
              <td><?= $sr++ ?></td>
              <td><?= htmlspecialchars((string)$r['created_at']) ?></td>
              <td>
                <?php if ($r['txn_type'] === 'credit'): ?>
                  <span class="pill pill-credit">Credit</span>
                <?php else: ?>
                  <span class="pill pill-debit">Debit</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars(WalletService::describeSource((string)$r['source'])) ?></td>
              <td><?= htmlspecialchars((string)($r['description'] ?? '')) ?></td>
              <td class="small"><?= htmlspecialchars((string)($r['admin_email'] ?? '')) ?></td>
              <td style="text-align:right;font-weight:700;color:<?= $r['txn_type'] === 'credit' ? '#166534' : '#991b1b' ?>">
                <?= $r['txn_type'] === 'credit' ? '+' : '-' ?>&#8377;<?= (int)$r['amount'] ?>
              </td>
              <td style="text-align:right">&#8377;<?= (int)$r['balance_after'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:8px;justify-content:center;align-items:center;margin-top:12px;flex-wrap:wrap">
      <?php if ($page > 1): ?><a class="btn btn-ghost" href="?client_id=<?= (int)$client['id'] ?>&page=<?= (int)($page - 1) ?>">Previous</a><?php endif; ?>
      <span class="small">Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
      <?php if ($page < $totalPages): ?><a class="btn btn-ghost" href="?client_id=<?= (int)$client['id'] ?>&page=<?= (int)($page + 1) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var mode = document.getElementById('validity_mode');
  var pDays = document.getElementById('panel_extend_days');
  var pDate = document.getElementById('panel_set_date');
  if (!mode) return;
  function sync(){
    var v = mode.value;
    pDays.classList.toggle('show', v === 'extend_days');
    pDate.classList.toggle('show', v === 'set_date');
  }
  mode.addEventListener('change', sync);
  sync();
  var d = document.getElementById('validity_until_date');
  if (d && <?= json_encode($validUntil) ?> !== '') {
    d.value = <?= json_encode($validUntil) ?>;
  }
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
