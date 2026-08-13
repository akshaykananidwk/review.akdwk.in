<?php
$pageTitle = 'Create Reseller';
$activeMenu = 'create_reseller';
require __DIR__ . '/partials/layout_head.php';
?>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">New reseller account</h3>
  <p style="color:#64748b;font-size:.9rem;margin:0 0 14px">
    Creates an <strong>admins</strong> row with <code>role = reseller</code>. The reseller uses the same <a href="<?= APP_URL ?>/login.php">login page</a> as everyone else and is redirected to the reseller panel.
  </p>
  <form method="post">
      <?= csrfField() ?>
    <div style="display:grid;gap:10px;max-width:420px">
      <div>
        <label>Full name *</label>
        <input name="full_name" required maxlength="120" value="<?= htmlspecialchars((string)($_POST['full_name'] ?? '')) ?>" autocomplete="name">
      </div>
      <div>
        <label>Email *</label>
        <input type="email" name="email" required maxlength="190" value="<?= htmlspecialchars((string)($_POST['email'] ?? '')) ?>" autocomplete="off">
      </div>
      <div>
        <label>Password * (min 8 characters)</label>
        <input type="password" name="password" required minlength="8" autocomplete="new-password">
      </div>
    </div>
    <button class="btn" type="submit" style="margin-top:14px">Create reseller</button>
  </form>
  <p style="margin-top:18px"><a href="<?= APP_URL ?>/admin_reseller_wallet.php">→ Credit reseller wallets</a></p>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
