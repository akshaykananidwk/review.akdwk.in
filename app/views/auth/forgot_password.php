<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Forgot Password - <?= htmlspecialchars(APP_NAME) ?></title>
  <style>
    :root{--peacock:#005f8f;--deepyellow:#f4b400;--gold:#d4af37}*{box-sizing:border-box}
    body{margin:0;background:linear-gradient(180deg,#f7f8fc,#eef2ff);font-family:Segoe UI,Arial,sans-serif;color:#1f2937}
    .wrap{max-width:480px;margin:32px auto;padding:14px}
    .card{background:#fff;border-radius:16px;padding:22px;border-top:5px solid var(--deepyellow);box-shadow:0 10px 26px rgba(0,95,143,.1)}
    h2{margin:0;color:var(--peacock)}
    p{color:#475569}
    label{display:block;margin:12px 0 5px;font-weight:700;color:var(--peacock)}
    input{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:10px;font-size:1rem}
    .btn{margin-top:14px;width:100%;padding:12px;border:0;border-radius:12px;font-weight:700;background:linear-gradient(90deg,var(--deepyellow),var(--gold));cursor:pointer;font-size:1rem}
    .btn-link{display:block;text-align:center;margin-top:12px;width:100%;padding:11px;border:2px solid var(--peacock);border-radius:12px;font-weight:700;color:var(--peacock);text-decoration:none}
    .err{margin-bottom:10px;background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:10px;color:#991b1b}
    .ok{margin-bottom:10px;background:#ecfdf5;border:1px solid #bbf7d0;padding:10px;border-radius:10px;color:#065f46}
    .info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;padding:10px;border-radius:10px;margin-bottom:10px}
    .wa-tag{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.78rem;font-weight:700;background:#dcfce7;color:#166534;margin-left:6px}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h2>Forgot Password <span class="wa-tag">via WhatsApp</span></h2>

    <?php if (!empty($error)): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (!empty($flash)): ?><div class="ok"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

    <?php if ($stage === 'request'): ?>
      <p>Enter the email address you registered with. We'll send a 6-digit OTP and a secure reset link directly to your registered WhatsApp number.</p>
      <form method="post" action="<?= APP_URL ?>/forgot_password.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <label>Registered Email</label>
        <input type="email" name="email" required autofocus placeholder="you@example.com">
        <button class="btn" type="submit">Send WhatsApp OTP</button>
      </form>
      <a class="btn-link" href="<?= APP_URL ?>/login.php">Back to Login</a>

    <?php elseif ($stage === 'sent'): ?>
      <div class="info">
        <strong>Request received.</strong>
        <?php if ($maskedMobile !== ''): ?>
          If your account exists and a mobile is on file, an OTP and reset link have been sent to your WhatsApp at <strong><?= htmlspecialchars($maskedMobile) ?></strong>.
        <?php else: ?>
          If your account exists and a mobile is on file, an OTP and reset link have been sent to your WhatsApp.
        <?php endif; ?>
      </div>
      <p>Didn't get it? Check your WhatsApp in a few seconds, or contact admin support.</p>
      <a class="btn-link" href="<?= APP_URL ?>/login.php">Back to Login</a>
      <a class="btn-link" href="<?= APP_URL ?>/forgot_password.php" style="margin-top:8px">Send Again</a>

    <?php elseif ($stage === 'verify'): ?>
      <p>Enter the 6-digit OTP sent to your WhatsApp <?php if ($maskedMobile !== ''): ?>(<strong><?= htmlspecialchars($maskedMobile) ?></strong>)<?php endif; ?> and choose a new password.</p>
      <form method="post" action="<?= APP_URL ?>/reset_password.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="reset_token" value="<?= htmlspecialchars($resetToken) ?>">
        <label>OTP (6 digits)</label>
        <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus>
        <label>New Password</label>
        <input type="password" name="new_password" minlength="8" required>
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" minlength="8" required>
        <button class="btn" type="submit">Reset Password</button>
      </form>
      <a class="btn-link" href="<?= APP_URL ?>/forgot_password.php">Resend OTP</a>

    <?php elseif ($stage === 'done'): ?>
      <div class="ok">
        Password reset successful. Please log in with your new credentials.
      </div>
      <a class="btn-link" href="<?= APP_URL ?>/login.php">Go to Login</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
