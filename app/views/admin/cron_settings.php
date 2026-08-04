<?php
declare(strict_types=1);
/**
 * Admin: Cron Settings — centralized scheduler control panel.
 *
 * Variables from CronSettingsController::index():
 * $tablesReady, $jobs, $health, $recentRuns, $historyJob, $reminderDays,
 * $walletThreshold, $cronSecretSet, $projectRoot, $csrfToken, $flash, $flashError
 */
require __DIR__ . '/partials/layout_head.php';

$runStatusBadge = static function (string $status): string {
    $map = [
        'success' => ['#ecfdf5', '#065f46', 'Success'],
        'running' => ['#eff6ff', '#1e40af', 'Running'],
        'failed'  => ['#fef2f2', '#991b1b', 'Failed'],
        'skipped' => ['#f8fafc', '#334155', 'Skipped'],
        'never'   => ['#f8fafc', '#334155', 'Never Run'],
    ];
    [$bg, $fg, $label] = $map[$status] ?? ['#f8fafc', '#334155', ucfirst($status)];
    return '<span style="background:' . $bg . ';color:' . $fg . ';padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700">' . htmlspecialchars($label) . '</span>';
};
?>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!empty($flashError)): ?><div class="err"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

<?php if (!$tablesReady): ?>
  <div class="card">
    <h3 style="margin-top:0;color:var(--peacock)">Enable the Centralized Cron Scheduler</h3>
    <p>One-time installation creates <code>cron_jobs</code>, <code>cron_job_runs</code> and
       <code>notification_queue</code>, then registers all background tasks automatically.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" value="install_cron_tables">
      <button type="submit" class="btn btn-primary">Install Cron Scheduler</button>
    </form>
  </div>
<?php else: ?>

<!-- ================= Health ================= -->
<div class="card">
  <h3 style="margin-top:0;color:var(--peacock)">Health Monitoring</h3>
  <?php
    $tick = $health['master_last_tick'] ?? null;
    if ($tick === null): ?>
      <div class="err"><strong>Master cron has never run.</strong> Configure the single server cron below (or the HTTP fallback) — it drives every background task.</div>
  <?php elseif (!$health['master_ok']): ?>
      <div class="err"><strong>Master cron looks stalled.</strong> Last tick: <?= htmlspecialchars($tick) ?>. Expected every minute — check the crontab line below.</div>
  <?php else: ?>
      <div class="msg"><strong>✔ Master cron is running.</strong> Last tick: <?= htmlspecialchars($tick) ?> &middot; Pending queue messages: <?= (int)$health['queue_pending'] ?></div>
  <?php endif; ?>

  <?php foreach ($health['overdue_jobs'] as $oj): ?>
    <div class="err">Job <strong><?= htmlspecialchars((string)$oj['job_name']) ?></strong> is overdue (was due <?= htmlspecialchars((string)$oj['next_run_at']) ?>).</div>
  <?php endforeach; ?>
  <?php foreach ($health['failed_jobs'] as $fj): ?>
    <div class="err">Job <strong><?= htmlspecialchars((string)$fj['job_name']) ?></strong> failed on its last run<?= $fj['last_run_at'] ? ' (' . htmlspecialchars((string)$fj['last_run_at']) . ')' : '' ?>: <?= htmlspecialchars((string)($fj['last_error'] ?? '')) ?></div>
  <?php endforeach; ?>

  <h4 style="color:var(--peacock);margin-bottom:6px">Server setup — only ONE cron is needed (pick either option)</h4>
  <p style="color:var(--muted);font-size:.85rem;margin:4px 0 6px">
    <strong>Option A — Shell script cron</strong> (every 1 minute). Detected PHP CLI on this server:
    <code><?= htmlspecialchars($phpCliPath) ?></code>
  </p>
  <pre style="background:#0f172a;color:#e2e8f0;border-radius:10px;padding:12px;font-size:.8rem;overflow-x:auto"><?= htmlspecialchars($phpCliPath) ?> <?= htmlspecialchars($projectRoot) ?>/cron/master.php</pre>
  <p style="color:var(--muted);font-size:.85rem;margin:10px 0 6px">
    <strong>Option B — URL cron</strong> (easiest on AAPanel: cron type "Access URL", every 1 minute)
    <?= $cronSecretSet ? '' : ' — <span style="color:#991b1b;font-weight:700">set REFILL_CRON_SECRET in .env first!</span>' ?>
  </p>
  <?php if ($cronSecretSet): ?>
    <pre style="background:#0f172a;color:#e2e8f0;border-radius:10px;padding:12px;font-size:.8rem;overflow-x:auto"><?= htmlspecialchars(APP_URL) ?>/run_cron.php?key=<?= htmlspecialchars($cronSecret) ?></pre>
  <?php endif; ?>
  <p style="color:var(--muted);font-size:.85rem;margin-bottom:0">
    Tip: run the shell command once by hand (SSH) to see its output — if it prints an error, the PHP
    path is wrong or an extension is missing. Old per-task crontab lines can be removed; they now
    delegate to this scheduler anyway.
  </p>
</div>

