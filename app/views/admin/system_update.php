<?php
declare(strict_types=1);
/**
 * Admin: GitHub Auto Update System.
 *
 * Variables provided by SystemUpdateController::index():
 * $tablesReady, $config, $currentVersion, $currentCommit, $lastCheck,
 * $lastUpdateAt, $updateHistory, $backupHistory, $csrfToken, $flash, $flashError
 */
require __DIR__ . '/partials/layout_head.php';

$statusBadge = static function (string $status): string {
    $map = [
        'success' => ['#ecfdf5', '#065f46', 'Success'],
        'running' => ['#eff6ff', '#1e40af', 'Running'],
        'pending' => ['#f8fafc', '#334155', 'Pending'],
        'failed' => ['#fef2f2', '#991b1b', 'Failed'],
        'rolled_back' => ['#fffbeb', '#92400e', 'Rolled Back'],
        'created' => ['#ecfdf5', '#065f46', 'Available'],
        'restored' => ['#fffbeb', '#92400e', 'Restored'],
    ];
    [$bg, $fg, $label] = $map[$status] ?? ['#f8fafc', '#334155', ucfirst($status)];
    return '<span style="background:' . $bg . ';color:' . $fg . ';padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700">' . htmlspecialchars($label) . '</span>';
};
?>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if (!empty($flashError)): ?><div class="err"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

<?php if (!$tablesReady): ?>
  <div class="card">
    <h3 style="margin-top:0;color:var(--peacock)">Enable the GitHub Auto Update System</h3>
    <p>The update system tables are not installed yet. Click below to run the one-time installation
       (creates <code>system_updates</code>, <code>system_update_backups</code> and <code>system_migrations</code>).</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <input type="hidden" name="action" value="install_updater_tables">
      <button type="submit" class="btn btn-primary">Install Update System</button>
    </form>
  </div>
<?php endif; ?>

<!-- ================= GitHub configuration ================= -->
<div class="card">
  <h3 style="margin-top:0;color:var(--peacock)">GitHub Repository Settings</h3>
  <p style="color:var(--muted);margin-top:4px">
    Save the repository once — after that every update is one click, no ZIP uploads.
    The token is stored write-only and never displayed again.
  </p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="action" value="save_github_settings">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
      <div>
        <label>Repository (owner/name)</label>
        <input type="text" name="github_repo" placeholder="e.g. akshaykananidwk/review.akdwk.in"
               value="<?= htmlspecialchars($config['repo']) ?>" required>
      </div>
      <div>
        <label>Branch</label>
        <input type="text" name="github_branch" value="<?= htmlspecialchars($config['branch']) ?>" placeholder="main">
      </div>
      <div>
        <label>Personal Access Token <?= $config['token'] !== '' ? '<span style="color:#065f46;font-weight:600">(saved ✓)</span>' : '' ?></label>
        <input type="password" name="github_token" autocomplete="new-password"
               placeholder="<?= $config['token'] !== '' ? 'Leave blank to keep the saved token' : 'ghp_... / github_pat_...' ?>">
      </div>
    </div>
    <div style="margin-top:12px">
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
  </form>
</div>

<!-- ================= Version / check / update ================= -->
<div class="card">
  <h3 style="margin-top:0;color:var(--peacock)">Version &amp; Updates</h3>
  <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center">
    <div>
      <div style="color:var(--muted);font-size:.8rem">Current Version</div>
      <div style="font-size:1.4rem;font-weight:800;color:var(--peacock)">v<?= htmlspecialchars($currentVersion) ?></div>
      <div style="color:var(--muted);font-size:.78rem">
        Commit: <code><?= $currentCommit !== '' ? htmlspecialchars(substr($currentCommit, 0, 10)) : 'not recorded yet' ?></code>
        <?php if ($lastUpdateAt !== ''): ?> &middot; Last update: <?= htmlspecialchars($lastUpdateAt) ?><?php endif; ?>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;gap:10px">
      <button type="button" class="btn btn-ghost" id="btnCheck" <?= (!$tablesReady || !$service->isConfigured()) ? 'disabled' : '' ?>>Check for Update</button>
      <button type="button" class="btn btn-primary" id="btnUpdate" style="display:none">Update Now</button>
    </div>
  </div>

  <div id="checkResult" style="margin-top:14px"></div>

  <!-- Progress panel -->
  <div id="progressPanel" style="display:none;margin-top:16px;border:1px solid #e2e8f0;border-radius:12px;padding:14px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <strong id="progressTitle" style="color:var(--peacock)">Updating…</strong>
      <span id="progressPct" style="font-weight:800">0%</span>
    </div>
    <div style="background:#e2e8f0;border-radius:999px;height:12px;overflow:hidden">
      <div id="progressBar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--deepyellow),var(--gold));transition:width .4s"></div>
    </div>
    <ul id="stepList" style="list-style:none;margin:12px 0 0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:6px"></ul>
    <pre id="updateLog" style="background:#0f172a;color:#e2e8f0;border-radius:10px;padding:12px;margin-top:12px;max-height:260px;overflow:auto;font-size:.78rem;white-space:pre-wrap"></pre>
  </div>
