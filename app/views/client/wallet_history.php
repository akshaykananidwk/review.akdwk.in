<?php
$pageTitle = 'Wallet History';
$activeMenu = 'wallet';
$clientForLayout = $client;
require __DIR__ . '/partials/layout_head.php';
?>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr)">
  <section class="card">
    <div>Current Wallet Balance</div>
    <div class="v">&#8377;<?= (int)$client['wallet_balance'] ?></div>
    <div style="color:#475569;font-size:.85rem">Equivalent to <?= (int)max(1, floor((int)$client['wallet_balance'] / max(1, $pricePerReview))) ?> review(s) at &#8377;<?= (int)$pricePerReview ?>/review.</div>
    <a href="<?= APP_URL ?>/client_recharge.php" class="btn" style="display:inline-block;margin-top:10px">+ Recharge Wallet</a>
  </section>
  <section class="card">
    <div>Total Transactions</div>
    <div class="v"><?= (int)$total ?></div>
  </section>
  <section class="card">
    <div>Price Per Review</div>
    <div class="v">&#8377;<?= (int)$pricePerReview ?></div>
  </section>
</div>

<div class="card">
  <h3>Transaction History</h3>
  <?php if (empty($rows)): ?>
    <p style="color:#475569">No transactions yet. Once you receive sign-up bonus, top-ups or review deductions, they'll appear here.</p>
  <?php else: ?>
    <div style="overflow:auto">
      <table>
        <thead><tr><th>#</th><th>Date</th><th>Type</th><th>Source</th><th>Description</th><th style="text-align:right">Amount</th><th style="text-align:right">Balance After</th></tr></thead>
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
      <?php if ($page > 1): ?><a class="btn btn-ghost" href="?page=<?= (int)($page - 1) ?>">Previous</a><?php endif; ?>
      <span style="color:#475569">Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
      <?php if ($page < $totalPages): ?><a class="btn btn-ghost" href="?page=<?= (int)($page + 1) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
