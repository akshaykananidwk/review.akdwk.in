<?php
$pageTitle = 'Recharge Plans';
$activeMenu = 'plans';
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .stats-grid{display:grid;gap:12px;grid-template-columns:1fr;margin-bottom:14px}
  @media(min-width:760px){.stats-grid{grid-template-columns:repeat(3,1fr)}}
  .stat-v{font-size:1.8rem;font-weight:800;color:var(--peacock)}
  .grid-form{display:grid;gap:10px;grid-template-columns:1fr}
  @media(min-width:760px){.grid-form{grid-template-columns:repeat(3,1fr)}}
  .pill-on{background:#dcfce7;color:#166534;padding:3px 10px;border-radius:999px;font-weight:700;font-size:.78rem}
  .pill-off{background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:999px;font-weight:700;font-size:.78rem}
  .pill-pop{background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:999px;font-weight:700;font-size:.78rem;margin-left:6px}
  details summary{cursor:pointer;font-weight:700;color:var(--peacock);padding:6px 0}
</style>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="stats-grid">
  <section class="card"><div>Total Revenue</div><div class="stat-v">&#8377;<?= number_format($totalPaid) ?></div></section>
  <section class="card"><div>Successful Payments</div><div class="stat-v"><?= (int)$countPaid ?></div></section>
  <section class="card"><div>Total Attempts</div><div class="stat-v"><?= (int)$countAttempts ?></div></section>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Add a New Recharge Plan</h3>
  <p style="color:#475569;font-size:.9rem;margin:0 0 12px">Like mobile recharge: each plan adds <strong>wallet credits</strong> plus <strong>validity days</strong> (30 = 1 month, 365 = 1 year). Clients use one recharge page only.</p>
  <form method="post">
      <?= csrfField() ?>
    <input type="hidden" name="action" value="create_plan">
    <div class="grid-form">
      <div><label>Plan Name *</label><input name="name" required placeholder="e.g. Growth Pack"></div>
      <div><label>Price (INR) *</label><input name="price_inr" type="number" min="1" required placeholder="1000"></div>
      <div><label>Validity (days) *</label><input name="duration_days" type="number" min="1" value="30" required title="30 = ~1 month, 365 = ~1 year"></div>
      <div><label>Sort Order</label><input name="sort_order" type="number" value="0"></div>
      <div><label>Credits *</label><input name="credits" type="number" min="1" required placeholder="1000"></div>
      <div><label>Bonus Credits</label><input name="bonus_credits" type="number" min="0" value="0"></div>
      <div><label>Status</label>
        <select name="is_active"><option value="1">Active</option><option value="0">Inactive</option></select>
      </div>
      <div style="grid-column:1/-1"><label>Description</label><input name="description" placeholder="Best for trying the system"></div>
      <div><label><input type="checkbox" name="is_popular" value="1" style="width:auto;margin-right:6px"> Mark as Most Popular</label></div>
    </div>
    <button class="btn" type="submit" style="margin-top:12px">Create Plan</button>
  </form>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">All Plans</h3>
  <?php if (empty($plans)): ?>
    <p style="color:#475569">No plans yet. Add your first plan above.</p>
  <?php else: ?>
    <div style="overflow:auto">
      <table>
        <thead>
          <tr>
            <th>#</th><th>Name</th><th>Price</th><th>Validity</th><th>Credits</th><th>Bonus</th><th>Sort</th><th>Status</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($plans as $p): ?>
            <tr>
              <td><?= (int)$p['id'] ?></td>
              <td>
                <strong><?= htmlspecialchars((string)$p['name']) ?></strong>
                <?php if ((int)$p['is_popular'] === 1): ?><span class="pill-pop">POPULAR</span><?php endif; ?>
                <div style="color:#64748b;font-size:.85rem"><?= htmlspecialchars((string)($p['description'] ?? '')) ?></div>
              </td>
              <td>&#8377;<?= (int)$p['price_inr'] ?></td>
              <td><?= (int)($p['duration_days'] ?? 30) ?> days</td>
              <td><?= (int)$p['credits'] ?></td>
              <td><?= (int)$p['bonus_credits'] ?></td>
              <td><?= (int)$p['sort_order'] ?></td>
              <td><?= (int)$p['is_active'] === 1 ? '<span class="pill-on">ACTIVE</span>' : '<span class="pill-off">OFF</span>' ?></td>
              <td>
                <details>
                  <summary>Edit</summary>
                  <form method="post" style="margin-top:8px;background:#f8fafc;padding:10px;border-radius:10px">
      <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_plan">
                    <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                    <div class="grid-form">
                      <div><label>Name</label><input name="name" value="<?= htmlspecialchars((string)$p['name']) ?>" required></div>
                      <div><label>Price (INR)</label><input name="price_inr" type="number" min="1" value="<?= (int)$p['price_inr'] ?>" required></div>
                      <div><label>Validity (days)</label><input name="duration_days" type="number" min="1" value="<?= (int)($p['duration_days'] ?? 30) ?>" required></div>
                      <div><label>Sort Order</label><input name="sort_order" type="number" value="<?= (int)$p['sort_order'] ?>"></div>
                      <div><label>Credits</label><input name="credits" type="number" min="1" value="<?= (int)$p['credits'] ?>" required></div>
                      <div><label>Bonus Credits</label><input name="bonus_credits" type="number" min="0" value="<?= (int)$p['bonus_credits'] ?>"></div>
                      <div><label>Status</label>
                        <select name="is_active">
                          <option value="1" <?= (int)$p['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                          <option value="0" <?= (int)$p['is_active'] === 0 ? 'selected' : '' ?>>Inactive</option>
                        </select>
                      </div>
                      <div style="grid-column:1/-1"><label>Description</label><input name="description" value="<?= htmlspecialchars((string)($p['description'] ?? '')) ?>"></div>
                      <div><label><input type="checkbox" name="is_popular" value="1" style="width:auto;margin-right:6px" <?= (int)$p['is_popular'] === 1 ? 'checked' : '' ?>> Most Popular</label></div>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap">
                      <button class="btn btn-edit" type="submit">Save Changes</button>
                    </div>
                  </form>
                </details>
                <form method="post" style="display:inline-block;margin-top:6px" onsubmit="return confirm('Toggle this plan active state?')">
      <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_plan">
                  <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="btn btn-toggle"><?= (int)$p['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <form method="post" style="display:inline-block;margin-top:6px" onsubmit="return confirm('Delete this plan permanently? This will not refund any past payments.')">
      <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_plan">
                  <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="btn btn-delete">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">How It Works</h3>
  <ol style="color:#475569;line-height:1.7;margin:0 0 0 18px;padding:0">
    <li>Plans you create here appear on the client <strong>Recharge Wallet</strong> page (no separate subscription menu).</li>
    <li>The client picks a plan and pays via Razorpay (UPI, Cards, NetBanking, Wallets).</li>
    <li>On successful payment: wallet gets <code>credits + bonus_credits</code> and validity extends by <code>duration_days</code> (stacked if already active).</li>
    <li>An invoice is automatically sent to the client's WhatsApp number.</li>
    <li>Configure Razorpay credentials and webhook secret under <em>Global Settings → Razorpay Payment Gateway</em>.</li>
  </ol>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
