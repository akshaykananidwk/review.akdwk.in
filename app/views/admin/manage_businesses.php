<?php
require_once __DIR__ . '/../../helpers/subscription_helper.php';

$pageTitle = 'Manage Businesses';
$activeMenu = 'businesses';
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .status{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.8rem;font-weight:700}
  .active{background:#dcfce7;color:#166534}.suspended{background:#fee2e2;color:#991b1b}
  .edit-grid{display:grid;gap:10px;grid-template-columns:1fr}
  @media(min-width:900px){.edit-grid{grid-template-columns:1fr 1fr}}
  .actions{display:flex;gap:8px;flex-wrap:wrap}
</style>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Edit Business</h3>
  <form method="post">
    <input type="hidden" name="action" value="update_client">
    <div class="edit-grid">
      <div><label>Business</label><select id="edit_client_id" name="client_id" required><option value="">Select Business</option><?php foreach($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['business_name']) ?> (<?= htmlspecialchars($c['email']) ?>)</option><?php endforeach; ?></select></div>
      <div><label>Owner Name</label><input id="edit_owner_name" name="owner_name"></div>
      <div><label>Business Name</label><input id="edit_business_name" name="business_name" required></div>
      <div><label>Email</label><input id="edit_email" name="email" type="email" required></div>
      <div><label>Mobile</label><input id="edit_mobile" name="mobile"></div>
      <div style="grid-column:1/-1">
        <label>Extra WhatsApp numbers (comma-separated)</label>
        <input id="edit_extra_whatsapp_numbers" name="extra_whatsapp_numbers" placeholder="9876543210, 9123456789">
        <div style="font-size:.82rem;color:#64748b;margin-top:4px">Daily summaries and wallet alerts go to the primary mobile plus these numbers.</div>
      </div>
      <div>
        <label>Daily WhatsApp summary</label>
        <select id="edit_daily_summary_whatsapp_enabled" name="daily_summary_whatsapp_enabled">
          <option value="1">Enabled (10 PM IST)</option>
          <option value="0">Disabled</option>
        </select>
      </div>
      <div><label>Category</label><select id="edit_category_id" name="category_id" required><?php foreach($categories as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Google Place ID</label><input id="edit_place_id" name="google_place_id" required></div>
      <div><label>Address</label><textarea id="edit_address" name="address" required></textarea></div>
      <div><label>Custom API Key</label><input id="edit_custom_ai_api_key" name="custom_ai_api_key"></div>
        <div><label>AI Model Version</label><select id="edit_review_model_version" name="review_model_version"><option value="gemini-2.0-flash">gemini-2.0-flash</option><option value="gemini-1.5-flash">gemini-1.5-flash</option><option value="gemini-1.5-pro">gemini-1.5-pro</option></select></div>
        <div><label>Review Logic Type</label><select id="edit_review_logic_type" name="review_logic_type"><option value="balanced">Balanced</option><option value="premium">Premium</option><option value="simple">Simple</option></select></div>
        <div><label>Review Tone</label><select id="edit_review_tone" name="review_tone"><option value="Professional">Professional</option><option value="Friendly">Friendly</option><option value="Enthusiastic">Enthusiastic</option></select></div>
        <div><label>Status</label><select id="edit_is_active" name="is_active"><option value="1">Active</option><option value="0">Suspended</option></select></div>
      <div>
        <label>Plan expires on (valid until)</label>
        <input type="date" id="edit_subscription_valid_until" name="subscription_valid_until">
        <div style="font-size:.82rem;color:#64748b;margin-top:4px">Set the exact date when QR/dashboard access ends. Leave empty to clear. Recharge can also extend this automatically.</div>
      </div>
    </div>
    <button class="btn btn-primary" type="submit" style="margin-top:10px">Update Business</button>
  </form>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:var(--peacock)">Businesses</h3>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>ID</th><th>Business</th><th>Category</th><th>Engine</th><th>Wallet</th><th>Valid until</th><th>Access</th><th>Mobile</th><th>Status</th><th>Registered</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><strong><?= htmlspecialchars($c['business_name']) ?></strong><br><small><?= htmlspecialchars($c['email']) ?></small></td>
            <td><?= htmlspecialchars($c['category_name']) ?></td>
            <td><?= htmlspecialchars((string)($c['review_model_version'] ?? 'gemini-2.0-flash')) ?><br><small><?= htmlspecialchars((string)($c['review_logic_type'] ?? 'balanced')) ?> / <?= htmlspecialchars((string)($c['review_tone'] ?? 'Professional')) ?></small></td>
            <td><strong><?= (int)($c['wallet_balance'] ?? 0) ?></strong> credits</td>
            <td>
              <?php $vu = trim((string)($c['subscription_valid_until'] ?? '')); ?>
              <?= $vu !== '' ? htmlspecialchars($vu) : '—' ?>
            </td>
            <td>
              <?php $vb = validityStatusBadge($vu !== '' ? $vu : null); ?>
              <span class="status <?= htmlspecialchars($vb['class']) ?>"><?= htmlspecialchars($vb['label']) ?></span>
            </td>
            <td><?= htmlspecialchars((string)$c['mobile']) ?></td>
            <td><span class="status <?= (int)$c['is_active'] === 1 ? 'active':'suspended' ?>"><?= (int)$c['is_active'] === 1 ? 'Active':'Suspended' ?></span></td>
            <td><?= htmlspecialchars((string)$c['created_at']) ?></td>
            <td>
              <div class="actions">
                <button type="button" class="btn btn-edit"
                  data-id="<?= (int)$c['id'] ?>"
                  data-business="<?= htmlspecialchars($c['business_name'], ENT_QUOTES) ?>"
                  data-owner-name="<?= htmlspecialchars((string)$c['owner_name'], ENT_QUOTES) ?>"
                  data-email="<?= htmlspecialchars($c['email'], ENT_QUOTES) ?>"
                  data-mobile="<?= htmlspecialchars((string)$c['mobile'], ENT_QUOTES) ?>"
                  data-extra-whatsapp="<?= htmlspecialchars((string)($c['extra_whatsapp_numbers'] ?? ''), ENT_QUOTES) ?>"
                  data-daily-summary="<?= (int)($c['daily_summary_whatsapp_enabled'] ?? 1) ?>"
                  data-address="<?= htmlspecialchars($c['address'], ENT_QUOTES) ?>"
                  data-category="<?= (int)$c['category_id'] ?>"
                  data-place="<?= htmlspecialchars($c['google_place_id'], ENT_QUOTES) ?>"
                  data-custom-ai-key="<?= htmlspecialchars((string)$c['custom_ai_api_key'], ENT_QUOTES) ?>"
                  data-review-model-version="<?= htmlspecialchars((string)($c['review_model_version'] ?? 'gemini-2.0-flash'), ENT_QUOTES) ?>"
                  data-review-logic-type="<?= htmlspecialchars((string)($c['review_logic_type'] ?? 'balanced'), ENT_QUOTES) ?>"
                  data-review-tone="<?= htmlspecialchars((string)($c['review_tone'] ?? 'Professional'), ENT_QUOTES) ?>"
                  data-is-active="<?= (int)$c['is_active'] ?>"
                  data-valid-until="<?= htmlspecialchars($vu, ENT_QUOTES) ?>">Edit</button>

                <a class="btn btn-ghost" href="<?= APP_URL ?>/admin_wallet.php?client_id=<?= (int)$c['id'] ?>">Wallet</a>

                <form method="post" style="display:inline" onsubmit="return confirm('Clear unused AI pre-generated reviews for this business and generate a fresh buffer of <?= (int)\AiReviewService::BUFFER_TARGET ?>? Used reviews stay on record.')">
                  <input type="hidden" name="action" value="flush_ai_buffer">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-edit" type="submit" title="Remove stale unused reviews and refill the AI buffer">Flush &amp; regenerate</button>
                </form>

                <form method="post">
                  <input type="hidden" name="action" value="impersonate_client">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-primary" type="submit">Login as Client</button>
                </form>

                <form method="post" onsubmit="return confirm('Change business active/suspended status?')">
                  <input type="hidden" name="action" value="toggle_client">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-toggle" type="submit"><?= (int)$c['is_active'] === 1 ? 'Suspend':'Activate' ?></button>
                </form>

                <form method="post" onsubmit="return confirm('Delete this business and all related data permanently?')">
                  <input type="hidden" name="action" value="delete_client">
                  <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-delete" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
document.querySelectorAll('.btn-edit').forEach(function(btn){
  btn.addEventListener('click', function(){
    document.getElementById('edit_client_id').value = this.dataset.id;
    document.getElementById('edit_owner_name').value = this.dataset.ownerName || '';
    document.getElementById('edit_business_name').value = this.dataset.business;
    document.getElementById('edit_email').value = this.dataset.email || '';
    document.getElementById('edit_mobile').value = this.dataset.mobile;
    var ex = document.getElementById('edit_extra_whatsapp_numbers');
    if (ex) ex.value = this.dataset.extraWhatsapp || '';
    var ds = document.getElementById('edit_daily_summary_whatsapp_enabled');
    if (ds) ds.value = this.dataset.dailySummary || '1';
    document.getElementById('edit_address').value = this.dataset.address;
    document.getElementById('edit_category_id').value = this.dataset.category;
    document.getElementById('edit_place_id').value = this.dataset.place;
    document.getElementById('edit_custom_ai_api_key').value = this.dataset.customAiKey || '';
    document.getElementById('edit_review_model_version').value = this.dataset.reviewModelVersion || 'gemini-2.0-flash';
    document.getElementById('edit_review_logic_type').value = this.dataset.reviewLogicType || 'balanced';
    document.getElementById('edit_review_tone').value = this.dataset.reviewTone || 'Professional';
    document.getElementById('edit_is_active').value = this.dataset.isActive || '1';
    var vud = document.getElementById('edit_subscription_valid_until');
    if (vud) vud.value = this.dataset.validUntil || '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
});
</script>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
