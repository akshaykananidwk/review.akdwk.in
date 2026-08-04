<?php
$pageTitle = 'Global Settings & CMS';
$activeMenu = 'global';
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .grid{display:grid;gap:10px;grid-template-columns:1fr}
  @media(min-width:760px){.grid{grid-template-columns:1fr 1fr}}
  .grid-3{display:grid;gap:10px;grid-template-columns:1fr}
  @media(min-width:760px){.grid-3{grid-template-columns:1fr 1fr 1fr}}
  textarea{min-height:80px}
  .section-title{margin:0 0 10px;color:var(--peacock)}
  .hint{font-size:.85rem;color:#475569;margin-top:4px}
  .test-box{margin-top:10px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:10px}
  .badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.78rem;font-weight:700;background:#dbeafe;color:#1e40af}
</style>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!empty($whatsappTestResult)): ?>
  <div class="<?= $whatsappTestResult['ok'] ? 'msg' : 'err' ?>">
    <strong>WhatsApp test:</strong> <?= htmlspecialchars($whatsappTestResult['message']) ?>
  </div>
<?php endif; ?>

<div class="card">
  <h3 class="section-title">Master Configuration</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save_global_settings">
    <div class="grid">
      <div><label>System Name</label><input name="system_name" value="<?= htmlspecialchars($systemName) ?>" required></div>
      <div><label>Support Mobile</label><input name="support_mobile" value="<?= htmlspecialchars($supportMobile) ?>" placeholder="+91xxxxxxxxxx"></div>
      <div><label>Helpline / Lead Generation Number</label><input name="helpline_number" value="<?= htmlspecialchars($helplineNumber) ?>" placeholder="+91xxxxxxxxxx"><div class="hint">Shown on the public review page footer for lead generation.</div></div>
      <div><label>Master API Key (Admin Only)</label><input name="master_api_key" value="<?= htmlspecialchars($masterApiKey) ?>" required></div>
      <div><label>Price per Review (credits)</label><input type="number" min="1" step="1" name="price_per_review" value="<?= (int)$pricePerReview ?>" required></div>
      <div><label>Sign-up Bonus Amount (credits)</label><input type="number" min="0" step="1" name="signup_bonus_amount" value="<?= (int)$signupBonusAmount ?>"><div style="font-size:.78rem;color:#475569;margin-top:4px">New businesses are auto-credited this amount on registration. Set to 0 to disable.</div></div>
      <div><label>Sign-up free validity (days)</label><input type="number" min="0" step="1" name="signup_subscription_trial_days" value="<?= (int)$signupSubscriptionTrialDays ?>"><div class="hint">Free platform days on new registration (e.g. 90 = 3 months). 0 = must recharge before QR works.</div></div>
      <div><label>Special FREE districts</label><input name="special_district_names" value="<?= htmlspecialchars($specialDistrictNames ?? '') ?>" placeholder="Devbhumi Dwarka"><div class="hint">Comma separated district names. Businesses registering from these districts get the special free validity below.</div></div>
      <div><label>Special district free validity (days)</label><input type="number" min="0" step="1" name="special_district_trial_days" value="<?= (int)($specialDistrictTrialDays ?? 1095) ?>"><div class="hint">e.g. 1095 = 3 years FREE for special districts (Devbhumi Dwarka offer).</div></div>
      <div>
        <label>Default Welcome Standee Template</label>
        <select name="default_welcome_standee_template_id">
          <option value="0">— First active template (fallback) —</option>
          <?php foreach ($standeeTemplatesForSelect as $tpl): ?>
            <option value="<?= (int)$tpl['id'] ?>" <?= (int)$defaultStandeeTemplateId === (int)$tpl['id'] ? 'selected' : '' ?>>
              #<?= (int)$tpl['id'] ?> <?= htmlspecialchars((string)($tpl['title'] ?? 'Untitled')) ?><?= (int)($tpl['is_active'] ?? 0) === 1 ? '' : ' (inactive)' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Used for WhatsApp welcome standee on new registrations.</div>
      </div>
      <div style="grid-column:1/-1">
        <label>WhatsApp review invite template</label>
        <textarea name="review_invite_message_template" rows="2"><?= htmlspecialchars($reviewInviteMessageTemplate) ?></textarea>
        <div class="hint">Placeholders: <code>{name}</code> and <code>{link}</code> (client “Send Invite” + POS API).</div>
      </div>
      <div>
        <label>Global Logo Upload</label>
        <input type="file" name="global_logo" accept=".png,.jpg,.jpeg,.webp">
        <?php if ($globalLogoPath !== ''): ?><div style="margin-top:8px"><img src="<?= APP_URL . '/' . htmlspecialchars($globalLogoPath) ?>" alt="Logo" style="max-height:60px"></div><?php endif; ?>
      </div>
    </div>
    <div class="grid" style="margin-top:8px">
      <div><label>How It Works - Step 1</label><textarea name="step1"><?= htmlspecialchars((string)($howItWorks[0] ?? '')) ?></textarea></div>
      <div><label>How It Works - Step 2</label><textarea name="step2"><?= htmlspecialchars((string)($howItWorks[1] ?? '')) ?></textarea></div>
    </div>
    <div style="margin-top:8px"><label>How It Works - Step 3</label><textarea name="step3"><?= htmlspecialchars((string)($howItWorks[2] ?? '')) ?></textarea></div>
    <button class="btn" type="submit">Save Global Settings</button>
  </form>
