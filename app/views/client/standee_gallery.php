<?php
$pageTitle = 'Standee Gallery';
$activeMenu = 'standee';
$clientForLayout = $client;
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .lead{color:#475569;margin:0 0 18px;line-height:1.55}
  .gallery{display:grid;gap:18px;grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}
  .design-card{
    position:relative;border-radius:14px;overflow:hidden;border:3px solid transparent;background:#fff;
    box-shadow:0 10px 28px rgba(0,95,143,.12);cursor:pointer;transition:border-color .15s,transform .12s;
  }
  .design-card:hover{transform:translateY(-2px)}
  .design-card.selected{border-color:var(--deepyellow);box-shadow:0 12px 32px rgba(244,180,0,.35)}
  .design-card img{width:100%;aspect-ratio:3/4;object-fit:cover;display:block;background:#e2e8f0}
  .design-card .cap{padding:10px 12px;font-size:.88rem;color:#334155}
  .design-card input{position:absolute;opacity:0;pointer-events:none}
  .sticky-actions{
    position:sticky;bottom:0;margin:18px -18px -18px;padding:14px 18px;background:linear-gradient(180deg,rgba(247,248,252,.92),#eef2ff);
    border-top:1px solid #e2e8f0;display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between
  }
  .empty-state{text-align:center;padding:40px 20px;color:#64748b}
</style>

<?php if ($flash !== ''): ?><div class="msg"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <h2 style="margin:0;color:#005f8f">Choose Your Standee Design</h2>
  <p class="lead">Pick a template background uploaded by your administrator. We’ll merge your business name, QR code, and branding footer into a print-ready PNG.</p>

  <?php if (empty($templates)): ?>
    <div class="empty-state">
      <p><strong>No templates available yet.</strong></p>
      <p>Your administrator can upload designs under <em>Admin → Standee Templates</em>.</p>
      <p style="margin-top:14px"><a class="btn btn-ghost" href="<?= APP_URL ?>/dashboard.php">&larr; Back to Dashboard</a></p>
    </div>
  <?php else: ?>
    <form method="post" action="<?= APP_URL ?>/standee_generate.php" id="standeeForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="gallery" id="gallery">
        <?php foreach ($templates as $idx => $t): ?>
          <label class="design-card<?= $idx === 0 ? ' selected' : '' ?>" data-id="<?= (int)$t['id'] ?>">
            <input type="radio" name="template_id" value="<?= (int)$t['id'] ?>" <?= $idx === 0 ? 'checked' : '' ?>>
            <img src="<?= APP_URL ?>/<?= htmlspecialchars((string)$t['image_path']) ?>" alt="">
            <div class="cap">
              <?= $t['title'] !== null && $t['title'] !== '' ? htmlspecialchars((string)$t['title']) : 'Template #' . (int)$t['id'] ?>
            </div>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="sticky-actions">
        <span style="color:#475569;font-size:.9rem">Selected design applies to your next download only.</span>
        <button type="submit" class="btn">Generate My Standee</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
(function(){
  var g = document.getElementById('gallery');
  if (!g) return;
  g.addEventListener('click', function(e){
    var card = e.target.closest('.design-card');
    if (!card) return;
    var id = card.getAttribute('data-id');
    g.querySelectorAll('.design-card').forEach(function(c){ c.classList.toggle('selected', c.getAttribute('data-id') === id); });
    var inp = card.querySelector('input[type="radio"]');
    if (inp) { inp.checked = true; }
  });
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
