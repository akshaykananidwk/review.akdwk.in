<?php
$pageTitle = 'Send Review Invite';
$activeMenu = 'invite';
$clientForLayout = $client;
require __DIR__ . '/partials/layout_head.php';
?>

<?php if ($flash !== ''): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <h2 style="margin:0;color:var(--peacock)">WhatsApp review invite</h2>
  <p style="color:#475569">Send your standard review link to a customer by WhatsApp (same link as your QR code).</p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <label>Customer name</label>
    <input name="customer_name" required maxlength="120" placeholder="Customer name">
    <label>Customer mobile</label>
    <input name="customer_mobile" required maxlength="20" placeholder="9876543210">
    <p style="font-size:.85rem;color:#64748b;margin-top:6px">Message template is editable under Super Admin → Global Settings.</p>
    <button class="btn" type="submit" style="margin-top:12px">Send WhatsApp invite</button>
  </form>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
