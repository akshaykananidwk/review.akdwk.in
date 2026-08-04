<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Krishna Review System - Unified Login</title>
  <style>
    :root{--peacock:#005f8f;--deepyellow:#f4b400;--gold:#d4af37}*{box-sizing:border-box}
    body{margin:0;background:linear-gradient(180deg,#f7f8fc,#eef2ff);font-family:Segoe UI,Arial,sans-serif}
    .wrap{max-width:460px;margin:32px auto;padding:14px}.card{background:#fff;border-radius:16px;padding:20px;border-top:5px solid var(--deepyellow);box-shadow:0 10px 26px rgba(0,95,143,.1)}
    label{display:block;margin:10px 0 6px;font-weight:600;color:var(--peacock)}input{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px}
    .btn{margin-top:12px;width:100%;padding:12px;border:0;border-radius:12px;font-weight:700;background:linear-gradient(90deg,var(--deepyellow),var(--gold));cursor:pointer}
    .btn-link{display:block;text-align:center;margin-top:10px;width:100%;padding:12px;border:2px solid var(--peacock);border-radius:12px;font-weight:700;color:var(--peacock);text-decoration:none}
    .forgot{display:block;text-align:right;margin-top:8px;color:var(--peacock);font-weight:600;text-decoration:none;font-size:.92rem}
    .forgot:hover{text-decoration:underline}
    .err{margin-bottom:10px;background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:10px;color:#991b1b}
  </style>
</head>
<body>
<div class="wrap"><div class="card">
  <h2 style="margin:0;color:#005f8f">Unified Login Gateway</h2>
  <p style="margin:6px 0 14px;color:#475569">Use your email and password. You will be redirected to Admin or Client dashboard automatically.</p>
  <?php if (!empty($error)): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <label>Email</label><input type="email" name="email" required>
    <label>Password</label><input type="password" name="password" required>
    <a class="forgot" href="<?= APP_URL ?>/forgot_password.php">Forgot Password?</a>
    <button class="btn" type="submit">Secure Login</button>
  </form>
  <a class="btn-link" href="<?= APP_URL ?>/register.php">Register Now</a>
  <a class="btn-link" href="<?= APP_URL ?>/forgot_password.php" style="margin-top:8px">Reset Password via WhatsApp</a>
</div></div>
</body>
</html>
