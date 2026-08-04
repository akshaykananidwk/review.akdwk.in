<?php
declare(strict_types=1);

if (!isset($pageTitle)) {
    $pageTitle = 'Admin Panel';
}
if (!isset($activeMenu)) {
    $activeMenu = '';
}

$adminMenu = [
    ['key' => 'dashboard',     'label' => 'Dashboard',          'href' => APP_URL . '/super_admin.php',                'icon' => 'D'],
    ['key' => 'businesses',    'label' => 'Manage Businesses',  'href' => APP_URL . '/admin_businesses.php',           'icon' => 'B'],
    ['key' => 'categories',    'label' => 'Categories & Facilities', 'href' => APP_URL . '/admin_category_facilities.php', 'icon' => 'C'],
    ['key' => 'standee_tpl',   'label' => 'Standee Templates',  'href' => APP_URL . '/admin_standee_templates.php',    'icon' => 'T'],
    ['key' => 'reports',       'label' => 'Reports',            'href' => APP_URL . '/admin_reports.php',              'icon' => 'R'],
    ['key' => 'plans',         'label' => 'Recharge Plans',     'href' => APP_URL . '/admin_payment_plans.php',        'icon' => '₹'],
    ['key' => 'reseller_wallet','label' => 'Reseller Wallets', 'href' => APP_URL . '/admin_reseller_wallet.php',      'icon' => 'W'],
    ['key' => 'create_reseller','label' => 'Create Reseller', 'href' => APP_URL . '/admin_create_reseller.php',      'icon' => '+'],
    ['key' => 'global',        'label' => 'Global Settings',    'href' => APP_URL . '/admin_global_settings.php',      'icon' => 'G'],
    ['key' => 'cron',          'label' => 'Cron Settings',      'href' => APP_URL . '/admin_cron_settings.php',        'icon' => '⏱'],
    ['key' => 'system_update', 'label' => 'System Update',      'href' => APP_URL . '/admin_system_update.php',        'icon' => '⟳'],
    ['key' => 'audit',         'label' => 'Audit & Logs',       'href' => APP_URL . '/admin_audit.php',                'icon' => 'A'],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= htmlspecialchars((string)$pageTitle) ?></title>
  <style>
    :root{--peacock:#005f8f;--peacock-dark:#004367;--deepyellow:#f4b400;--gold:#d4af37;--muted:#475569;--bg:#f7f8fc}
    *{box-sizing:border-box}
    html,body{margin:0;padding:0}
    body{background:linear-gradient(180deg,#f7f8fc,#eef2ff);font-family:Segoe UI,Arial,sans-serif;color:#1f2937;min-height:100vh}

    /* ---------- Layout shell (always visible sidebar + content) ---------- */
    .admin-shell{display:flex;min-height:100vh}
    .admin-sidebar{
      width:240px;flex:0 0 240px;
      background:linear-gradient(180deg,var(--peacock),var(--peacock-dark));
      color:#fff;
      padding:18px 0;
      position:sticky;top:0;height:100vh;
      box-shadow:2px 0 12px rgba(0,0,0,.08);
      overflow-y:auto;
      z-index:50;
    }
    .admin-sidebar .brand{display:flex;align-items:center;gap:10px;padding:0 18px 14px;border-bottom:1px solid rgba(255,255,255,.15);margin-bottom:10px}
    .admin-sidebar .brand-logo{width:38px;height:38px;border-radius:10px;background:var(--deepyellow);color:#1e293b;display:grid;place-items:center;font-weight:800}
    .admin-sidebar .brand-name{font-weight:700;font-size:1rem;line-height:1.1}
    .admin-sidebar .brand-name small{display:block;font-weight:400;opacity:.75;font-size:.72rem;margin-top:2px}
    .admin-sidebar nav{display:flex;flex-direction:column;gap:2px;padding:6px 10px}
    .admin-sidebar nav a{
      display:flex;align-items:center;gap:10px;
      color:#fff;text-decoration:none;
      padding:11px 12px;border-radius:10px;font-weight:600;font-size:.95rem;
      transition:background .15s,transform .05s;
    }
    .admin-sidebar nav a .ico{
      width:26px;height:26px;border-radius:7px;background:rgba(255,255,255,.12);
      display:grid;place-items:center;font-size:.8rem;font-weight:700;
    }
    .admin-sidebar nav a:hover{background:rgba(255,255,255,.10)}
    .admin-sidebar nav a.active{background:var(--deepyellow);color:#1e293b}
    .admin-sidebar nav a.active .ico{background:rgba(0,0,0,.15);color:#1e293b}
    .admin-sidebar .sidebar-footer{margin-top:auto;padding:14px 18px;font-size:.75rem;opacity:.6}
    .admin-sidebar .sidebar-footer a{color:#fff}

    .admin-main{flex:1;min-width:0;display:flex;flex-direction:column}
    .admin-topbar{
      background:#fff;border-bottom:1px solid #e2e8f0;
      padding:12px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;
      position:sticky;top:0;z-index:40;
    }
    .admin-topbar h1{font-size:1.1rem;margin:0;color:var(--peacock)}
    .admin-topbar .top-actions a{color:var(--peacock);text-decoration:none;font-weight:700;margin-left:14px}
    .admin-topbar .top-actions a.logout{color:#b91c1c}

    .admin-content{padding:18px;flex:1}

    .menu-toggle{display:none;background:transparent;border:0;color:var(--peacock);font-size:1.4rem;cursor:pointer}

    /* ---------- Mobile / tablet ---------- */
    @media (max-width: 900px){
      .admin-shell{flex-direction:row}
      .menu-toggle{display:inline-block}
      .admin-sidebar{
        position:fixed;top:0;left:-260px;height:100vh;
        transition:left .25s ease;
      }
      .admin-sidebar.open{left:0}
      .sidebar-backdrop{
        display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:45;
      }
      .sidebar-backdrop.show{display:block}
    }

    /* ---------- Cards & shared admin styles ---------- */
    .card{background:#fff;border-radius:14px;padding:16px;border-top:5px solid var(--deepyellow);box-shadow:0 10px 26px rgba(0,95,143,.1);margin-bottom:14px}
    .msg{padding:10px;border-radius:10px;background:#ecfdf5;border:1px solid #bbf7d0;margin-bottom:10px}
    .err{padding:10px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;margin-bottom:10px;color:#991b1b}

    label{display:block;font-weight:700;color:var(--peacock);margin-bottom:4px}
    input,select,textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;font-family:inherit}
    .btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b}
    .btn-primary{background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b}
    .btn-edit{background:#dbeafe;color:#1e3a8a}
    .btn-toggle{background:#fef3c7;color:#92400e}
    .btn-delete{background:#fee2e2;color:#991b1b}
    .btn-ghost{background:#f1f5f9;color:#0f172a}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
    th{color:var(--peacock);background:#f8fafc}
  </style>
</head>
<body>
<div class="admin-shell">
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="brand">
      <div class="brand-logo">K</div>
      <div class="brand-name">Krishna Admin<small>Super Admin Panel</small></div>
    </div>
    <nav>
      <?php foreach ($adminMenu as $m): ?>
        <a href="<?= htmlspecialchars($m['href']) ?>" class="<?= $activeMenu === $m['key'] ? 'active' : '' ?>">
          <span class="ico"><?= htmlspecialchars($m['icon']) ?></span>
          <span><?= htmlspecialchars($m['label']) ?></span>
        </a>
      <?php endforeach; ?>
      <a href="<?= APP_URL ?>/logout.php" style="margin-top:14px;background:rgba(255,255,255,.06)">
        <span class="ico">&#x21B7;</span><span>Logout</span>
      </a>
    </nav>
    <div class="sidebar-footer">&copy; <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?></div>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="admin-main">
    <header class="admin-topbar">
      <div style="display:flex;align-items:center;gap:10px">
        <button type="button" class="menu-toggle" id="menuToggle" aria-label="Toggle menu">&#9776;</button>
        <h1><?= htmlspecialchars((string)$pageTitle) ?></h1>
      </div>
      <div class="top-actions">
        <a href="<?= APP_URL ?>/" target="_blank" rel="noopener">View Site</a>
        <a class="logout" href="<?= APP_URL ?>/logout.php">Logout</a>
      </div>
    </header>
    <main class="admin-content">
