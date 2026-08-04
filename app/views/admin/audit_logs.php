<?php
$pageTitle = 'Audit & Security Logs';
$activeMenu = 'audit';
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.78rem;font-weight:700}
  .admin{background:#dbeafe;color:#1e40af}.client{background:#dcfce7;color:#166534}
</style>

<div class="card">
  <h3 style="margin:0 0 10px;color:#0b1f4d">Login History (Recent 100)</h3>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>User Type</th><th>Email</th><th>IP</th><th>Login Time</th></tr></thead>
      <tbody>
        <?php foreach ($loginHistory as $log): ?>
          <tr>
            <td><span class="pill <?= $log['user_type'] === 'admin' ? 'admin':'client' ?>"><?= htmlspecialchars($log['user_type']) ?></span></td>
            <td><?= htmlspecialchars($log['email']) ?></td>
            <td><?= htmlspecialchars((string)$log['ip_address']) ?></td>
            <td><?= htmlspecialchars((string)$log['login_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:#0b1f4d">Admin Activity (Recent 100)</h3>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>Admin</th><th>Action</th><th>Target</th><th>Description</th><th>IP</th><th>Time</th></tr></thead>
      <tbody>
        <?php foreach ($adminActivities as $log): ?>
          <tr>
            <td><?= htmlspecialchars($log['admin_email']) ?></td>
            <td><?= htmlspecialchars($log['action']) ?></td>
            <td><?= htmlspecialchars((string)$log['target_type']) ?> #<?= htmlspecialchars((string)$log['target_id']) ?></td>
            <td><?= htmlspecialchars((string)$log['description']) ?></td>
            <td><?= htmlspecialchars((string)$log['ip_address']) ?></td>
            <td><?= htmlspecialchars((string)$log['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 10px;color:#0b1f4d">Registration Report (Recent 200)</h3>
  <div style="overflow:auto">
    <table>
      <thead><tr><th>Business</th><th>Category</th><th>Email</th><th>Mobile</th><th>Status</th><th>Registered At</th></tr></thead>
      <tbody>
        <?php foreach ($registrationReport as $row): ?>
          <tr>
            <td><?= htmlspecialchars($row['business_name']) ?></td>
            <td><?= htmlspecialchars($row['category_name']) ?></td>
            <td><?= htmlspecialchars($row['email']) ?></td>
            <td><?= htmlspecialchars((string)$row['mobile']) ?></td>
            <td><?= (int)$row['is_active'] === 1 ? 'Active' : 'Suspended' ?></td>
            <td><?= htmlspecialchars((string)$row['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