</div>

<div class="card">
  <h3 class="section-title">WhatsApp API Gateway <span class="badge"><?= $whatsappConfigured ? 'Configured' : 'Not Configured' ?></span></h3>
  <p class="hint">Used to deliver Forgot Password OTP, password reset links and customer notifications via WhatsApp.</p>
  <form method="post">
    <input type="hidden" name="action" value="save_whatsapp_settings">
    <div class="grid">
      <div>
        <label>API Endpoint</label>
        <input name="whatsapp_endpoint" value="<?= htmlspecialchars($whatsappEndpoint) ?>" placeholder="https://bulk.akdwk.in/api.php" required>
      </div>
      <div>
        <label>API Key</label>
        <input name="whatsapp_api_key" value="<?= htmlspecialchars($whatsappApiKey) ?>" placeholder="c9f5b590100fc385c31b" required>
      </div>
      <div>
        <label>Session ID</label>
        <input name="whatsapp_session_id" value="<?= htmlspecialchars($whatsappSessionId) ?>" placeholder="user_4835_1774094200_1776318138" required>
      </div>
      <div>
        <label>Instance ID (optional)</label>
        <input name="whatsapp_instance_id" value="<?= htmlspecialchars($whatsappInstanceId) ?>">
      </div>
      <div>
        <label>Token (optional)</label>
        <input name="whatsapp_token" value="<?= htmlspecialchars($whatsappToken) ?>">
      </div>
      <div>
        <label>Sender Display Name</label>
        <input name="whatsapp_sender_name" value="<?= htmlspecialchars($whatsappSenderName) ?>">
      </div>
      <div>
        <label>Default Country Code</label>
        <input name="whatsapp_country_code" value="<?= htmlspecialchars($whatsappCountryCode) ?>" placeholder="91">
        <div class="hint">Prepended automatically to 10-digit local mobile numbers.</div>
      </div>
    </div>
    <button class="btn" type="submit">Save WhatsApp Settings</button>
  </form>

  <div class="test-box">
    <h4 style="margin:0 0 8px;color:var(--peacock)">Send Test Message</h4>
    <form method="post">
      <input type="hidden" name="action" value="send_whatsapp_test">
      <div class="grid">
        <div><label>Recipient Mobile</label><input name="test_mobile" placeholder="9876543210" required></div>
        <div><label>Test Message</label><input name="test_message" value="Hello from <?= htmlspecialchars($systemName) ?>! WhatsApp gateway test." required></div>
      </div>
      <button class="btn" type="submit" style="margin-top:8px">Send Test WhatsApp</button>
    </form>
  </div>
