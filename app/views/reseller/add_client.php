<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Add Client — Reseller</title>
  <style>
    :root{--peacock:#005f8f;--deepyellow:#f4b400;--gold:#d4af37}
    body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9}
    header{background:linear-gradient(90deg,var(--peacock),#004367);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center}
    header a{color:#fff;font-weight:700}
    .wrap{max-width:720px;margin:20px auto;padding:0 16px}
    .card{background:#fff;border-radius:14px;padding:18px;border-top:4px solid var(--deepyellow)}
    label{display:block;font-weight:700;margin:10px 0 4px;color:var(--peacock)}
    input,select,textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;box-sizing:border-box}
    .btn{margin-top:14px;padding:12px 18px;border:0;border-radius:10px;font-weight:800;background:linear-gradient(90deg,var(--deepyellow),var(--gold));cursor:pointer}
    .msg{background:#ecfdf5;border:1px solid #bbf7d0;padding:10px;border-radius:10px;margin-bottom:10px}
    .err{background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:10px;margin-bottom:10px;color:#991b1b}
  </style>
</head>
<body>
<header>
  <strong>Add client</strong>
  <a href="<?= APP_URL ?>/reseller_panel.php">← Back</a>
</header>
<div class="wrap">
  <div class="card">
    <?php if ($flash !== ''): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <label>Business name *</label>
      <input name="business_name" required>
      <label>Owner name</label>
      <input name="owner_name">
      <label>Email (login) *</label>
      <input type="email" name="email" required>
      <label>Password * (min 8)</label>
      <input type="password" name="password" required minlength="8">
      <label>Mobile *</label>
      <input name="mobile" required>
      <label>Address *</label>
      <textarea name="address" rows="3" required></textarea>
      <label>Category *</label>
      <select name="category_id" required>
        <option value="">Select</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars((string)$cat['category_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Google Place ID *</label>
      <input name="google_place_id" required>
      <button class="btn" type="submit">Create client</button>
    </form>
  </div>
</div>
</body>
</html>
