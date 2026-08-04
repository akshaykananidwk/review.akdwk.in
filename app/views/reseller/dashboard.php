<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Reseller Panel</title>
  <style>
    :root{--peacock:#005f8f;--deepyellow:#f4b400;--gold:#d4af37}
    body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9}
    header{background:linear-gradient(90deg,var(--peacock),#004367);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center}
    header a{color:#fff;font-weight:700}
    .wrap{max-width:1100px;margin:20px auto;padding:0 16px}
    .card{background:#fff;border-radius:14px;padding:16px;margin-bottom:14px;border-top:4px solid var(--deepyellow);box-shadow:0 8px 24px rgba(0,0,0,.06)}
    table{width:100%;border-collapse:collapse}
    th,td{padding:10px;border-bottom:1px solid #e5e7eb;text-align:left}
    th{color:var(--peacock);background:#f8fafc}
    .btn{display:inline-block;padding:10px 16px;border-radius:10px;background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b;font-weight:700;text-decoration:none;border:0;cursor:pointer}
    .msg{background:#ecfdf5;border:1px solid #bbf7d0;padding:10px;border-radius:10px;margin-bottom:10px}
    .err{background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:10px;margin-bottom:10px;color:#991b1b}
    label{display:block;font-weight:700;margin:8px 0 4px;color:var(--peacock)}
    input{width:100%;max-width:280px;padding:8px;border:1px solid #cbd5e1;border-radius:8px}
  </style>
</head>
<body>
<header>
  <div><strong>Reseller Panel</strong> — <?= htmlspecialchars((string)($admin['full_name'] ?? '')) ?></div>
  <div>
    <a href="<?= APP_URL ?>/reseller_add_client.php">Add client</a> &nbsp;
    <a href="<?= APP_URL ?>/logout.php">Logout</a>
  </div>
</header>
<div class="wrap">
  <?php if ($flash !== ''): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card">
    <h2 style="margin:0;color:var(--peacock)">Master wallet</h2>
    <p style="font-size:1.4rem;font-weight:800;color:#0f172a"><?= (int)($admin['reseller_wallet_balance'] ?? 0) ?> credits</p>
    <p style="color:#64748b;font-size:.9rem">Purchase bulk credits from Super Admin, then transfer to your clients below.</p>
  </div>

  <div class="card">
    <h3 style="margin:0 0 10px;color:var(--peacock)">Your clients</h3>
    <?php if (empty($clientRows)): ?>
      <p>No clients yet. <a href="<?= APP_URL ?>/reseller_add_client.php">Add your first business</a>.</p>
    <?php else: ?>
      <div style="overflow:auto">
        <table>
          <thead>
            <tr><th>Business</th><th>Email</th><th>Wallet</th><th>Total scans</th><th>AI buffer</th><th>Transfer credits</th></tr>
          </thead>
          <tbody>
            <?php foreach ($clientRows as $c): ?>
              <tr>
                <td><?= htmlspecialchars((string)$c['business_name']) ?></td>
                <td><?= htmlspecialchars((string)$c['email']) ?></td>
                <td><?= (int)$c['wallet_balance'] ?></td>
                <td><?= (int)$c['scan_count'] ?></td>
                <td>
                  <form method="post" style="display:inline" onsubmit="return confirm('Clear unused AI reviews for this client and generate a fresh buffer of <?= (int)AiReviewService::BUFFER_TARGET ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="flush_ai_buffer">
                    <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                    <button class="btn" type="submit" style="font-size:.85rem;padding:8px 12px">Flush &amp; regenerate</button>
                  </form>
                </td>
                <td>
                  <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="transfer_to_client">
                    <input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>">
                    <input type="number" name="amount" min="1" step="1" placeholder="Amount" style="width:100px" required>
                    <button class="btn" type="submit">Transfer</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