</div>

<div class="card">
  <h3 class="section-title">Razorpay Payment Gateway <span class="badge"><?= !empty($razorpayEnabled) && !empty($razorpayKeyId) && !empty($razorpayKeySecretSet) ? 'Configured' : 'Not Configured' ?></span></h3>
  <p class="hint">Used for client wallet self-recharge (UPI, Cards, NetBanking, Wallets). Get your keys from <a href="https://dashboard.razorpay.com/app/keys" target="_blank" rel="noopener">Razorpay Dashboard → API Keys</a>.</p>
  <form method="post">
    <input type="hidden" name="action" value="save_razorpay_settings">
    <div class="grid">
      <div>
        <label>Enable Razorpay</label>
        <select name="razorpay_enabled">
          <option value="1" <?= !empty($razorpayEnabled) ? 'selected' : '' ?>>Enabled (clients can recharge)</option>
          <option value="0" <?= empty($razorpayEnabled) ? 'selected' : '' ?>>Disabled</option>
        </select>
      </div>
      <div>
        <label>Mode</label>
        <select name="razorpay_mode">
          <option value="test" <?= ($razorpayMode ?? 'test') === 'test' ? 'selected' : '' ?>>Test (sandbox)</option>
          <option value="live" <?= ($razorpayMode ?? '') === 'live' ? 'selected' : '' ?>>Live (production)</option>
        </select>
      </div>
      <div>
        <label>Key ID</label>
        <input name="razorpay_key_id" value="<?= htmlspecialchars((string)($razorpayKeyId ?? '')) ?>" placeholder="rzp_test_xxxxxxxxxxxx">
      </div>
      <div>
        <label>Key Secret <?= !empty($razorpayKeySecretSet) ? '<span class="badge">SAVED</span>' : '' ?></label>
        <input name="razorpay_key_secret" type="password" autocomplete="new-password" placeholder="<?= !empty($razorpayKeySecretSet) ? 'Leave blank to keep existing' : 'Paste secret from Razorpay dashboard' ?>">
        <div class="hint">Stored server-side only. Never exposed to clients.</div>
      </div>
      <div style="grid-column:1/-1">
        <label>Webhook Secret <?= !empty($razorpayWebhookSet) ? '<span class="badge">SAVED</span>' : '' ?></label>
        <input name="razorpay_webhook_secret" type="password" autocomplete="new-password" placeholder="<?= !empty($razorpayWebhookSet) ? 'Leave blank to keep existing' : 'Paste webhook secret from Razorpay dashboard' ?>">
        <div class="hint">
          Webhook URL: <code><?= htmlspecialchars(APP_URL . '/razorpay_webhook.php') ?></code><br>
          Subscribe to events <code>payment.captured</code> and <code>payment.failed</code> in your Razorpay dashboard.
        </div>
      </div>
    </div>
    <button class="btn" type="submit">Save Razorpay Settings</button>
  </form>
</div>

