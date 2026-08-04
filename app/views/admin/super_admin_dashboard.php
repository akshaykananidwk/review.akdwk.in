<?php
$pageTitle = 'Super Admin Dashboard';
$activeMenu = 'dashboard';
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .kpi-grid{display:grid;gap:14px;grid-template-columns:1fr}
  @media(min-width:840px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
  .kpi-card .v{font-size:2rem;color:var(--peacock);font-weight:800;margin-top:6px}
  .kpi-card .l{color:#475569;font-weight:600}
  .quicknav{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
  .quicknav a{background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b;text-decoration:none;padding:10px 14px;border-radius:10px;font-weight:700}
</style>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

<div class="quicknav">
  <a href="<?= APP_URL ?>/admin_businesses.php">Manage Businesses</a>
  <a href="<?= APP_URL ?>/admin_category_facilities.php">Categories & Facilities</a>
  <a href="<?= APP_URL ?>/admin_global_settings.php">Global Settings</a>
  <a href="<?= APP_URL ?>/admin_audit.php">Audit & Logs</a>
</div>

<div class="kpi-grid">
  <section class="card kpi-card"><div class="l">Total Clients</div><div class="v"><?= (int)$totalClients ?></div></section>
  <section class="card kpi-card"><div class="l">Active Clients</div><div class="v"><?= (int)$totalActiveClients ?></div></section>
  <section class="card kpi-card"><div class="l">Suspended Clients</div><div class="v"><?= (int)$totalSuspendedClients ?></div></section>
  <section class="card kpi-card"><div class="l">Total Reviews Generated</div><div class="v"><?= (int)$totalReviewsGenerated ?></div></section>
  <section class="card kpi-card"><div class="l">Total 4/5 Star Redirects</div><div class="v"><?= (int)$totalRedirected ?></div></section>
  <section class="card kpi-card"><div class="l">Total 1-3 Star Feedbacks</div><div class="v"><?= (int)$totalInternalFeedback ?></div></section>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
