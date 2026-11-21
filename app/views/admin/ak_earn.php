<?php
$pageTitle = 'AK Earn Connection';
$activeMenu = 'ak_earn';
require __DIR__ . '/partials/layout_head.php';

/** Short, readable label for an outbox status. */
$statusPill = static function (string $s): string {
    $map = [
        'sent' => ['Sent', '#dcfce7', '#166534'],
        'pending' => ['Waiting', '#fef3c7', '#92400e'],
        'failed' => ['Failed', '#fee2e2', '#991b1b'],
        'skipped' => ['Skipped', '#e2e8f0', '#475569'],
    ];
    [$label, $bg, $fg] = $map[$s] ?? [$s, '#e2e8f0', '#475569'];
    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:.75rem;font-weight:700;background:' . $bg . ';color:' . $fg . '">' . htmlspecialchars($label) . '</span>';
};
?>
<style>
  .ak-grid{display:grid;gap:12px;grid-template-columns:1fr}
  @media(min-width:860px){.ak-grid{grid-template-columns:repeat(4,1fr)}}
  .ak-stat{background:#fff;border-radius:12px;padding:14px;border-top:4px solid #005f8f}
  .ak-stat .v{font-size:1.7rem;font-weight:800;color:#005f8f}
  .ak-stat .k{color:#475569;font-size:.85rem}
  .ak-banner{padding:14px;border-radius:12px;border:1px solid;margin-bottom:14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:space-between}
  .ak-ok{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
  .ak-off{background:#fef2f2;border-color:#fecaca;color:#991b1b}
  .ak-actions{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 0}
  .ak-mono{font-family:ui-monospace,Consolas,monospace;font-size:.82rem;word-break:break-all}
  .ak-err{color:#991b1b;font-size:.8rem;max-width:380px;white-space:normal}
</style>

<?php if ($flash !== ''): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($flashError !== ''): ?><div class="err"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

<div class="ak-banner <?= $configured ? 'ak-ok' : 'ak-off' ?>">
  <div>
    <strong style="font-size:1.05rem">
      <?= $configured ? '✅ Connector configured' : '⚠️ Not connected yet' ?>
    </strong>
    <div style="font-size:.9rem;margin-top:4px">
      <?php if ($configured): ?>
        Master: <span class="ak-mono"><?= htmlspecialchars($masterUrl) ?></span> &middot;
        Site key: <span class="ak-mono"><?= htmlspecialchars($siteKey) ?></span>
      <?php else: ?>
        The secret is missing. Add <span class="ak-mono">AK_EARN_SECRET=...</span> to the
        <span class="ak-mono">.env</span> file in the site root, then press Test Connection.
        Until then nothing is sent and the site works exactly as before.
      <?php endif; ?>
    </div>
  </div>
  <form method="post" style="margin:0">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="ping">
    <button class="btn" type="submit">Test Connection</button>
  </form>
</div>

<?php if ($pingResult !== null): ?>
  <div class="card">
    <h3>Master response</h3>
    <pre class="ak-mono" style="white-space:pre-wrap"><?= htmlspecialchars(json_encode($pingResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
  </div>
<?php endif; ?>

<div class="ak-grid">
  <div class="ak-stat"><div class="v"><?= (int)($counts['sent'] ?? 0) ?></div><div class="k">Events sent</div></div>
  <div class="ak-stat" style="border-top-color:#f4b400"><div class="v"><?= (int)($counts['pending'] ?? 0) ?></div><div class="k">Waiting to send</div></div>
  <div class="ak-stat" style="border-top-color:#dc2626"><div class="v"><?= (int)($counts['failed'] ?? 0) ?></div><div class="k">Failed</div></div>
  <div class="ak-stat" style="border-top-color:#16a34a">
    <div class="v" style="font-size:1.05rem"><?= $lastSent !== null ? htmlspecialchars($lastSent) : '—' ?></div>
    <div class="k">Last event sent</div>
  </div>
</div>

<div class="card" style="margin-top:14px">
  <h3>Today &amp; yesterday (what the master should show)</h3>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>Day</th><th>Visitors</th><th>Signups</th><th>Orders</th><th>Revenue (₹)</th></tr></thead>
      <tbody>
        <tr>
          <td>Today</td>
          <td><?= (int)$todayTotals['visitors'] ?></td>
          <td><?= (int)$todayTotals['signups'] ?></td>
          <td><?= (int)$todayTotals['orders'] ?></td>
          <td><?= number_format((float)$todayTotals['revenue'], 2) ?></td>
        </tr>
        <tr>
          <td>Yesterday</td>
          <td><?= (int)$yesterdayTotals['visitors'] ?></td>
          <td><?= (int)$yesterdayTotals['signups'] ?></td>
          <td><?= (int)$yesterdayTotals['orders'] ?></td>
          <td><?= number_format((float)$yesterdayTotals['revenue'], 2) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
  <p style="color:#475569;font-size:.88rem;margin:10px 0 0">
    Yesterday's row is sent automatically every night at 02:20 by the
    <strong>AK Earn — Daily Totals</strong> cron job. Revenue is the amount actually
    captured, with no commission applied — the master works that out itself.
  </p>
  <div class="ak-actions">
    <form method="post" style="margin:0;display:flex;gap:6px;align-items:center">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="push_stats">
      <input type="date" name="stats_date" value="<?= htmlspecialchars(date('Y-m-d', strtotime('-1 day'))) ?>" style="width:auto">
      <button class="btn btn-ghost" type="submit">Send that day's totals</button>
    </form>
    <form method="post" style="margin:0">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="sync_products">
      <button class="btn btn-ghost" type="submit">Send plan &amp; price list</button>
    </form>
    <form method="post" style="margin:0"
          onsubmit="return confirm('Send the last 12 months of daily totals to the master? Safe to repeat — the master keeps one row per day.');">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="backfill">
      <button class="btn btn-ghost" type="submit">
        <?= $backfillDone ? 'Re-send 12 months of history' : 'Send 12 months of history' ?>
      </button>
    </form>
  </div>
  <?php if (!$backfillDone): ?>
    <p style="color:#92400e;font-size:.85rem;margin:8px 0 0">
      History has not been uploaded yet. It runs by itself on the next nightly job,
      or press the button to do it now.
    </p>
  <?php endif; ?>
</div>

<?php if ($failed !== []): ?>
<div class="card">
  <h3 style="color:#991b1b">Failed events (<?= count($failed) ?>)</h3>
  <p style="color:#475569;font-size:.88rem">
    These were tried several times and gave up. Fix the cause if the error points at one,
    then retry — nothing is lost, and the master ignores a duplicate if it did arrive.
  </p>
  <form method="post" style="margin:0 0 10px">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="retry_all">
    <button class="btn" type="submit">Retry all failed</button>
  </form>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>When</th><th>Type</th><th>Event ID</th><th>Tries</th><th>Last error</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($failed as $e): ?>
          <tr>
            <td><?= htmlspecialchars((string)$e['created_at']) ?></td>
            <td><?= htmlspecialchars((string)$e['event_type']) ?></td>
            <td class="ak-mono"><?= htmlspecialchars((string)$e['event_id']) ?></td>
            <td><?= (int)$e['attempts'] ?></td>
            <td class="ak-err"><?= htmlspecialchars((string)($e['last_error'] ?? '')) ?></td>
            <td>
              <form method="post" style="margin:0">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="retry_one">
                <input type="hidden" name="outbox_id" value="<?= (int)$e['id'] ?>">
                <button class="btn btn-ghost" type="submit">Retry</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h3>Event log</h3>
  <p style="color:#475569;font-size:.88rem">
    Every event this site has queued. Events are written here instantly when the customer acts,
    and delivered by the cron job within a minute — so a slow or offline master
    never delays a signup or a payment.
  </p>
  <form method="post" style="margin:0 0 10px">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="send_now">
    <button class="btn btn-ghost" type="submit">Send waiting events now</button>
  </form>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>When</th><th>Type</th><th>Event ID</th><th>Ref</th><th>Amount</th><th>Status</th><th>Sent at</th></tr></thead>
      <tbody>
        <?php if ($recent === []): ?>
          <tr><td colspan="7">Nothing yet. Open the site with <span class="ak-mono">?ref=TEST</span> and do a test signup.</td></tr>
        <?php else: ?>
          <?php foreach ($recent as $e): ?>
            <tr>
              <td><?= htmlspecialchars((string)$e['created_at']) ?></td>
              <td><?= htmlspecialchars((string)$e['event_type']) ?></td>
              <td class="ak-mono"><?= htmlspecialchars((string)$e['event_id']) ?></td>
              <td class="ak-mono"><?= htmlspecialchars((string)($e['ref_code'] ?? '—')) ?></td>
              <td><?= $e['amount'] !== null ? '₹' . number_format((float)$e['amount'], 2) : '—' ?></td>
              <td><?= $statusPill((string)$e['status']) ?></td>
              <td><?= htmlspecialchars((string)($e['sent_at'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