</div>

<!-- ================= Update history ================= -->
<div class="card">
  <h3 style="margin-top:0;color:var(--peacock)">Update &amp; Rollback History</h3>
  <?php if (empty($updateHistory)): ?>
    <p style="color:var(--muted)">No updates have been run yet.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead><tr><th>#</th><th>Type</th><th>From → To</th><th>Commit</th><th>Status</th><th>Started</th><th>Finished</th><th>Log</th></tr></thead>
        <tbody>
        <?php foreach ($updateHistory as $u): ?>
          <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= $u['update_type'] === 'rollback' ? '↩ Rollback' : '⬆ Update' ?></td>
            <td>v<?= htmlspecialchars((string)$u['from_version']) ?> → v<?= htmlspecialchars((string)$u['to_version']) ?></td>
            <td><code><?= htmlspecialchars(substr((string)$u['to_commit'], 0, 8)) ?></code></td>
            <td><?= $statusBadge((string)$u['status']) ?></td>
            <td><?= htmlspecialchars((string)($u['started_at'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($u['finished_at'] ?? '')) ?></td>
            <td>
              <?php if (!empty($u['log_text']) || !empty($u['error_message'])): ?>
                <details>
                  <summary style="cursor:pointer;color:var(--peacock);font-weight:700">View</summary>
                  <?php if (!empty($u['error_message'])): ?>
                    <div class="err" style="margin-top:6px"><?= htmlspecialchars((string)$u['error_message']) ?></div>
                  <?php endif; ?>
                  <pre style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px;font-size:.74rem;max-height:200px;overflow:auto;white-space:pre-wrap"><?= htmlspecialchars((string)$u['log_text']) ?></pre>
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

<!-- ================= Backup history ================= -->
<div class="card">
  <h3 style="margin-top:0;color:var(--peacock)">Backup History</h3>
  <p style="color:var(--muted);margin-top:4px">
    A full code + database backup is taken automatically before every update.
    Rolling back restores both. Protected data (<code>.env</code>, <code>config.php</code>,
    <code>public/uploads/</code>, logs) is never touched by updates or rollbacks.
  </p>
  <?php if (empty($backupHistory)): ?>
    <p style="color:var(--muted)">No backups yet — one is created automatically before each update.</p>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table>
        <thead><tr><th>#</th><th>Name</th><th>Version</th><th>Commit</th><th>Size</th><th>Status</th><th>Created</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($backupHistory as $b): ?>
          <tr>
            <td><?= (int)$b['id'] ?></td>
            <td><code><?= htmlspecialchars((string)$b['backup_name']) ?></code></td>
            <td>v<?= htmlspecialchars((string)$b['app_version']) ?></td>
            <td><code><?= htmlspecialchars(substr((string)$b['commit_hash'], 0, 8)) ?></code></td>
            <td><?= number_format(((int)$b['size_bytes']) / 1048576, 1) ?> MB</td>
            <td><?= $statusBadge((string)$b['status']) ?></td>
            <td><?= htmlspecialchars((string)$b['created_at']) ?></td>
            <td><button type="button" class="btn btn-toggle btn-rollback" data-backup-id="<?= (int)$b['id'] ?>"
                        data-backup-name="<?= htmlspecialchars((string)$b['backup_name']) ?>">Rollback</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode($csrfToken) ?>;
  var API = <?= json_encode(APP_URL . '/admin_system_update.php?ajax=1') ?>;
  var STEPS = [
    ['backup',   'Auto Backup'],
    ['download', 'Download'],
    ['verify',   'Verify Files'],
    ['deploy',   'Deploy'],
    ['migrate',  'DB Migration'],
    ['finalize', 'Finalize']
  ];

  var btnCheck = document.getElementById('btnCheck');
  var btnUpdate = document.getElementById('btnUpdate');
  var checkResult = document.getElementById('checkResult');
  var panel = document.getElementById('progressPanel');
  var bar = document.getElementById('progressBar');
  var pct = document.getElementById('progressPct');
  var title = document.getElementById('progressTitle');
  var stepList = document.getElementById('stepList');
  var logBox = document.getElementById('updateLog');
  var running = false;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function api(payload) {
    payload.csrf_token = CSRF;
    return fetch(API, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (r) { return r.json(); });
  }

  function renderSteps(activeIdx, failedIdx) {
    stepList.innerHTML = STEPS.map(function (s, i) {
      var mark = '⏳', color = '#94a3b8';
      if (failedIdx === i) { mark = '✖'; color = '#991b1b'; }
      else if (i < activeIdx) { mark = '✔'; color = '#065f46'; }
      else if (i === activeIdx) { mark = '●'; color = '#1e40af'; }
      return '<li style="color:' + color + ';font-weight:700;font-size:.82rem">' + mark + ' ' + s[1] + '</li>';
    }).join('');
  }

  function setProgress(p) {
    bar.style.width = p + '%';
    pct.textContent = p + '%';
  }

  function showCheck(c) {
    if (!c.update_available) {
      checkResult.innerHTML =
        '<div class="msg" style="font-weight:700">✔ Already Up To Date — you are running the latest version (v' +
        esc(c.current_version) + ').</div>';
      btnUpdate.style.display = 'none';
      return;
    }
    var files = (c.changed_files || []);
    var fileRows = files.map(function (f) {
      return '<tr><td><code>' + esc(f.filename) + '</code></td><td>' + esc(f.status) +
             '</td><td style="color:#065f46">+' + f.additions + '</td><td style="color:#991b1b">-' + f.deletions + '</td></tr>';
    }).join('');
    checkResult.innerHTML =
      '<div style="border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;padding:14px">' +
      '<div style="font-weight:800;color:#1e40af;margin-bottom:8px">⬆ Update Available</div>' +
      '<table style="width:auto"><tbody>' +
      '<tr><th style="background:none">Current Version</th><td>v' + esc(c.current_version) + '</td></tr>' +
      '<tr><th style="background:none">Latest Version</th><td><strong>v' + esc(c.latest_version) + '</strong>' +
        (c.commits_behind ? ' <span style="color:var(--muted)">(' + c.commits_behind + ' commit(s) behind)</span>' : '') + '</td></tr>' +
      '<tr><th style="background:none">Commit</th><td><code>' + esc((c.latest_commit || '').slice(0, 10)) + '</code></td></tr>' +
      '<tr><th style="background:none">Message</th><td>' + esc(c.commit_message) + '</td></tr>' +
      '<tr><th style="background:none">Author</th><td>' + esc(c.commit_author) + '</td></tr>' +
      '<tr><th style="background:none">Date</th><td>' + esc(c.commit_date) + '</td></tr>' +
      '</tbody></table>' +
      (files.length
        ? '<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700;color:var(--peacock)">Changed Files (' + files.length + ')</summary>' +
          '<div style="overflow-x:auto;max-height:220px;overflow-y:auto"><table><thead><tr><th>File</th><th>Change</th><th>+</th><th>-</th></tr></thead><tbody>' +
          fileRows + '</tbody></table></div></details>'
        : '') +
      (c.release_notes
        ? '<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700;color:var(--peacock)">Release Notes</summary>' +
          '<pre style="white-space:pre-wrap;background:#f8fafc;border-radius:8px;padding:10px;font-size:.8rem">' + esc(c.release_notes) + '</pre></details>'
        : '') +
      '</div>';
    btnUpdate.style.display = 'inline-block';
  }

  btnCheck && btnCheck.addEventListener('click', function () {
    if (running) return;
    btnCheck.disabled = true;
    btnCheck.textContent = 'Checking…';
    checkResult.innerHTML = '';
    api({action: 'check_update'}).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Check failed');
      showCheck(res.check);
    }).catch(function (e) {
      checkResult.innerHTML = '<div class="err">' + esc(e.message) + '</div>';
    }).finally(function () {
      btnCheck.disabled = false;
      btnCheck.textContent = 'Check for Update';
    });
  });

  function applyUpdateState(u) {
    setProgress(u.progress || 0);
    logBox.textContent = u.log_text || '';
    logBox.scrollTop = logBox.scrollHeight;
  }

  function runSteps(updateId, stepIdx) {
    if (stepIdx >= STEPS.length) return Promise.resolve();
    renderSteps(stepIdx, -1);
    return api({action: 'run_step', update_id: updateId, step: STEPS[stepIdx][0]}).then(function (res) {
      if (!res.ok) {
        // Pull the final state (rollback log) before failing.
        return api({action: 'get_update', update_id: updateId}).then(function (st) {
          if (st.ok) applyUpdateState(st.update);
          renderSteps(stepIdx, stepIdx);
          throw new Error(res.error || 'Update step failed');
        });
      }
      applyUpdateState(res.update);
      if (res.update.status === 'success') {
        renderSteps(STEPS.length, -1);
        return Promise.resolve();
      }
      return runSteps(updateId, stepIdx + 1);
    });
  }

  btnUpdate && btnUpdate.addEventListener('click', function () {
    if (running) return;
    if (!confirm('Start the update now?\n\nA full backup (code + database) is taken first. If anything fails, the system rolls back automatically.')) {
      return;
    }
    running = true;
    btnUpdate.disabled = true;
    btnCheck.disabled = true;
    panel.style.display = 'block';
    title.textContent = 'Updating… please keep this page open.';
    logBox.textContent = '';
    setProgress(2);
    renderSteps(-1, -1);

    api({action: 'start_update'}).then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Could not start the update');
      applyUpdateState(res.update);
      return runSteps(res.update.id, 0);
    }).then(function () {
      title.textContent = '✔ Update completed successfully!';
      title.style.color = '#065f46';
      setTimeout(function () { window.location.reload(); }, 2500);
    }).catch(function (e) {
      title.textContent = '✖ Update failed — automatic rollback was performed.';
      title.style.color = '#991b1b';
      logBox.textContent += '\n[ERROR] ' + e.message;
      logBox.scrollTop = logBox.scrollHeight;
      running = false;
      btnUpdate.disabled = false;
      btnCheck.disabled = false;
    });
  });

  Array.prototype.forEach.call(document.querySelectorAll('.btn-rollback'), function (btn) {
    btn.addEventListener('click', function () {
      if (running) return;
      var id = parseInt(btn.getAttribute('data-backup-id'), 10);
      var name = btn.getAttribute('data-backup-name');
      if (!confirm('Rollback to backup "' + name + '"?\n\nThis restores BOTH the code and the database from that backup. Protected data (.env, uploads) is not touched.')) {
        return;
      }
      running = true;
      btn.disabled = true;
      btn.textContent = 'Rolling back…';
      panel.style.display = 'block';
      title.textContent = 'Rolling back…';
      renderSteps(-1, -1);
      setProgress(10);
      api({action: 'rollback', backup_id: id}).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Rollback failed');
        applyUpdateState(res.update);
        setProgress(100);
        title.textContent = '✔ Rollback completed.';
        title.style.color = '#065f46';
        setTimeout(function () { window.location.reload(); }, 2000);
      }).catch(function (e) {
        title.textContent = '✖ Rollback failed.';
        title.style.color = '#991b1b';
        logBox.textContent += '\n[ERROR] ' + e.message;
        running = false;
        btn.disabled = false;
        btn.textContent = 'Rollback';
      });
    });
  });
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
