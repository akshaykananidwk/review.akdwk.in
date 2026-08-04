<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/settings_helper.php';

$pdo = getPDO();
$systemName     = getSystemSetting($pdo, 'system_name', defined('APP_NAME') ? APP_NAME : 'Krishna Review System');
$helplineNumber = getSystemSetting($pdo, 'helpline_number', getSystemSetting($pdo, 'support_mobile', ''));
$helplineTel    = preg_replace('/[^0-9+]/', '', $helplineNumber) ?? '';

// Pre-canned demo reviews shown when the user picks 5 stars.
$demoReviews = [
    "Absolutely fantastic experience! The staff was incredibly welcoming and the service was top-notch from start to finish. Highly recommended for anyone looking for quality and professionalism in our area.",
    "I have been a regular customer for over a year now and they have never disappointed. The quality is consistent, prices are fair, and the team always goes out of their way to help. 5 stars well deserved!",
    "Hands down the best in the locality. Clean, professional, and very knowledgeable team. I came in with high expectations and they exceeded every single one of them. Will definitely be coming back!",
];
$demoBusiness = 'Sunshine Coffee House';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Try Demo - <?= htmlspecialchars($systemName) ?></title>
  <style>
    :root{--peacock:#005f8f;--peacock-dark:#004367;--deepyellow:#f4b400;--gold:#d4af37;--ink:#0f172a;--muted:#475569}
    *{box-sizing:border-box}
    html,body{margin:0;padding:0}
    body{font-family:Segoe UI,Plus Jakarta Sans,Arial,sans-serif;background:#f7f8fc;color:var(--ink);min-height:100vh;display:flex;flex-direction:column}
    .demo-banner{background:linear-gradient(90deg,#fef3c7,#fef9c3);color:#92400e;padding:10px 20px;text-align:center;font-weight:700;font-size:.9rem;border-bottom:1px solid #fde047}
    .demo-banner a{color:#1e293b;font-weight:800;text-decoration:underline}
    .container{max-width:520px;margin:auto;padding:20px;width:100%}
    .biz-card{background:#fff;border-radius:18px;border-top:5px solid var(--deepyellow);box-shadow:0 12px 30px rgba(0,95,143,.12);padding:24px;text-align:center}
    .biz-card h1{margin:0 0 4px;color:var(--peacock);font-size:1.4rem}
    .biz-card .sub{color:var(--muted);font-size:.92rem;margin-bottom:20px}
    .stars{display:flex;justify-content:center;gap:10px;margin:20px 0}
    .star{font-size:2.4rem;cursor:pointer;color:#cbd5e1;transition:transform .15s,color .15s;user-select:none}
    .star:hover,.star.active{color:var(--deepyellow);transform:scale(1.15)}
    .stage{margin-top:18px}
    .review-box{text-align:left;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:16px;margin-top:14px;font-size:.95rem;line-height:1.55;color:var(--ink)}
    .actions{display:flex;gap:10px;margin-top:14px;flex-wrap:wrap}
    .btn{flex:1;min-width:160px;padding:12px 16px;border-radius:10px;border:0;font-weight:700;cursor:pointer;font-family:inherit;font-size:.95rem;text-decoration:none;text-align:center;display:inline-flex;align-items:center;justify-content:center;gap:6px}
    .btn-primary{background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b}
    .btn-ghost{background:#f1f5f9;color:#0f172a}
    .btn-dark{background:var(--peacock);color:#fff}
    .feedback-form textarea{width:100%;min-height:100px;padding:12px;border:1px solid #cbd5e1;border-radius:10px;font-family:inherit;font-size:.95rem}
    .ok-badge{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:10px;display:inline-block;font-weight:700;margin-top:10px}
    footer{margin-top:auto;padding:16px;text-align:center;background:#fff;border-top:1px solid #e2e8f0;font-size:.88rem;color:var(--muted)}
    footer a{color:var(--peacock);font-weight:700;text-decoration:none}
    .info-tag{display:inline-block;background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:999px;font-size:.74rem;font-weight:700;margin-bottom:8px;letter-spacing:.4px}
  </style>
</head>
<body>

<div class="demo-banner">
  ★ DEMO MODE — This is a sample experience. <a href="<?= APP_URL ?>/register.php">Get yours →</a>
</div>

<div class="container">
  <div class="biz-card">
    <span class="info-tag">SAMPLE BUSINESS</span>
    <h1><?= htmlspecialchars($demoBusiness) ?></h1>
    <div class="sub">Hi! How was your experience with us today?</div>

    <div class="stars" id="stars">
      <span class="star" data-r="1">★</span>
      <span class="star" data-r="2">★</span>
      <span class="star" data-r="3">★</span>
      <span class="star" data-r="4">★</span>
      <span class="star" data-r="5">★</span>
    </div>

    <div class="stage" id="stage">
      <p style="color:var(--muted);margin:0">Tap the stars above to see how the customer journey works.</p>
    </div>
  </div>
</div>

<footer>
  <div>This was a simulated demo. <a href="<?= APP_URL ?>/register.php">Register your business</a> to get your own QR code.</div>
  <?php if ($helplineNumber !== ''): ?>
    <div style="margin-top:6px">Questions? Call <a href="tel:<?= htmlspecialchars($helplineTel) ?>"><?= htmlspecialchars($helplineNumber) ?></a></div>
  <?php endif; ?>
</footer>

<script>
const reviews = <?= json_encode($demoReviews) ?>;
const stars = document.querySelectorAll('.star');
const stage = document.getElementById('stage');

stars.forEach(function(s){
  s.addEventListener('click', function(){
    const rating = parseInt(s.getAttribute('data-r'), 10);
    stars.forEach(function(el){
      const r = parseInt(el.getAttribute('data-r'), 10);
      el.classList.toggle('active', r <= rating);
    });
    renderStage(rating);
  });
});

function renderStage(rating) {
  if (rating >= 4) {
    const review = reviews[Math.floor(Math.random() * reviews.length)];
    stage.innerHTML = `
      <div style="font-weight:700;color:#16a34a;font-size:1.05rem;text-align:left">★★★★★ Thank you! Here's a ready review you can post:</div>
      <div class="review-box" id="reviewText">${escapeHtml(review)}</div>
      <div class="actions">
        <button type="button" class="btn btn-ghost" id="copyBtn">📋 Copy Review</button>
        <button type="button" class="btn btn-primary" id="postBtn">📤 Post on Google →</button>
      </div>
      <div style="margin-top:12px;font-size:.85rem;color:#64748b;text-align:left">
        💡 In the real system, the AI generates this from your business category &amp; tone, then opens the customer's Google review form pre-filled.
      </div>
    `;
    document.getElementById('copyBtn').addEventListener('click', function(){
      navigator.clipboard.writeText(review).then(function(){
        document.getElementById('copyBtn').textContent = '✅ Copied!';
      });
    });
    document.getElementById('postBtn').addEventListener('click', function(){
      stage.innerHTML = `
        <div class="ok-badge">✅ In production, the customer is redirected to Google to post this review on your business profile.</div>
        <div style="margin-top:18px">
          <a href="<?= APP_URL ?>/register.php" class="btn btn-primary">Get this for my business →</a>
        </div>
      `;
    });
  } else {
    stage.innerHTML = `
      <div style="font-weight:700;color:#b91c1c;font-size:1.02rem;text-align:left">We're sorry your experience wasn't perfect.</div>
      <div style="color:#475569;text-align:left;margin-top:6px">Please share what went wrong — this stays private with the business owner and never reaches Google.</div>
      <div class="feedback-form" style="margin-top:14px">
        <textarea placeholder="Tell us what we could improve…"></textarea>
        <div class="actions">
          <button type="button" class="btn btn-dark" id="sendFb">Send Private Feedback</button>
        </div>
      </div>
      <div style="margin-top:12px;font-size:.85rem;color:#64748b;text-align:left">
        💡 This is the smart-gating system. 1-3 star feedback stays internal so your public Google rating only goes up.
      </div>
    `;
    document.getElementById('sendFb').addEventListener('click', function(){
      stage.innerHTML = `
        <div class="ok-badge">🙏 Thank you! In the real system, this private feedback is delivered to the business owner over email/dashboard.</div>
        <div style="margin-top:18px">
          <a href="<?= APP_URL ?>/register.php" class="btn btn-primary">Get this for my business →</a>
        </div>
      `;
    });
  }
}

function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, function(c){
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
  });
}
</script>

</body>
</html>