<div class="card">
  <h3 class="section-title">Public Landing Page (CMS)</h3>
  <p class="hint">Controls everything on your homepage at <code><?= htmlspecialchars(APP_URL) ?>/</code> — the page that loads when someone opens your domain.</p>
  <form method="post">
    <input type="hidden" name="action" value="save_landing_cms">
    <div class="grid">
      <div style="grid-column:1/-1">
        <label>Hero Headline</label>
        <input name="landing_hero_headline" value="<?= htmlspecialchars((string)($landingHeadline ?? '')) ?>" placeholder="Get More 5-Star Google Reviews — Automatically.">
      </div>
      <div style="grid-column:1/-1">
        <label>Hero Sub-headline</label>
        <textarea name="landing_hero_subheadline" rows="2"><?= htmlspecialchars((string)($landingSubheadline ?? '')) ?></textarea>
      </div>
      <div>
        <label>Hero Video URL (YouTube embed or .mp4)</label>
        <input name="landing_hero_video_url" value="<?= htmlspecialchars((string)($landingVideoUrl ?? '')) ?>" placeholder="https://www.youtube.com/embed/xxxxxxxxxxx">
        <div class="hint">Use a YouTube <em>embed</em> URL (https://www.youtube.com/embed/VIDEO_ID) or a direct .mp4 link.</div>
      </div>
      <div>
        <label>Live Counter Manual Offset</label>
        <input name="landing_live_counter_offset" type="number" min="0" value="<?= (int)($landingCounterOffset ?? 0) ?>">
        <div class="hint">Added on top of the real review count. Useful for an impressive opening number.</div>
      </div>
      <div>
        <label>Demo QR Token</label>
        <input name="landing_demo_qr_token" value="<?= htmlspecialchars((string)($landingDemoToken ?? '')) ?>" placeholder="paste any active QR token from a demo client">
        <div class="hint">If empty, the "Try Demo" button shows a simulated experience instead of a real QR flow.</div>
      </div>
      <div>
        <label>Show Pricing Section</label>
        <select name="landing_show_pricing">
          <option value="1" <?= !empty($landingShowPricing) ? 'selected' : '' ?>>Yes</option>
          <option value="0" <?= empty($landingShowPricing) ? 'selected' : '' ?>>No</option>
        </select>
      </div>
      <div style="grid-column:1/-1">
        <label>Customer Testimonials (JSON)</label>
        <textarea name="landing_testimonials" rows="8" style="font-family:monospace;font-size:.85rem"><?= htmlspecialchars(json_encode($landingTestimonials ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></textarea>
        <div class="hint">
          JSON array. Each object: <code>{"name":"...", "business":"...", "quote":"...", "rating":5}</code>.
          <br>Example:
          <pre style="background:#0f172a;color:#f1f5f9;padding:8px;border-radius:8px;font-size:.78rem;overflow:auto">[
  {"name":"Rohan Patel", "business":"Krishna Mobile Zone", "quote":"Reviews jumped from 60 to 280 in 2 months. Mind-blowing.", "rating":5}
]</pre>
        </div>
      </div>
    </div>
    <button class="btn" type="submit">Save Landing Page</button>
  </form>
</div>

<div class="card">
  <h3 class="section-title">Homepage Slider Images</h3>
  <form method="post" enctype="multipart/form-data" class="grid">
    <input type="hidden" name="action" value="add_slider">
    <div><label>Slider Image</label><input type="file" name="slider_image" accept=".png,.jpg,.jpeg,.webp" required></div>
    <div><label>Sort Order</label><input type="number" name="sort_order" value="0"></div>
    <div style="display:flex;align-items:end"><button class="btn" type="submit">Add Slider</button></div>
  </form>
  <div style="overflow:auto;margin-top:10px">
    <table>
      <thead><tr><th>Preview</th><th>Sort</th><th>Created</th><th>Action</th></tr></thead>
      <tbody>
        <?php if (empty($sliders)): ?>
          <tr><td colspan="4">No sliders uploaded yet.</td></tr>
        <?php else: foreach ($sliders as $s): ?>
          <tr>
            <td><img src="<?= APP_URL . '/' . htmlspecialchars($s['image_path']) ?>" alt="Slider" style="max-height:80px"></td>
            <td><?= (int)$s['sort_order'] ?></td>
            <td><?= htmlspecialchars((string)$s['created_at']) ?></td>
            <td>
              <form method="post" onsubmit="return confirm('Delete this slider image?')">
                <input type="hidden" name="action" value="delete_slider">
                <input type="hidden" name="slider_id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-delete" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
