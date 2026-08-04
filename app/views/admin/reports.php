<?php
$pageTitle = 'Reports & Analytics';
$activeMenu = 'reports';
require __DIR__ . '/partials/layout_head.php';

$activePreset = $preset ?? 'today';
$qsBase = http_build_query(array_filter([
    'preset' => $activePreset,
    'from'   => $activePreset === 'custom' ? $fromDate : null,
    'to'     => $activePreset === 'custom' ? $toDate : null,
]));
?>
<style>
  .filters{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
  .filters .preset{display:flex;gap:6px;flex-wrap:wrap}
  .filters .preset a{
    padding:8px 14px;border-radius:999px;text-decoration:none;
    background:#e2e8f0;color:#0f172a;font-weight:600;font-size:.88rem;
  }
  .filters .preset a.active{background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b}
  .filters form{display:flex;gap:8px;align-items:flex-end}
  .filters input[type="date"]{padding:8px;border:1px solid #cbd5e1;border-radius:8px;width:160px}
  .filters .btn{padding:9px 14px}

  .kpi-grid{display:grid;gap:12px;grid-template-columns:1fr;margin-bottom:14px}
  @media(min-width:760px){.kpi-grid{grid-template-columns:repeat(3,1fr)}}
  @media(min-width:1100px){.kpi-grid{grid-template-columns:repeat(6,1fr)}}
  .kpi-grid .card{margin-bottom:0}
  .kpi-grid .v{font-size:1.8rem;font-weight:800;color:#005f8f}
  .kpi-grid .label{color:#475569;font-size:.85rem}
  .small{font-size:.85rem;color:#475569}
  .actions-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px}
</style>

<div class="card">
  <div class="actions-row">
    <h2 style="margin:0;color:#005f8f">Reports — <?= htmlspecialchars($rangeLabel) ?></h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn" target="_blank" rel="noopener" href="<?= APP_URL ?>/admin_reports.php?format=print&<?= htmlspecialchars($qsBase) ?>">Download PDF / Print</a>
    </div>
  </div>

  <div class="filters" style="margin-top:6px">
    <div class="preset">
      <?php foreach (['today'=>'Today','week'=>'This Week','month'=>'This Month','3months'=>'Last 3 Months','custom'=>'Custom'] as $key=>$label): ?>
        <a href="?preset=<?= htmlspecialchars($key) ?><?= $key === 'custom' ? '&from=' . htmlspecialchars($fromDate) . '&to=' . htmlspecialchars($toDate) : '' ?>"
           class="<?= $activePreset === $key ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" action="">
      <input type="hidden" name="preset" value="custom">
      <div><label class="small">From</label><input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>"></div>
      <div><label class="small">To</label><input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>"></div>
      <button class="btn btn-primary" type="submit">Apply</button>
    </form>
  </div>
</div>

<div class="kpi-grid">
  <section class="card"><div class="label">5★ Reviews Redirected</div><div class="v"><?= (int)$stats['total_5star_redirected'] ?></div></section>
  <section class="card"><div class="label">Internal Feedback (1-3★)</div><div class="v"><?= (int)$stats['total_internal_feedback'] ?></div></section>
  <section class="card"><div class="label">New Businesses</div><div class="v"><?= (int)$stats['new_businesses'] ?></div></section>
  <section class="card"><div class="label">Active Businesses</div><div class="v"><?= (int)$stats['active_businesses'] ?></div></section>
  <section class="card"><div class="label">Wallet Credited</div><div class="v">&#8377;<?= (int)$stats['total_credited'] ?></div></section>
  <section class="card"><div class="label">Wallet Debited</div><div class="v">&#8377;<?= (int)$stats['total_debited'] ?></div></section>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:#005f8f">Per-Business Performance</h3>
  <div style="overflow:auto">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Business</th><th>Category</th><th>Mobile</th>
          <th style="text-align:right">5★ Redirected</th>
          <th style="text-align:right">Internal Feedback</th>
          <th style="text-align:right">Wallet</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($perBusiness)): ?>
          <tr><td colspan="8" class="small">No data in selected range.</td></tr>
        <?php else: ?>
          <?php $i = 1; foreach ($perBusiness as $row): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><strong><?= htmlspecialchars($row['business_name']) ?></strong><br><span class="small"><?= htmlspecialchars($row['email']) ?></span></td>
              <td><?= htmlspecialchars($row['category_name']) ?></td>
              <td><?= htmlspecialchars((string)$row['mobile']) ?></td>
              <td style="text-align:right;font-weight:700;color:#166534"><?= (int)$row['total_5star'] ?></td>
              <td style="text-align:right;color:#92400e"><?= (int)$row['total_internal'] ?></td>
              <td style="text-align:right">&#8377;<?= (int)$row['wallet_balance'] ?></td>
              <td><a class="btn btn-ghost" href="<?= APP_URL ?>/admin_wallet.php?client_id=<?= (int)$row['id'] ?>">Wallet</a></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:#005f8f">Daily Breakdown (5★ Reviews)</h3>
  <?php if (empty($dailyBreakdown)): ?>
    <p class="small">No daily data available.</p>
  <?php else: ?>
    <div style="overflow:auto">
      <table>
        <thead><tr><th>Date</th><th style="text-align:right">5★ Reviews Redirected</th></tr></thead>
        <tbody>
          <?php foreach ($dailyBreakdown as $d): ?>
            <tr>
              <td><?= htmlspecialchars((string)$d['d']) ?></td>
              <td style="text-align:right;font-weight:700"><?= (int)$d['reviews'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
