<?php
$pageTitle = 'Categories & Facilities';
$activeMenu = 'categories';
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .grid{display:grid;gap:10px;grid-template-columns:1fr}
  @media(min-width:900px){.grid{grid-template-columns:1fr 1fr 1fr 1fr}}
  .row-actions{display:flex;gap:6px;flex-wrap:wrap}
  .row-actions form{display:inline-block}
  .pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.78rem;font-weight:700}
  .pill.active{background:#dcfce7;color:#166534}.pill.inactive{background:#fee2e2;color:#991b1b}
</style>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Add New Category</h3>
  <form method="post" class="grid">
      <?= csrfField() ?>
    <input type="hidden" name="action" value="add_category">
    <div style="grid-column:span 3"><label>Category Name</label><input name="category_name" required placeholder="e.g. Cafe, Clinic, Tuition Center"></div>
    <div style="display:flex;align-items:end"><button class="btn" type="submit">Add Category</button></div>
  </form>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Manage Categories</h3>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>ID</th><th>Category Name</th><th>Status</th><th>Update</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (empty($allCategories)): ?>
        <tr><td colspan="5">No categories yet.</td></tr>
      <?php else: foreach ($allCategories as $cat): ?>
        <tr>
          <td><?= (int)$cat['id'] ?></td>
          <td>
            <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <?= csrfField() ?>
              <input type="hidden" name="action" value="update_category">
              <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
              <input name="category_name" value="<?= htmlspecialchars($cat['category_name']) ?>" required style="max-width:280px">
              <select name="is_active" style="max-width:140px">
                <option value="1" <?= (int)$cat['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= (int)$cat['is_active'] === 0 ? 'selected' : '' ?>>Inactive</option>
              </select>
              <button class="btn btn-edit" type="submit">Save</button>
            </form>
          </td>
          <td><span class="pill <?= (int)$cat['is_active'] === 1 ? 'active' : 'inactive' ?>"><?= (int)$cat['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
          <td><small>Linked clients: <?= (int)($cat['client_count'] ?? 0) ?> &middot; Facilities: <?= (int)($cat['facility_count'] ?? 0) ?></small></td>
          <td>
            <div class="row-actions">
              <form method="post" onsubmit="return confirm('Delete category &quot;<?= htmlspecialchars(addslashes($cat['category_name']), ENT_QUOTES) ?>&quot;? This cannot be undone.');">
      <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="category_id" value="<?= (int)$cat['id'] ?>">
                <button class="btn btn-delete" type="submit" title="Delete category">&#x1F5D1; Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <p style="font-size:.85rem;color:#475569;margin:8px 2px 0">A category cannot be deleted while it still has clients or facilities assigned to it.</p>
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Add New Facility</h3>
  <form method="post">
      <?= csrfField() ?>
    <input type="hidden" name="action" value="add_facility">
    <div class="grid">
      <div style="grid-column:span 2">
        <label>Category</label>
        <select name="category_id" required>
          <option value="">Select Category</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="grid-column:span 2">
        <label>Facility Name</label>
        <input name="facility_name" required placeholder="e.g. 24x7 Emergency, Home Delivery, Same-Day Repair">
      </div>
    </div>
    <button class="btn" type="submit" style="margin-top:10px">Add Facility</button>
  </form>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Manage Facilities</h3>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>ID</th><th>Category</th><th>Facility</th><th>Status</th><th>Update</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (empty($facilities)): ?>
        <tr><td colspan="6">No facilities yet.</td></tr>
      <?php else: foreach ($facilities as $f): ?>
        <tr>
          <form method="post">
      <?= csrfField() ?>
            <input type="hidden" name="action" value="update_facility">
            <input type="hidden" name="facility_id" value="<?= (int)$f['id'] ?>">
            <td><?= (int)$f['id'] ?></td>
            <td>
              <select name="category_id" required>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat['id'] ?>" <?= (int)$cat['id'] === (int)$f['category_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['category_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input name="facility_name" value="<?= htmlspecialchars($f['facility_name']) ?>" required></td>
            <td>
              <select name="is_active">
                <option value="1" <?= (int)$f['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= (int)$f['is_active'] === 0 ? 'selected' : '' ?>>Inactive</option>
              </select>
            </td>
            <td><button class="btn btn-edit" type="submit">Save</button></td>
          </form>
          <td>
            <form method="post" onsubmit="return confirm('Delete facility &quot;<?= htmlspecialchars(addslashes($f['facility_name']), ENT_QUOTES) ?>&quot;? This cannot be undone.');">
      <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_facility">
              <input type="hidden" name="facility_id" value="<?= (int)$f['id'] ?>">
              <button class="btn btn-delete" type="submit" title="Delete facility">&#x1F5D1; Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
