<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/settings_helper.php';

$pdo = getPDO();

// ---------------------------------------------------------------------------
// CMS values (with safe fallbacks).
// ---------------------------------------------------------------------------
$systemName     = getSystemSetting($pdo, 'system_name', defined('APP_NAME') ? APP_NAME : 'Krishna Review System');
$supportMobile  = getSystemSetting($pdo, 'support_mobile', '');
$helplineNumber = getSystemSetting($pdo, 'helpline_number', $supportMobile);
$globalLogoPath = getSystemSetting($pdo, 'global_logo_path', '');

$heroHeadline    = getSystemSetting($pdo, 'landing_hero_headline', 'Get More 5-Star Google Reviews — Automatically.');
$heroSubheadline = getSystemSetting($pdo, 'landing_hero_subheadline', 'AI-powered review collection for local businesses. Customers scan a QR code, share their experience, and your Google profile shines.');
$heroVideoUrl    = trim(getSystemSetting($pdo, 'landing_hero_video_url', ''));
$counterOffset   = (int)getSystemSetting($pdo, 'landing_live_counter_offset', '0');
$demoToken       = trim(getSystemSetting($pdo, 'landing_demo_qr_token', ''));
$showPricing     = (int)getSystemSetting($pdo, 'landing_show_pricing', '1') === 1;
$testimonialsRaw = getSystemSetting($pdo, 'landing_testimonials', '[]');
$testimonials    = json_decode($testimonialsRaw, true);
if (!is_array($testimonials)) { $testimonials = []; }

$howItWorksRaw = getSystemSetting($pdo, 'homepage_how_it_works', '[]');
$howItWorks = json_decode($howItWorksRaw, true);
if (!is_array($howItWorks) || empty($howItWorks)) {
    $howItWorks = [
        'Customer scans your QR code',
        'They tap a star rating (1-5)',
        '5-stars go to Google, low ratings stay private',
    ];
}

