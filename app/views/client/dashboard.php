<?php
$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
$clientForLayout = $client;
$clientForLayout['wallet_balance'] = $walletBalance;
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .dashboard-grid{display:grid;gap:14px;grid-template-columns:1fr}
  @media(min-width:900px){.dashboard-grid{grid-template-columns:1.2fr .8fr}}
  .qr-box{text-align:center;border:1px dashed #cbd5e1;background:#fffdf8;border-radius:12px;padding:14px}
  .alert{margin:0 0 12px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;padding:10px;border-radius:10px}
  .small-note{font-size:.9rem;color:#475569}
  .pill{display:inline-block;padding:7px 12px;border-radius:999px;font-weight:700;background:#dcfce7;color:#166534}
</style>

<?php if ($walletAlert !== ''): ?>
  <div class="alert" id="walletAlert"><?= htmlspecialchars($walletAlert) ?></div>
<?php else: ?>
  <div class="alert" id="walletAlert" style="display:none"></div>
<?php endif; ?>

<div class="kpi-grid">
  <section class="card">
    <div>Wallet Balance</div>
    <div class="v" id="walletBalance"><?= (int)$walletBalance ?></div>
    <div class="small-note">
      <a href="<?= APP_URL ?>/client_recharge.php" style="font-weight:700;color:#16a34a">+ Recharge</a> &middot;
      <a href="<?= APP_URL ?>/client_wallet.php">History &rsaquo;</a>
    </div>
  </section>
  <section class="card"><div>Price Per Review</div><div class="v">&#8377;<?= (int)$pricePerReview ?></div></section>
  <section class="card"><div>Today's 5-Star Reviews</div><div class="v" id="todayUsedCount"><?= (int)$todayUsed ?></div></section>
  <section class="card"><div>This Week</div><div class="v" id="weekUsedCount"><?= (int)$weekUsed ?></div></section>
  <section class="card"><div>This Month</div><div class="v" id="monthUsedCount"><?= (int)$monthUsed ?></div></section>
</div>

<div class="card">
  <h3 style="margin:0;color:var(--peacock)">Funnel analytics</h3>
  <p style="color:#475569;font-size:.9rem">Scan-to-review conversion (sessions created in each period).</p>
  <div class="kpi-grid" style="grid-template-columns:repeat(2,1fr);margin-top:10px">
    <section class="card" style="margin:0"><div>Scans today</div><div class="v"><?= (int)($funnel['scans_today'] ?? 0) ?></div></section>
    <section class="card" style="margin:0"><div>Scans this month</div><div class="v"><?= (int)($funnel['scans_month'] ?? 0) ?></div></section>
    <section class="card" style="margin:0"><div>Reviews completed today</div><div class="v"><?= (int)($funnel['completed_today'] ?? 0) ?></div></section>
    <section class="card" style="margin:0"><div>Reviews completed (month)</div><div class="v"><?= (int)($funnel['completed_month'] ?? 0) ?></div></section>
    <section class="card" style="margin:0"><div>Drop-offs today (pending)</div><div class="v"><?= (int)($funnel['abandoned_today'] ?? 0) ?></div></section>
    <section class="card" style="margin:0"><div>Drop-offs (month)</div><div class="v"><?= (int)($funnel['abandoned_month'] ?? 0) ?></div></section>
    <section class="card" style="margin:0"><div>Bounce rate today</div><div class="v"><?= htmlspecialchars((string)($funnel['bounce_rate_today'] ?? 0)) ?>%</div></section>
    <section class="card" style="margin:0"><div>Bounce rate (month)</div><div class="v"><?= htmlspecialchars((string)($funnel['bounce_rate_month'] ?? 0)) ?>%</div></section>
  </div>
</div>

<div class="dashboard-grid">
  <section class="card">
    <h2 style="margin:0;color:var(--peacock)">Welcome, <?= htmlspecialchars($client['business_name']) ?></h2>
    <p style="color:#475569">Your review collection control center.</p>
    <div class="qr-box">
      <h3 style="margin:0 0 8px;color:var(--peacock)">Active QR Code</h3>
      <?php if (!empty($client['qr_image_path'])): ?>
        <img id="qrImage" src="<?= APP_URL . '/' . htmlspecialchars($client['qr_image_path']) ?>" alt="QR" style="max-width:220px;display:block;margin:0 auto 8px">
        <a class="btn" id="downloadQrBtn" href="<?= APP_URL ?>/dashboard_download.php?type=qr">Download QR</a>
        <a class="btn" id="downloadStandeeBtn" href="<?= APP_URL ?>/standee_gallery.php">Choose Standee Design</a>
        <p class="small-note" style="margin-top:10px">Your review link is <strong>permanent</strong> for printed materials. New standee designs reuse this same link.</p>
        <p id="publicReviewLink" style="word-break:break-all"><strong>Public Link:</strong><br><?= htmlspecialchars((string)$client['public_review_url']) ?></p>
      <?php else: ?>
        <p>No active QR found.</p>
      <?php endif; ?>
    </div>
  </section>
  <aside class="card">
    <h2 style="margin:0;color:var(--peacock)">Review Tone</h2>
    <p style="color:#475569">Current brand voice for AI-generated reviews.</p>
    <span class="pill"><?= htmlspecialchars((string)($client['review_tone'] ?? 'Professional')) ?></span>
  </aside>
</div>

<div class="card">
  <h3>Recent Review Activity</h3>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>Sr. No.</th><th>Rating</th><th>Flow</th><th>Review Text</th><th>Created At</th><th>Completed At</th></tr></thead>
      <tbody>
        <?php if (empty($recentActivities)): ?>
          <tr><td colspan="6">No review activity yet.</td></tr>
        <?php else: ?>
          <?php $srNo = (int)$srStart; foreach ($recentActivities as $row): ?>
            <tr>
              <td><?= $srNo++ ?></td>
              <td><?= htmlspecialchars((string)$row['customer_rating']) ?></td>
              <td><?= htmlspecialchars((string)$row['flow_type']) ?></td>
              <td style="max-width:380px;white-space:normal"><?= htmlspecialchars((string)($row['review_text'] ?? '-')) ?></td>
              <td><?= htmlspecialchars((string)$row['created_at']) ?></td>
              <td><?= htmlspecialchars((string)$row['completed_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <div style="display:flex;gap:8px;justify-content:center;align-items:center;margin-top:12px;flex-wrap:wrap">
    <?php if ($page > 1): ?>
      <a class="btn" href="<?= APP_URL ?>/dashboard.php?page=<?= (int)($page - 1) ?>">Previous</a>
    <?php endif; ?>
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <?php if ($p === $page): ?>
        <span class="btn" style="opacity:.7;cursor:default">Page <?= (int)$p ?></span>
      <?php else: ?>
        <a class="btn" href="<?= APP_URL ?>/dashboard.php?page=<?= (int)$p ?>">Page <?= (int)$p ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
      <a class="btn" href="<?= APP_URL ?>/dashboard.php?page=<?= (int)($page + 1) ?>">Next</a>
    <?php endif; ?>
  </div>
</div>

<script>
async function refreshDashboardStats(){
  try{
    const res = await fetch('dashboard_stats.php', { headers: { 'Accept': 'application/json' } });
    if(!res.ok) return;
    const data = await res.json();
    if(!data.ok) return;
    document.getElementById('walletBalance').textContent = data.walletBalance;
    document.getElementById('todayUsedCount').textContent = data.todayUsed;
    document.getElementById('weekUsedCount').textContent = data.weekUsed;
    document.getElementById('monthUsedCount').textContent = data.monthUsed;
    const walletAlert = document.getElementById('walletAlert');
    if (walletAlert) {
      if (data.walletAlert) {
        walletAlert.style.display = 'block';
        walletAlert.textContent = data.walletAlert;
      } else {
        walletAlert.style.display = 'none';
      }
    }
  }catch(e){ /* silent */ }
}
setInterval(refreshDashboardStats, 5000);
</script>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
