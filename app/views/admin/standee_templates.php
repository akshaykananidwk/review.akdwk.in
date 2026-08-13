<?php
$pageTitle = 'Standee Templates';
$activeMenu = 'standee_tpl';
require __DIR__ . '/partials/layout_head.php';
?>
<style>
  .tpl-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}
  .tpl-card{border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;background:#fff;box-shadow:0 6px 18px rgba(0,95,143,.08);display:flex;flex-direction:column}
  .tpl-thumb{position:relative;width:100%;aspect-ratio:3/4;background:#f1f5f9;overflow:hidden}
  .tpl-thumb img{width:100%;height:100%;object-fit:cover;display:block}
  .tpl-thumb .qr-marker{position:absolute;border:2px dashed #f4b400;background:rgba(244,180,0,.18);box-shadow:0 0 0 2px rgba(15,23,42,.25) inset;pointer-events:none}
  .tpl-meta{padding:10px;font-size:.85rem;color:#475569;display:flex;flex-direction:column;gap:6px;flex:1}
  .tpl-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:auto;padding-top:8px}
  .pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.72rem;font-weight:700}
  .pill-on{background:#dcfce7;color:#166534}.pill-off{background:#fee2e2;color:#991b1b}
  .pill-warn{background:#fef3c7;color:#92400e}
  .pill-pos{background:#e0f2fe;color:#075985;font-weight:600}
  .upload-zone{border:2px dashed #cbd5e1;border-radius:12px;padding:16px;background:#f8fafc;margin-bottom:16px}
  .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:.9rem}
</style>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars((string)$flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert-error"><?= htmlspecialchars((string)$error) ?></div><?php endif; ?>

<div class="card">
  <h2 style="margin:0 0 8px;color:#005f8f">Upload Template Background</h2>
  <p style="color:#475569;margin:0 0 12px">
    Add Canva-style backgrounds (PNG, JPG, or WebP). After upload you'll be taken to the
    <strong>drag-and-drop editor</strong> to place the QR box exactly where you want it on this template.
  </p>
  <div class="upload-zone">
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload_template">
      <div style="display:grid;gap:10px;grid-template-columns:1fr;max-width:520px">
        <div><label>Optional title</label><input type="text" name="title" placeholder="e.g. Diwali Gold Frame"></div>
        <div><label>Image file *</label><input type="file" name="template_image" accept=".png,.jpg,.jpeg,.webp" required></div>
      </div>
      <button class="btn btn-primary" type="submit" style="margin-top:12px">Upload &amp; Position QR</button>
    </form>
  </div>
</div>

<div class="card">
  <h3 style="margin:0 0 12px;color:#005f8f">Gallery (<?= count($templates) ?>)</h3>
  <?php if (empty($templates)): ?>
    <p style="color:#64748b">No templates yet. Upload your first design above.</p>
  <?php else: ?>
    <div class="tpl-grid">
      <?php foreach ($templates as $t): ?>
        <?php
          $tplId   = (int)$t['id'];
          $imgPath = (string)$t['image_path'];
          $title   = $t['title'] !== null && $t['title'] !== '' ? (string)$t['title'] : '';
          $isActive = (int)($t['is_active'] ?? 1) === 1;
          $hasBox = isset($t['qr_width']) && (int)$t['qr_width'] > 0 && (int)$t['qr_height'] > 0;
          $nW = isset($t['native_width'])  ? (int)$t['native_width']  : 0;
          $nH = isset($t['native_height']) ? (int)$t['native_height'] : 0;
          $boxStyle = '';
          if ($hasBox && $nW > 0 && $nH > 0) {
              $boxStyle = sprintf(
                  'left:%.2f%%;top:%.2f%%;width:%.2f%%;height:%.2f%%;',
                  ((int)$t['qr_pos_x']  / $nW) * 100,
                  ((int)$t['qr_pos_y']  / $nH) * 100,
                  ((int)$t['qr_width']  / $nW) * 100,
                  ((int)$t['qr_height'] / $nH) * 100
              );
          }
          $editUrl = APP_URL . '/admin_standee_templates.php?edit=' . $tplId;
        ?>
        <div class="tpl-card">
          <div class="tpl-thumb">
            <img src="<?= APP_URL ?>/<?= htmlspecialchars($imgPath) ?>" alt="">
            <?php if ($boxStyle !== ''): ?>
              <div class="qr-marker" style="<?= $boxStyle ?>"></div>
            <?php endif; ?>
          </div>
          <div class="tpl-meta">
            <div>
              <strong>#<?= $tplId ?></strong>
              <?= $title !== '' ? htmlspecialchars($title) : '<em>Untitled</em>' ?>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
              <span class="pill <?= $isActive ? 'pill-on' : 'pill-off' ?>"><?= $isActive ? 'Active' : 'Hidden' ?></span>
              <?php if ($hasBox): ?>
                <span class="pill pill-pos">QR <?= (int)$t['qr_width'] ?>×<?= (int)$t['qr_height'] ?></span>
              <?php else: ?>
                <span class="pill pill-warn">QR not set</span>
              <?php endif; ?>
            </div>
            <div class="tpl-actions">
              <a class="btn btn-primary" href="<?= htmlspecialchars($editUrl) ?>" style="padding:6px 10px;font-size:.85rem">Edit Position</a>
              <form method="post" style="display:inline">
      <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_template">
                <input type="hidden" name="template_id" value="<?= $tplId ?>">
                <button type="submit" class="btn btn-toggle" style="padding:6px 10px;font-size:.85rem"><?= $isActive ? 'Hide' : 'Show' ?></button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove this template permanently?');">
      <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_template">
                <input type="hidden" name="template_id" value="<?= $tplId ?>">
                <button type="submit" class="btn btn-delete" style="padding:6px 10px;font-size:.85rem">Delete</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
