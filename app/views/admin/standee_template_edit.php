<?php
$pageTitle = 'Position QR & name — Template #' . (int)$template['id'];
$activeMenu = 'standee_tpl';
require __DIR__ . '/partials/layout_head.php';

$tplId = (int)$template['id'];
$imgPath = (string)$template['image_path'];
$title = (string)($template['title'] ?? '');
$nW = (int)$template['native_width'];
$nH = (int)$template['native_height'];
$qx = (int)$template['qr_pos_x'];
$qy = (int)$template['qr_pos_y'];
$qw = (int)$template['qr_width'];
$qh = (int)$template['qr_height'];

$hasBn = array_key_exists('business_name_enabled', $template);
$bnEn = (int)($template['business_name_enabled'] ?? 0);
$bnx = (int)($template['business_name_pos_x'] ?? 40);
$bny = (int)($template['business_name_pos_y'] ?? 40);
$bnw = (int)($template['business_name_box_w'] ?? 0);
$bnh = (int)($template['business_name_box_h'] ?? 0);
if ($hasBn) {
    if ($bnw < 40) {
        $bnw = $nW > 0 ? max(200, $nW - 80) : 400;
    }
    if ($bnh < 30) {
        $bnh = $nH > 0 ? min(180, max(100, (int)round($nH * 0.14))) : 120;
    }
}
$bnFont = max(10, (int)($template['business_name_font_pt'] ?? 36));
$bnColor = (string)($template['business_name_color'] ?? '#0f172a');
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bnColor)) {
    $bnColor = '#0f172a';
}
?>
<style>
  .editor-wrap{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px;align-items:start}
  @media (max-width: 980px){ .editor-wrap{grid-template-columns:1fr} .editor-side{order:-1} }

  .stage-card{background:#0f172a;border-radius:14px;padding:14px;box-shadow:0 10px 30px rgba(15,23,42,.18);overflow:hidden}
  .stage{position:relative;display:inline-block;max-width:100%;line-height:0;user-select:none;-webkit-user-select:none;touch-action:none}
  .stage img{display:block;max-width:100%;height:auto;border-radius:8px;background:#fff}

  .qr-box{
    position:absolute;z-index:2;
    border:2px solid #f4b400;
    background:rgba(244,180,0,.18);
    box-shadow:0 0 0 2px rgba(15,23,42,.45) inset, 0 0 0 9999px rgba(15,23,42,0);
    cursor:move;
    box-sizing:border-box;
  }
  .qr-box.locked{cursor:not-allowed;opacity:.6}
  .qr-box .label{
    position:absolute;left:6px;top:6px;background:rgba(15,23,42,.85);color:#fff;
    font-size:11px;font-weight:700;padding:3px 7px;border-radius:6px;letter-spacing:.02em;
  }
  .qr-handle,.name-handle{
    position:absolute;width:18px;height:18px;border:2px solid #1e293b;border-radius:4px;
    bottom:-10px;right:-10px;cursor:nwse-resize;
  }
  .qr-handle{background:#f4b400}
  .name-handle{background:#22c55e}
  .qr-handle.tl,.name-handle.tl{top:-10px;left:-10px;bottom:auto;right:auto;cursor:nwse-resize}
  .qr-handle.tr,.name-handle.tr{top:-10px;right:-10px;bottom:auto;left:auto;cursor:nesw-resize}
  .qr-handle.bl,.name-handle.bl{bottom:-10px;left:-10px;top:auto;right:auto;cursor:nesw-resize}

  .name-box{
    position:absolute;z-index:3;
    border:2px solid #22c55e;
    background:rgba(34,197,94,.16);
    box-shadow:0 0 0 2px rgba(15,23,42,.4) inset;
    cursor:move;
    box-sizing:border-box;
  }
  .name-box.is-disabled{opacity:.35;pointer-events:none}
  .name-box .label{
    position:absolute;left:6px;top:6px;background:rgba(15,23,42,.88);color:#bbf7d0;
    font-size:11px;font-weight:700;padding:3px 7px;border-radius:6px;letter-spacing:.02em;
  }

  .editor-side .card{margin-bottom:14px}
  .editor-side label{display:block;font-weight:600;color:#334155;font-size:.85rem;margin-bottom:4px;margin-top:10px}
  .editor-side input[type=number],
  .editor-side input[type=text]{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:.9rem}
  .readout{display:grid;grid-template-columns:1fr 1fr;gap:8px;background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:10px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.82rem;color:#0f172a}
  .readout div{display:flex;justify-content:space-between;gap:8px}
  .readout strong{color:#005f8f}
  .help-list{margin:6px 0 0;padding-left:18px;color:#475569;font-size:.85rem;line-height:1.55}
  .alert-error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:.9rem}
</style>

<?php if (!empty($flash)): ?><div class="msg"><?= htmlspecialchars((string)$flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert-error"><?= htmlspecialchars((string)$error) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:10px;flex-wrap:wrap">
  <div>
    <h2 style="margin:0;color:#005f8f">Position QR &amp; business name — Template #<?= $tplId ?></h2>
    <div style="color:#64748b;font-size:.85rem">Native resolution: <strong><?= $nW ?> × <?= $nH ?> px</strong></div>
  </div>
  <a class="btn btn-toggle" href="<?= APP_URL ?>/admin_standee_templates.php" style="padding:8px 14px">← Back to Gallery</a>
</div>

<form method="post" id="boxForm">
  <input type="hidden" name="action" value="save_box">
  <input type="hidden" name="template_id" value="<?= $tplId ?>">
  <input type="hidden" name="qr_pos_x"  id="f_qx" value="<?= $qx ?>">
  <input type="hidden" name="qr_pos_y"  id="f_qy" value="<?= $qy ?>">
  <input type="hidden" name="qr_width"  id="f_qw" value="<?= $qw ?>">
  <input type="hidden" name="qr_height" id="f_qh" value="<?= $qh ?>">
  <?php if ($hasBn): ?>
  <input type="hidden" name="business_name_pos_x" id="f_nx" value="<?= $bnx ?>">
  <input type="hidden" name="business_name_pos_y" id="f_ny" value="<?= $bny ?>">
  <input type="hidden" name="business_name_box_w" id="f_nw" value="<?= $bnw ?>">
  <input type="hidden" name="business_name_box_h" id="f_nh" value="<?= $bnh ?>">
  <?php endif; ?>

  <div class="editor-wrap">
    <div class="stage-card">
      <div class="stage" id="stage">
        <img id="bg" src="<?= APP_URL ?>/<?= htmlspecialchars($imgPath) ?>" alt="" draggable="false">
        <div class="qr-box" id="qrBox">
          <span class="label" id="qrLabel">QR</span>
          <span class="qr-handle tl" data-handle="tl"></span>
          <span class="qr-handle tr" data-handle="tr"></span>
          <span class="qr-handle bl" data-handle="bl"></span>
          <span class="qr-handle"    data-handle="br"></span>
        </div>
        <?php if ($hasBn): ?>
        <div class="name-box<?= $bnEn ? '' : ' is-disabled' ?>" id="nameBox">
          <span class="label" id="nameLabel">BUSINESS NAME</span>
          <span class="name-handle tl" data-name-handle="tl"></span>
          <span class="name-handle tr" data-name-handle="tr"></span>
          <span class="name-handle bl" data-name-handle="bl"></span>
          <span class="name-handle"    data-name-handle="br"></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <aside class="editor-side">
      <div class="card">
        <h3 style="margin:0 0 10px;color:#005f8f">QR box</h3>
        <p style="color:#475569;margin:0 0 10px;font-size:.88rem">
          Drag the <strong>yellow</strong> box to move it. Drag any corner to resize. Values are stored at native resolution.
        </p>

        <div class="readout" aria-live="polite">
          <div><span>X</span><strong id="r_x"><?= $qx ?> px</strong></div>
          <div><span>Y</span><strong id="r_y"><?= $qy ?> px</strong></div>
          <div><span>W</span><strong id="r_w"><?= $qw ?> px</strong></div>
          <div><span>H</span><strong id="r_h"><?= $qh ?> px</strong></div>
        </div>

        <label><input type="checkbox" id="lockSquare" checked> Lock to square (recommended for QR)</label>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px">
          <div>
            <label>X (px)</label>
            <input type="number" id="i_x" min="0" value="<?= $qx ?>">
          </div>
          <div>
            <label>Y (px)</label>
            <input type="number" id="i_y" min="0" value="<?= $qy ?>">
          </div>
          <div>
            <label>Width (px)</label>
            <input type="number" id="i_w" min="20" value="<?= $qw ?>">
          </div>
          <div>
            <label>Height (px)</label>
            <input type="number" id="i_h" min="20" value="<?= $qh ?>">
          </div>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
          <button type="button" class="btn btn-toggle" id="btnCenter" style="padding:7px 12px">Center QR</button>
          <button type="button" class="btn btn-toggle" id="btnFit"    style="padding:7px 12px">QR fit 50%</button>
        </div>
      </div>

      <?php if ($hasBn): ?>
      <div class="card">
        <h3 style="margin:0 0 10px;color:#005f8f">Business name box</h3>
        <p style="color:#475569;margin:0 0 10px;font-size:.88rem">
          Drag the <strong>green</strong> box on the preview to place the text area. Resize corners to set max width/height for wrapping. Coordinates update automatically — no need to type numbers.
        </p>
        <label style="display:flex;gap:8px;align-items:center;font-weight:600;margin-bottom:10px">
          <input type="checkbox" name="business_name_enabled" value="1" id="bnEnabled" <?= $bnEn ? 'checked' : '' ?>>
          Enable business name on standee
        </label>

        <div class="readout" aria-live="polite" style="margin-bottom:8px">
          <div><span>Name X</span><strong id="rn_x"><?= $bnx ?> px</strong></div>
          <div><span>Name Y</span><strong id="rn_y"><?= $bny ?> px</strong></div>
          <div><span>Name W</span><strong id="rn_w"><?= $bnw ?> px</strong></div>
          <div><span>Name H</span><strong id="rn_h"><?= $bnh ?> px</strong></div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
          <button type="button" class="btn btn-toggle" id="btnNameTop" style="padding:7px 12px">Name: top strip</button>
          <button type="button" class="btn btn-toggle" id="btnNameCenter" style="padding:7px 12px">Name: center</button>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div><label>Font size (pt)</label><input type="number" name="business_name_font_pt" min="10" max="200" value="<?= $bnFont ?>"></div>
          <div><label>Text colour</label>
            <input type="color" id="bnColorPick" value="<?= htmlspecialchars($bnColor) ?>" style="width:100%;height:38px;padding:0;border:1px solid #cbd5e1;border-radius:8px">
            <input type="text" name="business_name_color" id="bnColorHex" maxlength="16" value="<?= htmlspecialchars($bnColor) ?>" style="width:100%;margin-top:6px">
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <h3 style="margin:0 0 10px;color:#005f8f">Title</h3>
        <input type="text" name="title" value="<?= htmlspecialchars($title) ?>" placeholder="e.g. Diwali Gold Frame">
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:14px;padding:12px">Save template</button>
      </div>

      <div class="card">
        <h3 style="margin:0 0 8px;color:#005f8f">How it works</h3>
        <ul class="help-list">
          <li><strong>Yellow</strong> — QR paste area (same as before).</li>
          <li><strong>Green</strong> — where each client’s business name is drawn (when enabled).</li>
          <li>All positions are stored in <strong>native pixels</strong> so they stay accurate when the preview scales.</li>
        </ul>
      </div>
    </aside>
  </div>
</form>

<script>
(function(){
  const stage  = document.getElementById('stage');
  const bg     = document.getElementById('bg');
  const box    = document.getElementById('qrBox');
  const label  = document.getElementById('qrLabel');
  const lock   = document.getElementById('lockSquare');

  const fields = {
    x: document.getElementById('f_qx'),
    y: document.getElementById('f_qy'),
    w: document.getElementById('f_qw'),
    h: document.getElementById('f_qh'),
  };
  const inputs = {
    x: document.getElementById('i_x'),
    y: document.getElementById('i_y'),
    w: document.getElementById('i_w'),
    h: document.getElementById('i_h'),
  };
  const readout = {
    x: document.getElementById('r_x'),
    y: document.getElementById('r_y'),
    w: document.getElementById('r_w'),
    h: document.getElementById('r_h'),
  };

  const NAT_W = <?= $nW > 0 ? $nW : 'bg.naturalWidth' ?>;
  const NAT_H = <?= $nH > 0 ? $nH : 'bg.naturalHeight' ?>;

  let state = {
    x: <?= $qx ?>,
    y: <?= $qy ?>,
    w: <?= $qw ?>,
    h: <?= $qh ?>,
  };

  const nameBox    = document.getElementById('nameBox');
  const nameLabel  = document.getElementById('nameLabel');
  const bnEnabled  = document.getElementById('bnEnabled');
  const nameFields = nameBox ? { x: document.getElementById('f_nx'), y: document.getElementById('f_ny'), w: document.getElementById('f_nw'), h: document.getElementById('f_nh') } : null;
  const nameReadout = nameBox ? { x: document.getElementById('rn_x'), y: document.getElementById('rn_y'), w: document.getElementById('rn_w'), h: document.getElementById('rn_h') } : null;

  let nameState = nameBox ? {
    x: <?= $bnx ?>,
    y: <?= $bny ?>,
    w: <?= $bnw ?>,
    h: <?= $bnh ?>,
  } : null;

  function dispScale() {
    const dispW = bg.clientWidth || bg.offsetWidth || 1;
    return NAT_W / dispW;
  }

  function clampQr() {
    state.w = Math.max(20, Math.min(state.w, NAT_W));
    state.h = Math.max(20, Math.min(state.h, NAT_H));
    state.x = Math.max(0, Math.min(state.x, NAT_W - state.w));
    state.y = Math.max(0, Math.min(state.y, NAT_H - state.h));
  }

  function clampName() {
    if (!nameState) return;
    nameState.w = Math.max(40, Math.min(nameState.w, NAT_W));
    nameState.h = Math.max(30, Math.min(nameState.h, NAT_H));
    nameState.x = Math.max(0, Math.min(nameState.x, NAT_W - nameState.w));
    nameState.y = Math.max(0, Math.min(nameState.y, NAT_H - nameState.h));
  }

  function renderQr() {
    clampQr();
    const s = dispScale();
    box.style.left   = (state.x / s) + 'px';
    box.style.top    = (state.y / s) + 'px';
    box.style.width  = (state.w / s) + 'px';
    box.style.height = (state.h / s) + 'px';

    fields.x.value = state.x;
    fields.y.value = state.y;
    fields.w.value = state.w;
    fields.h.value = state.h;

    inputs.x.value = state.x;
    inputs.y.value = state.y;
    inputs.w.value = state.w;
    inputs.h.value = state.h;

    readout.x.textContent = state.x + ' px';
    readout.y.textContent = state.y + ' px';
    readout.w.textContent = state.w + ' px';
    readout.h.textContent = state.h + ' px';

    label.textContent = 'QR ' + state.w + '×' + state.h + ' @ (' + state.x + ',' + state.y + ')';
  }

  function renderName() {
    if (!nameBox || !nameState || !nameFields) return;
    clampName();
    const s = dispScale();
    nameBox.style.left   = (nameState.x / s) + 'px';
    nameBox.style.top    = (nameState.y / s) + 'px';
    nameBox.style.width  = (nameState.w / s) + 'px';
    nameBox.style.height = (nameState.h / s) + 'px';

    nameFields.x.value = nameState.x;
    nameFields.y.value = nameState.y;
    nameFields.w.value = nameState.w;
    nameFields.h.value = nameState.h;

    if (nameReadout) {
      nameReadout.x.textContent = nameState.x + ' px';
      nameReadout.y.textContent = nameState.y + ' px';
      nameReadout.w.textContent = nameState.w + ' px';
      nameReadout.h.textContent = nameState.h + ' px';
    }
    if (nameLabel) {
      nameLabel.textContent = 'NAME ' + nameState.w + '×' + nameState.h + ' @ (' + nameState.x + ',' + nameState.y + ')';
    }
  }

  function renderAll() {
    renderQr();
    renderName();
  }

  let dragTarget = null;
  let dragMode = null;
  let dragStart = null;

  function onPointerDownQr(e) {
    if (e.button !== undefined && e.button !== 0) return;
    const handle = e.target.closest('.qr-handle');
    if (handle) {
      dragTarget = 'qr';
      dragMode = handle.dataset.handle || 'br';
    } else if (e.target === box || box.contains(e.target)) {
      dragTarget = 'qr';
      dragMode = 'move';
    } else {
      return;
    }
    dragStart = { px: e.clientX, py: e.clientY, state: { ...state }, nameState: nameState ? { ...nameState } : null, scale: dispScale() };
    box.setPointerCapture && box.setPointerCapture(e.pointerId);
    e.preventDefault();
  }

  function onPointerDownName(e) {
    if (!nameBox || !nameState || nameBox.classList.contains('is-disabled')) return;
    if (e.button !== undefined && e.button !== 0) return;
    const handle = e.target.closest('.name-handle');
    if (handle) {
      dragTarget = 'name';
      dragMode = handle.dataset.nameHandle || 'br';
    } else if (e.target === nameBox || nameBox.contains(e.target)) {
      dragTarget = 'name';
      dragMode = 'move';
    } else {
      return;
    }
    dragStart = { px: e.clientX, py: e.clientY, state: { ...state }, nameState: { ...nameState }, scale: dispScale() };
    nameBox.setPointerCapture && nameBox.setPointerCapture(e.pointerId);
    e.preventDefault();
    e.stopPropagation();
  }

  function onPointerMove(e) {
    if (!dragTarget || !dragStart) return;
    const sNow = dragStart.scale;
    const dxN = (e.clientX - dragStart.px) * sNow;
    const dyN = (e.clientY - dragStart.py) * sNow;

    if (dragTarget === 'qr') {
      const s0 = dragStart.state;
      if (dragMode === 'move') {
        state.x = Math.round(s0.x + dxN);
        state.y = Math.round(s0.y + dyN);
      } else {
        let nx = s0.x, ny = s0.y, nw = s0.w, nh = s0.h;
        if (dragMode === 'br') { nw = s0.w + dxN; nh = s0.h + dyN; }
        else if (dragMode === 'tr') { nw = s0.w + dxN; nh = s0.h - dyN; ny = s0.y + dyN; }
        else if (dragMode === 'bl') { nw = s0.w - dxN; nh = s0.h + dyN; nx = s0.x + dxN; }
        else if (dragMode === 'tl') { nw = s0.w - dxN; nh = s0.h - dyN; nx = s0.x + dxN; ny = s0.y + dyN; }

        if (lock.checked) {
          const side = Math.max(nw, nh);
          const dw = side - nw, dh = side - nh;
          nw = side; nh = side;
          if (dragMode === 'tl') { nx -= dw; ny -= dh; }
          if (dragMode === 'tr') { ny -= dh; }
          if (dragMode === 'bl') { nx -= dw; }
        }
        state.x = Math.round(nx);
        state.y = Math.round(ny);
        state.w = Math.round(Math.max(20, nw));
        state.h = Math.round(Math.max(20, nh));
      }
      renderQr();
    } else if (dragTarget === 'name' && nameState && dragStart.nameState) {
      const n0 = dragStart.nameState;
      if (dragMode === 'move') {
        nameState.x = Math.round(n0.x + dxN);
        nameState.y = Math.round(n0.y + dyN);
      } else {
        let nx = n0.x, ny = n0.y, nw = n0.w, nh = n0.h;
        if (dragMode === 'br') { nw = n0.w + dxN; nh = n0.h + dyN; }
        else if (dragMode === 'tr') { nw = n0.w + dxN; nh = n0.h - dyN; ny = n0.y + dyN; }
        else if (dragMode === 'bl') { nw = n0.w - dxN; nh = n0.h + dyN; nx = n0.x + dxN; }
        else if (dragMode === 'tl') { nw = n0.w - dxN; nh = n0.h - dyN; nx = n0.x + dxN; ny = n0.y + dyN; }
        nameState.x = Math.round(nx);
        nameState.y = Math.round(ny);
        nameState.w = Math.round(Math.max(40, nw));
        nameState.h = Math.round(Math.max(30, nh));
      }
      renderName();
    }
  }

  function onPointerUp() {
    dragTarget = null;
    dragMode = null;
    dragStart = null;
  }

  box.addEventListener('pointerdown', onPointerDownQr);
  if (nameBox) {
    nameBox.addEventListener('pointerdown', onPointerDownName);
  }
  document.addEventListener('pointermove', onPointerMove);
  document.addEventListener('pointerup', onPointerUp);
  document.addEventListener('pointercancel', onPointerUp);

  function bindInput(key, prop) {
    inputs[key].addEventListener('input', () => {
      const v = parseInt(inputs[key].value, 10);
      if (Number.isFinite(v)) {
        state[prop] = v;
        if (lock.checked && (prop === 'w' || prop === 'h')) {
          state.w = state.h = v;
        }
        renderQr();
      }
    });
  }
  bindInput('x', 'x');
  bindInput('y', 'y');
  bindInput('w', 'w');
  bindInput('h', 'h');

  document.getElementById('btnCenter').addEventListener('click', () => {
    state.x = Math.max(0, Math.round((NAT_W - state.w) / 2));
    state.y = Math.max(0, Math.round((NAT_H - state.h) / 2));
    renderQr();
  });
  document.getElementById('btnFit').addEventListener('click', () => {
    const side = Math.round(Math.min(NAT_W, NAT_H) * 0.5);
    state.w = state.h = side;
    state.x = Math.max(0, Math.round((NAT_W - side) / 2));
    state.y = Math.max(0, Math.round((NAT_H - side) / 2));
    renderQr();
  });

  if (nameBox && nameState && bnEnabled) {
    document.getElementById('btnNameTop').addEventListener('click', () => {
      const w = Math.max(200, NAT_W - 80);
      const h = Math.min(180, Math.max(100, Math.round(NAT_H * 0.14)));
      nameState.w = w;
      nameState.h = h;
      nameState.x = 40;
      nameState.y = 40;
      renderName();
    });
    document.getElementById('btnNameCenter').addEventListener('click', () => {
      const w = Math.max(200, Math.min(NAT_W - 80, Math.round(NAT_W * 0.75)));
      const h = Math.min(200, Math.max(80, Math.round(NAT_H * 0.15)));
      nameState.w = w;
      nameState.h = h;
      nameState.x = Math.max(0, Math.round((NAT_W - w) / 2));
      nameState.y = Math.max(0, Math.round((NAT_H - h) / 2));
      renderName();
    });
    bnEnabled.addEventListener('change', function() {
      if (this.checked) {
        nameBox.classList.remove('is-disabled');
      } else {
        nameBox.classList.add('is-disabled');
      }
    });
  }

  function ready() { renderAll(); }
  if (bg.complete && bg.naturalWidth > 0) {
    ready();
  } else {
    bg.addEventListener('load', ready, { once: true });
  }
  window.addEventListener('resize', renderAll);
})();

(function(){
  const pick = document.getElementById('bnColorPick');
  const hex = document.getElementById('bnColorHex');
  if (!pick || !hex) return;
  pick.addEventListener('input', function(){ hex.value = pick.value; });
  hex.addEventListener('input', function(){
    var v = hex.value.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(v)) pick.value = v;
  });
})();
</script>

<?php require __DIR__ . '/partials/layout_foot.php'; ?>