<!-- ================= Jobs ================= -->
<div class="card">
  <h3 style="margin-top:0;color:var(--peacock)">Scheduled Jobs</h3>
  <div style="overflow-x:auto">
    <table>
      <thead>
        <tr><th>Job</th><th>Schedule</th><th>Status</th><th>Last Run</th><th>Duration</th><th>Next Run</th><th>Runs / Fails</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($jobs as $job): ?>
        <?php $key = (string)$job['job_key']; ?>
        <tr style="<?= (int)$job['is_enabled'] === 1 ? '' : 'opacity:.55' ?>">
          <td>
            <strong><?= htmlspecialchars((string)$job['job_name']) ?></strong>
            <div style="color:var(--muted);font-size:.78rem;max-width:340px"><?= htmlspecialchars((string)($job['description'] ?? '')) ?></div>
            <code style="font-size:.72rem"><?= htmlspecialchars($key) ?></code>
          </td>
          <td><?= htmlspecialchars(CronService::describeSchedule((string)$job['schedule_type'], (string)$job['schedule_value'])) ?></td>
          <td>
            <?= $runStatusBadge((string)$job['last_status']) ?>
            <?php if (!empty($job['last_error'])): ?>
              <div style="color:#991b1b;font-size:.74rem;max-width:220px"><?= htmlspecialchars(substr((string)$job['last_error'], 0, 160)) ?></div>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars((string)($job['last_run_at'] ?? '—')) ?></td>
          <td><?= $job['last_duration_ms'] !== null ? number_format((int)$job['last_duration_ms']) . ' ms' : '—' ?></td>
          <td><?= (int)$job['is_enabled'] === 1 ? htmlspecialchars((string)($job['next_run_at'] ?? 'next tick')) : '<span style="color:var(--muted)">disabled</span>' ?></td>
          <td><?= (int)$job['run_count'] ?> / <span style="color:<?= (int)$job['fail_count'] > 0 ? '#991b1b' : 'inherit' ?>"><?= (int)$job['fail_count'] ?></span></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <form method="post" onsubmit="return confirm('Run &quot;<?= htmlspecialchars($key) ?>&quot; now?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="run_job">
                <input type="hidden" name="job_key" value="<?= htmlspecialchars($key) ?>">
                <button type="submit" class="btn btn-edit">Run Now</button>
              </form>
              <?php if ((string)$job['last_status'] === 'failed'): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                  <input type="hidden" name="action" value="retry_job">
                  <input type="hidden" name="job_key" value="<?= htmlspecialchars($key) ?>">
                  <button type="submit" class="btn btn-toggle">Retry</button>
                </form>
              <?php endif; ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="toggle_job">
                <input type="hidden" name="job_key" value="<?= htmlspecialchars($key) ?>">
                <input type="hidden" name="enable" value="<?= (int)$job['is_enabled'] === 1 ? '0' : '1' ?>">
                <button type="submit" class="btn <?= (int)$job['is_enabled'] === 1 ? 'btn-delete' : 'btn-primary' ?>">
                  <?= (int)$job['is_enabled'] === 1 ? 'Disable' : 'Enable' ?>
                </button>
              </form>
              <a class="btn btn-ghost" style="text-decoration:none" href="?job=<?= urlencode($key) ?>#history">History</a>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ================= Task settings ================= -->
<div class="card">
  <h3 style="margin-top:0;color:var(--peacock)">Task Settings</h3>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="save_task_settings">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
      <div>
        <label>Payment-due reminder days (before expiry)</label>
        <input type="text" name="subscription_reminder_days" value="<?= htmlspecialchars($reminderDays) ?>" placeholder="3,1,0">
        <small style="color:var(--muted)">Comma separated. 0 = expiry day itself.</small>
      </div>
      <div>
        <label>Wallet low-balance threshold (₹)</label>
        <input type="number" name="wallet_low_balance_threshold" min="0" value="<?= (int)$walletThreshold ?>">
        <small style="color:var(--muted)">0 = automatic (5 × price per review).</small>
      </div>
    </div>
    <div style="margin-top:12px"><button type="submit" class="btn btn-primary">Save Task Settings</button></div>
  </form>
</div>

<!-- ================= Execution history ================= -->
<div class="card" id="history">
  <h3 style="margin-top:0;color:var(--peacock)">
    Execution History
    <?php if ($historyJob !== ''): ?>
      — <code><?= htmlspecialchars($historyJob) ?></code>
      <a href="<?= APP_URL ?>/admin_cron_settings.php#history" style="font-size:.8rem;margin-left:8px">show all</a>
    <?php endif; ?>
  </h3>
  <?php if (empty($recentRuns)): ?>
    <p style="color:var(--muted)">No runs recorded yet.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead><tr><th>#</th><th>Job</th><th>Trigger</th><th>Status</th><th>Started</th><th>Duration</th><th>Summary</th><th>Log</th></tr></thead>
        <tbody>
        <?php foreach ($recentRuns as $run): ?>
          <tr>
            <td><?= (int)$run['id'] ?></td>
            <td><code><?= htmlspecialchars((string)$run['job_key']) ?></code></td>
            <td><?= htmlspecialchars(ucfirst((string)$run['trigger_type'])) ?></td>
            <td><?= $runStatusBadge((string)$run['status']) ?></td>
            <td><?= htmlspecialchars((string)$run['started_at']) ?></td>
            <td><?= $run['duration_ms'] !== null ? number_format((int)$run['duration_ms']) . ' ms' : '—' ?></td>
            <td style="max-width:260px"><?= htmlspecialchars((string)($run['summary'] ?? '')) ?></td>
            <td>
              <?php if (!empty($run['log_text']) || !empty($run['error_message'])): ?>
                <details>
                  <summary style="cursor:pointer;color:var(--peacock);font-weight:700">View</summary>
                  <?php if (!empty($run['error_message'])): ?>
                    <div class="err" style="margin-top:6px"><?= htmlspecialchars((string)$run['error_message']) ?></div>
                  <?php endif; ?>
                  <pre style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px;font-size:.74rem;max-height:220px;overflow:auto;white-space:pre-wrap"><?= htmlspecialchars((string)($run['log_text'] ?? '')) ?></pre>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php endif; /* $tablesReady */ ?>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
