<?php
$pageTitle = 'Subscription Plans (SaaS)';
$activeMenu = 'subplans';
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .grid-form{display:grid;gap:10px;grid-template-columns:1fr}
  @media(min-width:760px){.grid-form{grid-template-columns:repeat(3,1fr)}}
</style>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Add subscription plan</h3>
  <form method="post">
      <?= csrfField() ?>
    <input type="hidden" name="action" value="create_sub_plan">
    <div class="grid-form">
      <div><label>Name *</label><input name="name" required placeholder="Platform Monthly"></div>
      <div><label>Billing period</label>
        <select name="billing_period">
          <option value="monthly">Monthly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>
      <div><label>Price (INR) *</label><input name="price_inr" type="number" min="1" required></div>
      <div><label>Duration (days) *</label><input name="duration_days" type="number" min="1" value="30" required></div>
      <div><label>Sort order</label><input name="sort_order" type="number" value="0"></div>
      <div><label>Active</label>
        <select name="is_active"><option value="1">Yes</option><option value="0">No</option></select>
      </div>
      <div style="grid-column:1/-1"><label>Description</label><input name="description" placeholder="Platform access fee"></div>
    </div>
    <button class="btn" type="submit" style="margin-top:12px">Create</button>
  </form>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Existing plans</h3>
  <?php if (empty($plans)): ?>
    <p>No plans (or table not migrated).</p>
  <?php else: ?>
    <div style="overflow:auto">
      <table>
        <thead><tr><th>#</th><th>Name</th><th>Period</th><th>Price</th><th>Days</th><th>Active</th><th>Edit</th></tr></thead>
        <tbody>
          <?php foreach ($plans as $p): ?>
            <tr>
              <td><?= (int)$p['id'] ?></td>
              <td><?= htmlspecialchars((string)$p['name']) ?></td>
              <td><?= htmlspecialchars((string)$p['billing_period']) ?></td>
              <td>&#8377;<?= (int)$p['price_inr'] ?></td>
              <td><?= (int)$p['duration_days'] ?></td>
              <td><?= (int)$p['is_active'] === 1 ? 'Yes' : 'No' ?></td>
              <td>
                <form method="post" style="background:#f8fafc;padding:10px;border-radius:10px">
      <?= csrfField() ?>
                  <input type="hidden" name="action" value="update_sub_plan">
                  <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                  <div class="grid-form">
                    <div><label>Name</label><input name="name" value="<?= htmlspecialchars((string)$p['name']) ?>" required></div>
                    <div><label>Period</label>
                      <select name="billing_period">
                        <option value="monthly" <?= ($p['billing_period'] ?? '') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        <option value="yearly" <?= ($p['billing_period'] ?? '') === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                      </select>
                    </div>
                    <div><label>Price</label><input name="price_inr" type="number" min="1" value="<?= (int)$p['price_inr'] ?>" required></div>
                    <div><label>Days</label><input name="duration_days" type="number" min="1" value="<?= (int)$p['duration_days'] ?>" required></div>
                    <div><label>Sort</label><input name="sort_order" type="number" value="<?= (int)$p['sort_order'] ?>"></div>
                    <div><label>Active</label>
                      <select name="is_active">
                        <option value="1" <?= (int)$p['is_active'] === 1 ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= (int)$p['is_active'] === 0 ? 'selected' : '' ?>>No</option>
                      </select>
                    </div>
                    <div style="grid-column:1/-1"><label>Description</label><input name="description" value="<?= htmlspecialchars((string)($p['description'] ?? '')) ?>"></div>
                  </div>
                  <button class="btn btn-edit" type="submit" style="margin-top:8px">Save</button>
                </form>
                <form method="post" style="margin-top:6px" onsubmit="return confirm('Toggle active?')">
      <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_sub_plan">
                  <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-secondary" type="submit">Toggle active</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
