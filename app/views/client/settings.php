<?php
$pageTitle = 'Settings';
$activeMenu = 'settings';
$clientForLayout = $client;
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .grid{display:grid;gap:10px;grid-template-columns:1fr}
  @media(min-width:760px){.grid{grid-template-columns:1fr 1fr}}
  .hint{font-size:.85rem;color:#475569}
  .facility-box{border:1px solid #e2e8f0;background:#f8fafc;border-radius:10px;padding:10px}
  .facility-grid{display:grid;gap:8px;grid-template-columns:1fr}
  @media(min-width:600px){.facility-grid{grid-template-columns:1fr 1fr}}
  .facility-grid label{display:flex;gap:8px;align-items:center;color:#334155;font-weight:500;margin:0}
  .facility-grid input[type="checkbox"]{width:auto;margin:0}
  .facility-empty{color:#64748b;font-size:.9rem;padding:6px 0}
  .facility-loading{color:#64748b;font-size:.9rem}
  .facility-error{color:#dc2626;font-size:.9rem}
</style>

<?php if ($flash !== ''): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <h3>Business Profile</h3>
  <form method="post" id="profileForm">
    <input type="hidden" name="action" value="update_profile">
    <div class="grid">
      <div><label>Business Name *</label><input name="business_name" value="<?= htmlspecialchars((string)$client['business_name']) ?>" required></div>
      <div><label>Mobile</label><input name="mobile" value="<?= htmlspecialchars((string)$client['mobile']) ?>"></div>
      <div style="grid-column:1/-1">
        <label>Extra WhatsApp numbers (comma-separated)</label>
        <input name="extra_whatsapp_numbers" value="<?= htmlspecialchars((string)($client['extra_whatsapp_numbers'] ?? '')) ?>" placeholder="Optional — receive copies on other numbers">
        <div class="hint">Daily review summary and wallet alerts are sent to your primary mobile and every number listed here.</div>
      </div>
      <div>
        <label>Daily WhatsApp summary (10 PM IST)</label>
        <?php $dsOn = (int)($client['daily_summary_whatsapp_enabled'] ?? 1) === 1; ?>
        <select name="daily_summary_whatsapp_enabled">
          <option value="1" <?= $dsOn ? 'selected' : '' ?>>Enabled</option>
          <option value="0" <?= !$dsOn ? 'selected' : '' ?>>Disabled</option>
        </select>
      </div>
      <div>
        <label>Business Category *</label>
        <select id="settingsCategory" name="category_id" required>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= (int)$cat['id'] === (int)$client['category_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat['category_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Changing category will load that category's facilities below.</div>
      </div>
      <div><label>Google Place ID *</label><input name="google_place_id" value="<?= htmlspecialchars((string)$client['google_place_id']) ?>" required></div>
    </div>

    <div><label>Address *</label><textarea name="address" required><?= htmlspecialchars((string)$client['address']) ?></textarea></div>

    <div style="margin-top:6px">
      <label>Facilities / Services Offered</label>
      <div id="facilityBox" class="facility-box">
        <?php if (empty($facilitiesForCategory)): ?>
          <div class="facility-empty">No facilities configured for this category yet.</div>
        <?php else: ?>
          <div class="facility-grid">
            <?php foreach ($facilitiesForCategory as $f):
              $checked = in_array((int)$f['id'], $selectedFacilityIds, true);
            ?>
              <label>
                <input type="checkbox" name="facilities[]" value="<?= (int)$f['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($f['facility_name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="hint">AI will weave 1-2 of these naturally into generated reviews.</div>
    </div>

    <div class="grid" style="margin-top:6px">
      <div>
        <label>Review Tone</label>
        <?php $tone = (string)($client['review_tone'] ?? 'Professional'); ?>
        <select name="review_tone">
          <option value="Professional" <?= $tone === 'Professional' ? 'selected' : '' ?>>Professional</option>
          <option value="Funny" <?= $tone === 'Funny' ? 'selected' : '' ?>>Funny</option>
          <option value="Enthusiastic" <?= $tone === 'Enthusiastic' ? 'selected' : '' ?>>Enthusiastic</option>
          <option value="Casual" <?= $tone === 'Casual' ? 'selected' : '' ?>>Casual</option>
        </select>
      </div>
    </div>

    <div>
      <label>SEO Keywords (up to 3, comma separated)</label>
      <input name="seo_keywords" value="<?= htmlspecialchars((string)($client['seo_keywords'] ?? '')) ?>" placeholder="Best CCTV in Dwarka, Fast Computer Repair, Trusted Service">
      <div class="hint">AI will naturally include 1-2 of these exact keywords in generated reviews.</div>
    </div>

    <div class="hint" style="margin-top:8px">Wallet Balance: <strong><?= (int)($client['wallet_balance'] ?? 0) ?></strong> credits &middot; <a href="<?= APP_URL ?>/client_wallet.php">View wallet history</a></div>
    <button class="btn" type="submit" style="margin-top:10px">Save Profile</button>
  </form>
  <form method="post" style="margin-top:8px">
    <input type="hidden" name="action" value="preview_demo">
    <button class="btn btn-ghost" type="submit">Preview Review Demo</button>
  </form>
  <?php if (!empty($demoReview)): ?>
    <div style="margin-top:10px;border:1px dashed #cbd5e1;background:#fff;padding:12px;border-radius:10px;white-space:pre-wrap"><?= htmlspecialchars($demoReview) ?></div>
  <?php endif; ?>
</div>

<script>
(function(){
  var categorySel = document.getElementById('settingsCategory');
  var facilityBox = document.getElementById('facilityBox');
  if (!categorySel || !facilityBox) return;

  // Snapshot initial selections (so re-selecting the original category restores ticks)
  var initialCategory = String(categorySel.value);
  var initialSelected = <?= json_encode(array_map('intval', $selectedFacilityIds), JSON_UNESCAPED_SLASHES) ?>;

  function esc(s){
    return String(s).replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
    });
  }

  function render(list, selectedIds){
    if (!Array.isArray(list) || list.length === 0){
      facilityBox.innerHTML = '<div class="facility-empty">No facilities configured for this category yet.</div>';
      return;
    }
    var sel = new Set((selectedIds || []).map(Number));
    var html = '<div class="facility-grid">' + list.map(function(f){
      var id = Number(f.id);
      var checked = sel.has(id) ? 'checked' : '';
      return '<label><input type="checkbox" name="facilities[]" value="' + id + '" ' + checked + '><span>' + esc(f.facility_name) + '</span></label>';
    }).join('') + '</div>';
    facilityBox.innerHTML = html;
  }

  categorySel.addEventListener('change', async function(){
    var newCat = String(categorySel.value);
    facilityBox.innerHTML = '<div class="facility-loading">Loading facilities...</div>';
    try{
      var res = await fetch('register_facilities.php?category_id=' + encodeURIComponent(newCat), {
        headers: { 'Accept': 'application/json' }
      });
      var data = await res.json();
      if (!data.ok){
        facilityBox.innerHTML = '<div class="facility-error">Could not load facilities. Please try again.</div>';
        return;
      }
      // If the user reverted to their original category, re-tick their existing picks.
      var preselected = (newCat === initialCategory) ? initialSelected : [];
      render(data.facilities, preselected);
    } catch (e) {
      facilityBox.innerHTML = '<div class="facility-error">Network error while loading facilities.</div>';
    }
  });
})();
</script>

<div class="card">
  <h3>POS / API key</h3>
  <p class="hint">Use with <code><?= htmlspecialchars(APP_URL . '/api/v1/trigger_invite.php') ?></code> — <code>Authorization: Bearer &lt;your_key&gt;</code> and JSON body <code>{"name":"...","mobile":"..."}</code>.</p>
  <?php if (!empty($client['api_key'])): ?>
    <?php
      $k = (string)$client['api_key'];
      $masked = strlen($k) > 10 ? substr($k, 0, 6) . '…' . substr($k, -4) : str_repeat('•', min(12, strlen($k)));
    ?>
    <p><strong>Current key:</strong> <code style="font-size:.85rem"><?= htmlspecialchars($masked) ?></code></p>
  <?php else: ?>
    <p class="hint">No API key yet — generate one to enable POS webhooks.</p>
  <?php endif; ?>
  <form method="post" onsubmit="return confirm('Regenerate API key? The old key stops working immediately.');">
    <input type="hidden" name="action" value="regenerate_api_key">
    <button class="btn" type="submit"><?= !empty($client['api_key']) ? 'Regenerate API key' : 'Generate API key' ?></button>
  </form>
</div>

<div class="card">
  <h3>Change Password</h3>
  <form method="post">
    <input type="hidden" name="action" value="change_password">
    <div class="grid">
      <div><label>Current Password *</label><input type="password" name="current_password" required></div>
      <div><label>New Password *</label><input type="password" name="new_password" required></div>
    </div>
    <div><label>Confirm New Password *</label><input type="password" name="confirm_password" required></div>
    <button class="btn" type="submit" style="margin-top:10px">Change Password</button>
  </form>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
