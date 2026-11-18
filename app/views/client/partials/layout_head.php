<?php
declare(strict_types=1);

if (!isset($pageTitle)) { $pageTitle = 'Client Panel'; }
if (!isset($activeMenu)) { $activeMenu = ''; }
if (!isset($clientForLayout) || !is_array($clientForLayout)) { $clientForLayout = []; }

// Plan expiry, resolved here rather than in each controller so it shows
// on every page of the panel — clients were complaining they could not
// find out when their access ends. clientValidityStatus() caches per
// request, so this costs one query no matter how many places read it.
require_once __DIR__ . '/../../../helpers/subscription_helper.php';
require_once __DIR__ . '/../../../config/database.php';
$layoutClientId = (int)($clientForLayout['id'] ?? ($_SESSION['client_id'] ?? 0));
$validity = $layoutClientId > 0
    ? clientValidityStatus(getPDO(), $layoutClientId)
    : ['state' => 'unknown', 'valid_until' => null, 'valid_until_human' => null,
       'days_left' => null, 'is_active' => false, 'headline' => '', 'detail' => ''];

$clientMenu = [
    ['key' => 'dashboard', 'label' => 'Dashboard',       'href' => APP_URL . '/dashboard.php',        'icon' => 'D'],
    ['key' => 'standee',   'label' => 'Standee Gallery', 'href' => APP_URL . '/standee_gallery.php',  'icon' => 'P'],
    ['key' => 'invite',    'label' => 'Send Invite',     'href' => APP_URL . '/client_send_invite.php', 'icon' => 'I'],
    ['key' => 'recharge',  'label' => 'Recharge Wallet', 'href' => APP_URL . '/client_recharge.php',  'icon' => '+'],
    ['key' => 'wallet',    'label' => 'Wallet History',  'href' => APP_URL . '/client_wallet.php',    'icon' => 'W'],
    ['key' => 'settings',  'label' => 'Settings',        'href' => APP_URL . '/client_settings.php',  'icon' => 'S'],
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

    .app-shell{display:flex;min-height:100vh}
    .app-sidebar{
      width:240px;flex:0 0 240px;
      background:linear-gradient(180deg,var(--peacock),var(--peacock-dark));
      color:#fff;padding:18px 0;
      position:sticky;top:0;height:100vh;
      box-shadow:2px 0 12px rgba(0,0,0,.08);
      overflow-y:auto;z-index:50;
      display:flex;flex-direction:column;
    }
    .app-sidebar .brand{display:flex;align-items:center;gap:10px;padding:0 18px 14px;border-bottom:1px solid rgba(255,255,255,.15);margin-bottom:10px}
    .app-sidebar .brand-logo{width:38px;height:38px;border-radius:10px;background:var(--deepyellow);color:#1e293b;display:grid;place-items:center;font-weight:800}
    .app-sidebar .brand-name{font-weight:700;font-size:1rem;line-height:1.1}
    .app-sidebar .brand-name small{display:block;font-weight:400;opacity:.75;font-size:.72rem;margin-top:2px}
    .app-sidebar nav{display:flex;flex-direction:column;gap:2px;padding:6px 10px}
    .app-sidebar nav a{
      display:flex;align-items:center;gap:10px;
      color:#fff;text-decoration:none;
      padding:11px 12px;border-radius:10px;font-weight:600;font-size:.95rem;
      transition:background .15s;
    }
    .app-sidebar nav a .ico{
      width:26px;height:26px;border-radius:7px;background:rgba(255,255,255,.12);
      display:grid;place-items:center;font-size:.8rem;font-weight:700;
    }
    .app-sidebar nav a:hover{background:rgba(255,255,255,.10)}
    .app-sidebar nav a.active{background:var(--deepyellow);color:#1e293b}
    .app-sidebar nav a.active .ico{background:rgba(0,0,0,.15);color:#1e293b}
    .app-sidebar .sidebar-footer{margin-top:auto;padding:14px 18px;font-size:.75rem;opacity:.6}

    .app-main{flex:1;min-width:0;display:flex;flex-direction:column}
    .app-topbar{
      background:#fff;border-bottom:1px solid #e2e8f0;
      padding:12px 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;
      position:sticky;top:0;z-index:40;
    }
    .app-topbar h1{font-size:1.1rem;margin:0;color:var(--peacock)}
    .biz-chip{display:flex;align-items:center;gap:10px;font-size:.9rem;color:#0f172a}
    .biz-chip strong{color:var(--peacock)}
    .top-actions a{color:var(--peacock);text-decoration:none;font-weight:700;margin-left:14px}
    .top-actions a.logout{color:#b91c1c}
    .app-content{padding:18px;flex:1}

    .menu-toggle{display:none;background:transparent;border:0;color:var(--peacock);font-size:1.4rem;cursor:pointer}
    @media (max-width: 900px){
      .menu-toggle{display:inline-block}
      .app-sidebar{position:fixed;top:0;left:-260px;height:100vh;transition:left .25s ease}
      .app-sidebar.open{left:0}
      .sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:45}
      .sidebar-backdrop.show{display:block}
    }

    .card{background:#fff;border-radius:14px;padding:16px;border-top:5px solid var(--deepyellow);box-shadow:0 10px 26px rgba(0,95,143,.1);margin-bottom:14px}
    .card h3{margin:0 0 10px;color:var(--peacock)}
    .msg{padding:10px;border-radius:10px;background:#ecfdf5;border:1px solid #bbf7d0;margin-bottom:10px}
    .err{padding:10px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;margin-bottom:10px;color:#991b1b}
    label{display:block;font-weight:700;color:var(--peacock);margin:6px 0 4px}
    input,select,textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;font-family:inherit}
    textarea{min-height:90px}
    .btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b;text-decoration:none;display:inline-block}
    .btn-ghost{background:#f1f5f9;color:#0f172a}
    .btn-secondary{background:var(--peacock);color:#fff}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
    th{color:var(--peacock);background:#f8fafc}

    .kpi-grid{display:grid;gap:12px;grid-template-columns:1fr}
    @media(min-width:760px){.kpi-grid{grid-template-columns:repeat(4,1fr)}}
    .kpi-grid .card .v{font-size:1.9rem;font-weight:800;color:var(--peacock)}
    .pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:700}
    .pill-credit{background:#dcfce7;color:#166534}
    .pill-debit{background:#fee2e2;color:#991b1b}

    /* Plan validity: header chip + full-width notice */
    .plan-chip{
      display:inline-flex;align-items:center;gap:6px;
      padding:5px 11px;border-radius:999px;
      font-size:.8rem;font-weight:700;white-space:nowrap;
      border:1px solid transparent;text-decoration:none;
    }
    .plan-chip .dot{width:7px;height:7px;border-radius:50%;background:currentColor;flex:0 0 7px}
    .plan-chip .chip-label{font-weight:600;opacity:.8}
    .plan-ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
    .plan-warning{background:#fffbeb;color:#92400e;border-color:#fde68a}
    .plan-critical{background:#fff7ed;color:#9a3412;border-color:#fdba74}
    .plan-expired{background:#fef2f2;color:#991b1b;border-color:#fecaca}
    .plan-none{background:#f1f5f9;color:#475569;border-color:#cbd5e1}

    .plan-notice{
      display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
      padding:12px 14px;border-radius:12px;border:1px solid;margin-bottom:14px;
    }
    .plan-notice strong{display:block;font-size:1rem}
    .plan-notice span{font-size:.9rem;opacity:.9}
    .plan-notice .btn{flex:0 0 auto}
    .notice-warning{background:#fffbeb;border-color:#fde68a;color:#92400e}
    .notice-critical{background:#fff7ed;border-color:#fdba74;color:#9a3412}
    .notice-expired{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .notice-none{background:#f1f5f9;border-color:#cbd5e1;color:#334155}

    @media (max-width:640px){
      .biz-chip{flex-wrap:wrap;justify-content:flex-end;gap:6px}
      .plan-chip{font-size:.74rem;padding:4px 9px}
    }
  </style>
</head>
<body>
<div class="app-shell">
  <aside class="app-sidebar" id="appSidebar">
    <div class="brand">
      <div class="brand-logo">K</div>
      <div class="brand-name"><?= htmlspecialchars((string)($clientForLayout['business_name'] ?? 'Client')) ?>
        <small><?= htmlspecialchars((string)($clientForLayout['email'] ?? 'Client Panel')) ?></small>
      </div>
    </div>
    <nav>
      <?php foreach ($clientMenu as $m): ?>
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

  <div class="app-main">
    <header class="app-topbar">
      <div style="display:flex;align-items:center;gap:10px">
        <button type="button" class="menu-toggle" id="menuToggle" aria-label="Toggle menu">&#9776;</button>
        <h1><?= htmlspecialchars((string)$pageTitle) ?></h1>
      </div>
      <div class="biz-chip">
        <?php if (!empty($clientForLayout['wallet_balance']) || ($clientForLayout['wallet_balance'] ?? null) === 0): ?>
          <span>Wallet: <strong><?= (int)$clientForLayout['wallet_balance'] ?></strong> credits</span>
        <?php endif; ?>
        <?php if (in_array($validity['state'], ['ok', 'warning', 'critical', 'expired', 'none'], true)): ?>
          <a class="plan-chip plan-<?= htmlspecialchars($validity['state']) ?>"
             href="<?= APP_URL ?>/client_recharge.php"
             title="<?= htmlspecialchars($validity['headline'] . ' — ' . $validity['detail']) ?>">
            <span class="dot"></span>
            <span class="chip-label">Plan:</span>
            <span><?= htmlspecialchars(validityChipText($validity)) ?></span>
          </a>
        <?php endif; ?>
        <span class="top-actions"><a class="logout" href="<?= APP_URL ?>/logout.php">Logout</a></span>
      </div>
    </header>
    <main class="app-content">
<?php if (empty($suppressPlanNotice) && in_array($validity['state'], ['warning', 'critical', 'expired', 'none'], true)): ?>
      <div class="plan-notice notice-<?= htmlspecialchars($validity['state']) ?>">
        <div>
          <strong><?= htmlspecialchars($validity['headline']) ?></strong>
          <span><?= htmlspecialchars($validity['detail']) ?></span>
        </div>
        <a class="btn" href="<?= APP_URL ?>/client_recharge.php<?= in_array($validity['state'], ['expired', 'none'], true) ? '?renew=1' : '' ?>">
          <?= in_array($validity['state'], ['expired', 'none'], true) ? 'Recharge Now' : 'Extend Plan' ?>
        </a>
      </div>
<?php endif; ?>