// ---------------------------------------------------------------------------
// Live counters from real data.
// ---------------------------------------------------------------------------
$todayCount = 0;
$totalCount = 0;
try {
    $todayCount = (int)$pdo->query("
        SELECT COUNT(*) c FROM review_sessions
        WHERE customer_rating IN (4,5)
          AND flow_type = 'google_redirect'
          AND DATE(created_at) = CURDATE()
    ")->fetch()['c'];
    $totalCount = (int)$pdo->query("
        SELECT COUNT(*) c FROM review_sessions
        WHERE customer_rating IN (4,5) AND flow_type = 'google_redirect'
    ")->fetch()['c'];
} catch (Throwable) {
    // Schema may not be ready in fresh installs; fallback to zero.
}
$totalCount += max(0, $counterOffset);
$activeBusinesses = 0;
try {
    $activeBusinesses = (int)$pdo->query("SELECT COUNT(*) c FROM clients WHERE is_active = 1")->fetch()['c'];
} catch (Throwable) {
    $activeBusinesses = 0;
}

// ---------------------------------------------------------------------------
// Pricing plans (active only) — used for landing pricing section.
// ---------------------------------------------------------------------------
$plans = [];
try {
    $plans = $pdo->query("
        SELECT id, name, description, price_inr, credits, bonus_credits, is_popular
        FROM payment_plans
        WHERE is_active = 1
        ORDER BY sort_order ASC, price_inr ASC
    ")->fetchAll();
} catch (Throwable) {
    $plans = [];
}

// ---------------------------------------------------------------------------
// Determine demo target — real QR if admin pasted one, else a simulated page.
// ---------------------------------------------------------------------------
$demoUrl = APP_URL . '/demo.php';
if ($demoToken !== '') {
    $demoUrl = APP_URL . '/review.php?t=' . rawurlencode($demoToken);
}

// ---------------------------------------------------------------------------
// Helper: convert a YouTube URL into a clean embed URL.
// ---------------------------------------------------------------------------
function landing_embed_url(string $url): string
{
    if ($url === '') { return ''; }
    if (preg_match('#youtu\.be/([A-Za-z0-9_-]+)#', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (preg_match('#youtube\.com/watch\?v=([A-Za-z0-9_-]+)#', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    return $url;
}
$videoEmbed = landing_embed_url($heroVideoUrl);
$videoIsMp4 = (bool)preg_match('#\.mp4($|\?)#i', $heroVideoUrl);

$helplineTel = preg_replace('/[^0-9+]/', '', $helplineNumber) ?? '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title><?= htmlspecialchars($systemName) ?> — AI-Powered Google Review System for Local Businesses</title>
  <meta name="description" content="<?= htmlspecialchars($heroSubheadline) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{
      --peacock:#005f8f; --peacock-dark:#004367;
      --deepyellow:#f4b400; --gold:#d4af37;
      --ink:#0f172a; --muted:#475569; --bg:#f7f8fc;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;scroll-behavior:smooth}
    body{font-family:'Plus Jakarta Sans',Segoe UI,Arial,sans-serif;color:var(--ink);background:#fff;line-height:1.55}
    img{max-width:100%;display:block}
    a{color:var(--peacock)}
    .container{max-width:1180px;margin:0 auto;padding:0 20px}

    /* ---------- Header ---------- */
    .site-header{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.92);backdrop-filter:saturate(180%) blur(14px);border-bottom:1px solid #e2e8f0}
    .site-header .row{display:flex;align-items:center;justify-content:space-between;padding:14px 0;gap:14px}
    .brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--peacock);font-weight:800;font-size:1.05rem}
    .brand .b-logo{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--deepyellow),var(--gold));display:grid;place-items:center;color:#1e293b;font-weight:800}
    .brand img{max-height:32px}
    .nav-links{display:flex;gap:18px;align-items:center}
    .nav-links a{color:var(--ink);text-decoration:none;font-weight:600;font-size:.95rem}
    .nav-links a:hover{color:var(--peacock)}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:12px;text-decoration:none;font-weight:700;border:0;cursor:pointer;font-size:.95rem;font-family:inherit;line-height:1}
    .btn-primary{background:linear-gradient(90deg,var(--deepyellow),var(--gold));color:#1e293b;box-shadow:0 8px 22px rgba(244,180,0,.3)}
    .btn-primary:hover{transform:translateY(-1px)}
    .btn-ghost{background:transparent;color:var(--peacock);border:2px solid var(--peacock)}
    .btn-dark{background:var(--peacock);color:#fff}
    .btn-lg{padding:14px 24px;font-size:1.02rem}
    @media (max-width:760px){.nav-links a:not(.btn){display:none}}

    /* ---------- Hero ---------- */
    .hero{padding:60px 0 40px;background:radial-gradient(1100px 500px at 20% -10%, rgba(0,95,143,.12), transparent 60%), radial-gradient(900px 400px at 100% 10%, rgba(244,180,0,.18), transparent 60%)}
    .hero-grid{display:grid;gap:40px;grid-template-columns:1fr;align-items:center}
    @media(min-width:900px){.hero-grid{grid-template-columns:1.05fr .95fr}}
    .eyebrow{display:inline-block;background:#fff;border:1px solid #e2e8f0;padding:6px 12px;border-radius:999px;font-size:.78rem;font-weight:700;color:var(--peacock);letter-spacing:.6px}
    .hero h1{font-size:clamp(1.95rem,3.6vw,3.1rem);line-height:1.1;margin:14px 0 14px;color:var(--ink);font-weight:800}
    .hero h1 .accent{background:linear-gradient(90deg,var(--peacock),#0284c7);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
    .hero p.lead{font-size:1.08rem;color:var(--muted);margin:0 0 22px;max-width:560px}
    .hero-cta{display:flex;gap:10px;flex-wrap:wrap}
    .hero-trust{display:flex;gap:18px;align-items:center;margin-top:24px;flex-wrap:wrap;color:#475569;font-size:.88rem}
    .hero-trust strong{color:var(--ink)}
    .hero-media{position:relative;border-radius:24px;overflow:hidden;box-shadow:0 30px 60px -20px rgba(0,95,143,.25);background:#0f172a;aspect-ratio:16/9}
    .hero-media iframe,.hero-media video{position:absolute;inset:0;width:100%;height:100%;border:0}
    .hero-media .placeholder{position:absolute;inset:0;display:grid;place-items:center;color:#94a3b8;text-align:center;padding:24px}
    .hero-media .placeholder .ring{width:80px;height:80px;border-radius:50%;border:4px solid var(--deepyellow);display:grid;place-items:center;margin:0 auto 14px;font-size:1.6rem;color:var(--deepyellow)}

    /* ---------- Live counter strip ---------- */
    .counters{padding:10px 0 30px}
    .counter-grid{display:grid;gap:14px;grid-template-columns:1fr}
    @media(min-width:760px){.counter-grid{grid-template-columns:repeat(3,1fr)}}
    .counter{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:22px;text-align:center;position:relative;overflow:hidden}
    .counter::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--deepyellow),var(--gold))}
    .counter .num{font-size:2.4rem;font-weight:800;color:var(--peacock);line-height:1}
    .counter .lbl{color:#64748b;margin-top:6px;font-weight:600;font-size:.92rem}
    .counter .live-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;margin-right:6px;animation:pulseDot 1.4s infinite}
    @keyframes pulseDot{0%,100%{opacity:1}50%{opacity:.35}}

    /* ---------- Sections ---------- */
    section{padding:60px 0}
    .section-eyebrow{text-align:center;color:var(--peacock);font-weight:800;font-size:.85rem;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px}
    .section-title{text-align:center;font-size:clamp(1.6rem,2.6vw,2.2rem);font-weight:800;margin:0 0 14px;color:var(--ink)}
    .section-sub{text-align:center;color:var(--muted);max-width:640px;margin:0 auto 36px}

    /* Features */
    .features-grid{display:grid;gap:18px;grid-template-columns:1fr}
    @media(min-width:760px){.features-grid{grid-template-columns:repeat(3,1fr)}}
    .feature{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px;transition:all .2s}
    .feature:hover{border-color:var(--deepyellow);transform:translateY(-3px);box-shadow:0 18px 40px -16px rgba(0,95,143,.18)}
    .feature .ico{width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--peacock),var(--peacock-dark));color:#fff;display:grid;place-items:center;font-size:1.3rem;font-weight:800;margin-bottom:14px}
    .feature h3{margin:0 0 8px;font-size:1.1rem;color:var(--ink)}
    .feature p{margin:0;color:var(--muted);font-size:.95rem}

    /* How it works */
    .how-bg{background:linear-gradient(180deg,#f8fafc,#fff)}
    .steps{display:grid;gap:18px;grid-template-columns:1fr;counter-reset:step}
    @media(min-width:760px){.steps{grid-template-columns:repeat(3,1fr)}}
    .step{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px;position:relative}
    .step::before{counter-increment:step;content:counter(step);position:absolute;top:-18px;left:24px;width:36px;height:36px;border-radius:50%;background:var(--deepyellow);color:#1e293b;display:grid;place-items:center;font-weight:800;font-size:1.05rem;box-shadow:0 6px 14px rgba(244,180,0,.4)}
    .step h3{margin:14px 0 6px;font-size:1.05rem;color:var(--ink)}
    .step p{margin:0;color:var(--muted);font-size:.93rem}

    /* Pricing */
    .pricing-grid{display:grid;gap:18px;grid-template-columns:1fr}
    @media(min-width:760px){.pricing-grid{grid-template-columns:repeat(3,1fr)}}
    .price-card{position:relative;background:#fff;border:2px solid #e2e8f0;border-radius:20px;padding:28px;text-align:center;transition:all .2s}
    .price-card:hover{transform:translateY(-3px);box-shadow:0 20px 40px -18px rgba(0,95,143,.2)}
    .price-card.popular{border-color:var(--deepyellow);box-shadow:0 18px 36px -14px rgba(244,180,0,.3)}
    .price-card .badge{position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:var(--deepyellow);color:#1e293b;padding:5px 16px;border-radius:999px;font-weight:800;font-size:.74rem;letter-spacing:.5px}
    .price-card h3{margin:6px 0 4px;font-size:1.15rem;color:var(--peacock)}
    .price-card .desc{color:#64748b;font-size:.85rem;min-height:34px}
    .price-card .price{font-size:2.6rem;font-weight:800;color:var(--ink);line-height:1;margin:14px 0 4px}
    .price-card .price small{font-size:.85rem;color:#64748b;font-weight:600}
    .price-card ul{list-style:none;padding:0;margin:18px 0;text-align:left}
    .price-card ul li{padding:6px 0;color:var(--ink);font-size:.93rem;display:flex;align-items:start;gap:8px}
    .price-card ul li::before{content:"✔";color:#16a34a;font-weight:800}

    /* Testimonials */
    .testimonials-grid{display:grid;gap:18px;grid-template-columns:1fr}
    @media(min-width:760px){.testimonials-grid{grid-template-columns:repeat(3,1fr)}}
    .quote-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px;position:relative}
    .quote-card .stars{color:var(--deepyellow);font-size:1.05rem;letter-spacing:2px;margin-bottom:10px}
    .quote-card p{margin:0 0 16px;color:var(--ink);font-size:.97rem;line-height:1.6}
    .quote-card .who{display:flex;align-items:center;gap:12px;border-top:1px solid #f1f5f9;padding-top:14px}
    .quote-card .avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--peacock),var(--peacock-dark));color:#fff;display:grid;place-items:center;font-weight:800}
    .quote-card .name{font-weight:700;color:var(--ink);font-size:.92rem;line-height:1.2}
    .quote-card .biz{color:#64748b;font-size:.82rem}

    /* Final CTA */
    .cta-band{background:linear-gradient(135deg,var(--peacock),var(--peacock-dark));color:#fff;border-radius:24px;padding:44px 28px;text-align:center;position:relative;overflow:hidden}
    .cta-band::before{content:"";position:absolute;inset:0;background:radial-gradient(600px 200px at 0% 0%, rgba(244,180,0,.25), transparent 60%)}
    .cta-band > *{position:relative;z-index:1}
    .cta-band h2{margin:0 0 10px;font-size:clamp(1.5rem,2.4vw,2.1rem);font-weight:800}
    .cta-band p{margin:0 0 22px;color:#cbd5e1;max-width:520px;margin-left:auto;margin-right:auto}

    /* Footer */
    footer{background:#0f172a;color:#cbd5e1;padding:40px 0 20px;margin-top:50px}
    footer .row{display:grid;gap:24px;grid-template-columns:1fr;margin-bottom:24px}
    @media(min-width:760px){footer .row{grid-template-columns:2fr 1fr 1fr}}
    footer h4{color:#fff;margin:0 0 10px;font-size:1rem}
    footer a{color:#cbd5e1;text-decoration:none;display:block;padding:4px 0;font-size:.92rem}
    footer a:hover{color:var(--deepyellow)}
    .copy{border-top:1px solid #1e293b;padding-top:18px;text-align:center;color:#64748b;font-size:.85rem}
  </style>
</head>
<body>

<!-- ============================================================ HEADER -->
<header class="site-header">
  <div class="container row">
    <a class="brand" href="<?= APP_URL ?>/">
      <?php if ($globalLogoPath !== ''): ?>
        <img src="<?= APP_URL . '/' . htmlspecialchars($globalLogoPath) ?>" alt="<?= htmlspecialchars($systemName) ?>">
      <?php else: ?>
        <span class="b-logo">K</span>
      <?php endif; ?>
      <span><?= htmlspecialchars($systemName) ?></span>
    </a>
    <nav class="nav-links">
      <a href="#features">Features</a>
      <a href="#how">How It Works</a>
      <?php if ($showPricing && !empty($plans)): ?><a href="#pricing">Pricing</a><?php endif; ?>
      <a href="<?= APP_URL ?>/login.php" class="btn btn-ghost">Login</a>
      <a href="<?= APP_URL ?>/register.php" class="btn btn-primary">Register Business</a>
    </nav>
  </div>
</header>

<!-- ============================================================ HERO -->
<section class="hero">
  <div class="container hero-grid">
    <div>
      <span class="eyebrow">★ TRUSTED BY <?= max(1, $activeBusinesses) ?>+ LOCAL BUSINESSES</span>
      <h1><?= htmlspecialchars($heroHeadline) ?></h1>
      <p class="lead"><?= htmlspecialchars($heroSubheadline) ?></p>
      <div class="hero-cta">
        <a href="<?= APP_URL ?>/register.php" class="btn btn-primary btn-lg">Get Started Free →</a>
        <a href="<?= htmlspecialchars($demoUrl) ?>" class="btn btn-ghost btn-lg" target="_blank" rel="noopener">▶ Try Demo</a>
      </div>
      <div class="hero-trust">
        <span>✅ <strong>Sign-up bonus included</strong></span>
        <span>✅ <strong>No setup fee</strong></span>
        <span>✅ <strong>Cancel anytime</strong></span>
      </div>
    </div>
    <div class="hero-media">
      <?php if ($videoEmbed !== '' && !$videoIsMp4): ?>
        <iframe src="<?= htmlspecialchars($videoEmbed) ?>?rel=0&modestbranding=1" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
      <?php elseif ($heroVideoUrl !== '' && $videoIsMp4): ?>
        <video src="<?= htmlspecialchars($heroVideoUrl) ?>" autoplay muted loop playsinline></video>
      <?php else: ?>
        <div class="placeholder">
          <div>
            <div class="ring">★</div>
            <div style="font-weight:700;color:#f1f5f9">See it in action</div>
            <div style="font-size:.85rem;margin-top:6px">Add a hero video URL in Admin → Global Settings → Public Landing Page</div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ============================================================ LIVE COUNTERS -->
<section class="counters">
  <div class="container counter-grid">
    <div class="counter">
      <div class="num"><span class="live-dot"></span><span id="todayCount" data-target="<?= (int)$todayCount ?>">0</span>+</div>
      <div class="lbl">5-Star Reviews Generated Today</div>
    </div>
    <div class="counter">
      <div class="num"><span id="totalCount" data-target="<?= (int)$totalCount ?>">0</span>+</div>
      <div class="lbl">Total Google Reviews Driven</div>
    </div>
    <div class="counter">
      <div class="num"><span id="bizCount" data-target="<?= (int)$activeBusinesses ?>">0</span>+</div>
      <div class="lbl">Active Local Businesses</div>
    </div>
  </div>
</section>

<!-- ============================================================ FEATURES -->
<section id="features">
  <div class="container">
    <div class="section-eyebrow">Why Choose Us</div>
    <h2 class="section-title">Built specifically for local businesses</h2>
    <p class="section-sub">From a chai stall to a luxury hotel — our review engine handles the full journey: customer → QR → smart gating → Google profile.</p>
    <div class="features-grid">
      <div class="feature">
        <div class="ico">QR</div>
        <h3>Custom QR & Standee</h3>
        <p>Pick from 50+ Canva-style designs and download a print-ready standee in seconds. Each business gets a dynamic, unique QR.</p>
      </div>
      <div class="feature">
        <div class="ico">AI</div>
        <h3>AI Review Generator</h3>
        <p>Pre-built buffer of category-aware, human-sounding reviews. Customers tap and post — no thinking required.</p>
      </div>
      <div class="feature">
        <div class="ico">★</div>
        <h3>Smart Star Gating</h3>
        <p>5-stars go straight to Google. 1-3 stars stay private as internal feedback. Your public rating only goes up.</p>
      </div>
      <div class="feature">
        <div class="ico">₹</div>
        <h3>Wallet-Based Pricing</h3>
        <p>Pay only for what you use. Recharge via UPI/Card. Sign-up bonus credits get you started immediately.</p>
      </div>
      <div class="feature">
        <div class="ico">📊</div>
        <h3>Real-Time Analytics</h3>
        <p>Track today's reviews, week-over-week growth, wallet activity, and buffer health from a single dashboard.</p>
      </div>
      <div class="feature">
        <div class="ico">💬</div>
        <h3>Daily WhatsApp Reports</h3>
        <p>10 PM IST report straight to your WhatsApp: how many reviews you got today vs yesterday + a daily nudge.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ HOW IT WORKS -->
<section id="how" class="how-bg">
  <div class="container">
    <div class="section-eyebrow">In 3 simple steps</div>
    <h2 class="section-title">How it works</h2>
    <p class="section-sub">No tech skills needed. Print one QR standee and watch reviews start flowing.</p>
    <div class="steps">
      <?php foreach ($howItWorks as $step): ?>
        <div class="step">
          <h3><?= htmlspecialchars((string)$step) ?></h3>
          <p style="color:#64748b;font-size:.9rem;margin-top:8px">&nbsp;</p>
        </div>
      <?php endforeach; ?>
    </div>
    <div style="text-align:center;margin-top:30px">
      <a href="<?= htmlspecialchars($demoUrl) ?>" class="btn btn-dark btn-lg" target="_blank" rel="noopener">▶ Try the Customer Experience</a>
    </div>
  </div>
</section>

<!-- ============================================================ PRICING -->
<?php if ($showPricing && !empty($plans)): ?>
<section id="pricing">
  <div class="container">
    <div class="section-eyebrow">Simple, transparent pricing</div>
    <h2 class="section-title">Pay only for what you use</h2>
    <p class="section-sub">One recharge = wallet credits + validity days (like mobile recharge). No hidden fees.</p>
    <div class="pricing-grid">
      <?php foreach ($plans as $plan): ?>
        <?php $tot = (int)$plan['credits'] + (int)$plan['bonus_credits']; ?>
        <div class="price-card <?= (int)$plan['is_popular'] === 1 ? 'popular' : '' ?>">
          <?php if ((int)$plan['is_popular'] === 1): ?><span class="badge">MOST POPULAR</span><?php endif; ?>
          <h3><?= htmlspecialchars((string)$plan['name']) ?></h3>
          <div class="desc"><?= htmlspecialchars((string)($plan['description'] ?? '')) ?></div>
          <div class="price">&#8377;<?= (int)$plan['price_inr'] ?><br><small>one-time</small></div>
          <ul>
            <li><strong><?= (int)$plan['credits'] ?></strong> credits</li>
            <?php if ((int)$plan['bonus_credits'] > 0): ?>
              <li><strong>+ <?= (int)$plan['bonus_credits'] ?></strong> bonus credits</li>
            <?php endif; ?>
            <li>Total <strong><?= $tot ?></strong> credits</li>
            <li>Instant credit on payment</li>
            <li>WhatsApp invoice</li>
          </ul>
          <a href="<?= APP_URL ?>/register.php" class="btn btn-primary" style="width:100%">Get <?= htmlspecialchars((string)$plan['name']) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============================================================ TESTIMONIALS -->
<?php if (!empty($testimonials)): ?>
<section style="background:linear-gradient(180deg,#fff,#f8fafc)">
  <div class="container">
    <div class="section-eyebrow">Real businesses, real results</div>
    <h2 class="section-title">Loved by local owners</h2>
    <div class="testimonials-grid">
      <?php foreach ($testimonials as $t):
        $name   = (string)($t['name'] ?? '');
        $biz    = (string)($t['business'] ?? '');
        $quote  = (string)($t['quote'] ?? '');
        $rating = max(1, min(5, (int)($t['rating'] ?? 5)));
        $initial = strtoupper(substr(trim($name) !== '' ? $name : ($biz !== '' ? $biz : 'C'), 0, 1));
      ?>
        <div class="quote-card">
          <div class="stars"><?= str_repeat('★', $rating) ?><?= str_repeat('☆', 5 - $rating) ?></div>
          <p>"<?= htmlspecialchars($quote) ?>"</p>
          <div class="who">
            <div class="avatar"><?= htmlspecialchars($initial) ?></div>
            <div>
              <div class="name"><?= htmlspecialchars($name) ?></div>
              <?php if ($biz !== ''): ?><div class="biz"><?= htmlspecialchars($biz) ?></div><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ============================================================ FINAL CTA -->
<section>
  <div class="container">
    <div class="cta-band">
      <h2>Ready to get more 5-star Google reviews?</h2>
      <p>Register your business, claim your sign-up bonus, and download your QR standee in under 2 minutes.</p>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <a href="<?= APP_URL ?>/register.php" class="btn btn-primary btn-lg">Start Free →</a>
        <?php if ($helplineNumber !== ''): ?>
          <a href="tel:<?= htmlspecialchars($helplineTel) ?>" class="btn btn-ghost btn-lg" style="background:rgba(255,255,255,.08);color:#fff;border-color:rgba(255,255,255,.3)">📞 Call <?= htmlspecialchars($helplineNumber) ?></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ FOOTER -->
<footer>
  <div class="container">
    <div class="row">
      <div>
        <a class="brand" href="<?= APP_URL ?>/" style="color:#fff">
          <span class="b-logo">K</span><span><?= htmlspecialchars($systemName) ?></span>
        </a>
        <p style="margin:12px 0 0;color:#94a3b8;max-width:340px;font-size:.9rem">AI-powered Google review collection for local businesses across India.</p>
      </div>
      <div>
        <h4>Product</h4>
        <a href="#features">Features</a>
        <a href="#how">How It Works</a>
        <?php if ($showPricing && !empty($plans)): ?><a href="#pricing">Pricing</a><?php endif; ?>
        <a href="<?= htmlspecialchars($demoUrl) ?>" target="_blank" rel="noopener">Try Demo</a>
      </div>
      <div>
        <h4>Account</h4>
        <a href="<?= APP_URL ?>/login.php">Login</a>
        <a href="<?= APP_URL ?>/register.php">Register Business</a>
        <a href="<?= APP_URL ?>/forgot_password.php">Forgot Password</a>
        <?php if ($helplineNumber !== ''): ?>
          <a href="tel:<?= htmlspecialchars($helplineTel) ?>">📞 <?= htmlspecialchars($helplineNumber) ?></a>
        <?php endif; ?>
      </div>
    </div>
    <div class="copy">&copy; <?= date('Y') ?> <?= htmlspecialchars($systemName) ?>. All rights reserved.</div>
  </div>
</footer>

<script>
// Animated number counters
(function(){
  const els = document.querySelectorAll('[data-target]');
  function animate(el){
    const target = parseInt(el.getAttribute('data-target'), 10) || 0;
    if (target === 0) { el.textContent = '0'; return; }
    const duration = 1400;
    const startTime = performance.now();
    function frame(t){
      const elapsed = Math.min(1, (t - startTime) / duration);
      const eased = 1 - Math.pow(1 - elapsed, 3);
      el.textContent = Math.floor(eased * target).toLocaleString('en-IN');
      if (elapsed < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  }
  const observer = new IntersectionObserver(function(entries){
    entries.forEach(function(entry){
      if (entry.isIntersecting) {
        animate(entry.target);
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.4 });
  els.forEach(function(el){ observer.observe(el); });
})();
</script>
</body>
</html>
