<?php
$pageTitle = 'Reseller Wallets';
$activeMenu = 'reseller_wallet';
require __DIR__ . '/partials/layout_head.php';
?>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Credit reseller master wallet</h3>
  <p style="color:#64748b;font-size:.9rem">Agencies use this balance to manually transfer credits into sub-client wallets from their reseller panel.</p>
  <p style="color:#64748b;font-size:.9rem;margin:0 0 14px">
    <a class="btn btn-secondary" href="<?= APP_URL ?>/admin_create_reseller.php" style="display:inline-block;padding:8px 14px;text-decoration:none">+ Create reseller</a>
  </p>
  <form method="post">
      <?= csrfField() ?>
    <input type="hidden" name="action" value="credit_reseller">
    <label>Reseller</label>
    <select name="reseller_admin_id" required>
      <option value="">Select reseller account</option>
      <?php foreach ($resellers as $r): ?>
        <option value="<?= (int)$r['id'] ?>">
          <?= htmlspecialchars((string)$r['full_name']) ?> (<?= htmlspecialchars((string)$r['email']) ?>) — balance <?= (int)($r['reseller_wallet_balance'] ?? 0) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label style="margin-top:10px">Credits to add</label>
    <input type="number" name="amount" min="1" step="1" required>
    <label style="margin-top:10px">Note (optional)</label>
    <input name="note" placeholder="Invoice #...">
    <button class="btn" type="submit" style="margin-top:12px">Credit wallet</button>
  </form>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
