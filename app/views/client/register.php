<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Krishna Review System - Client Registration</title>
  <style>
    :root{--peacock:#005f8f;--deepyellow:#f4b400;--gold:#d4af37;--bg:#f7f8fc;}
    *{box-sizing:border-box} body{margin:0;background:linear-gradient(180deg,#f7f8fc,#eef2ff);font-family:Segoe UI,Arial,sans-serif}
    .wrap{max-width:760px;margin:24px auto;padding:14px}.card{background:#fff;border-radius:16px;padding:18px;border-top:5px solid var(--deepyellow);box-shadow:0 10px 26px rgba(0,95,143,.15)}
    .grid{display:grid;gap:10px}.g2{grid-template-columns:1fr}@media(min-width:640px){.g2{grid-template-columns:1fr 1fr}}
    label{display:block;font-size:.9rem;font-weight:600;color:var(--peacock);margin-bottom:5px} input,select,textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px}
    .btn{width:100%;margin-top:12px;border:0;border-radius:12px;padding:12px;font-weight:700;background:linear-gradient(90deg,var(--deepyellow),var(--gold));cursor:pointer}
    .facility{display:grid;grid-template-columns:1fr 1fr;gap:8px;border:1px solid #e2e8f0;padding:10px;border-radius:10px;background:#f8fafc}
    .msg{margin:10px 0}.ok{background:#ecfdf5;border:1px solid #bbf7d0;padding:10px;border-radius:10px}.err{background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:10px}
    .qr{margin-top:10px;border:1px dashed #cbd5e1;padding:10px;border-radius:10px;background:#fffdf8}.qr img{max-width:220px;display:block;margin:8px auto}
  </style>
</head>
<body>
<div class="wrap"><div class="card">
  <h2 style="margin:0;color:#005f8f">Krishna Review System - Business Registration</h2>
  <p style="margin:6px 0 14px;color:#475569">Create account and get your dynamic QR instantly.</p>
  <div id="formMessage" class="msg"></div>
  <div id="qrResult" class="qr" style="display:none"></div>
  <form id="registerForm" method="post">
    <div class="grid g2">
      <div><label>Business Name *</label><input name="business_name" required></div>
      <div><label>Owner Name</label><input name="owner_name"></div>
      <div><label>Email *</label><input type="email" name="email" required></div>
      <div><label>Password *</label><input type="password" name="password" required></div>
      <div><label>Mobile *</label><input name="mobile" required></div>
      <div><label>Category *</label><select name="category_id" required><option value="">Select Category</option><?php foreach ($categories as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div style="margin-top:10px"><label>Address *</label><textarea name="address" required></textarea></div>
    <div style="margin-top:10px">
      <label>Google Place ID *</label>
      <input name="google_place_id" required>
      <div style="margin-top:6px;font-size:.85rem">
        <a href="https://developers.google.com/maps/documentation/places/web-service/place-id#find-id" target="_blank" rel="noopener">Find your Google Place ID here</a>
      </div>
    </div>
    <div style="margin-top:10px"><label>Facilities</label><div class="facility"><div style="color:#64748b">Select category to view facilities.</div></div></div>
    <button class="btn" type="submit" id="submitBtn">Register & Generate QR</button>
  </form>
</div></div>
<script>
const form=document.getElementById('registerForm'),msg=document.getElementById('formMessage'),qr=document.getElementById('qrResult'),btn=document.getElementById('submitBtn');
const categorySelect=form.querySelector('select[name="category_id"]');
const facilityWrap=form.querySelector('.facility');
const esc=(s)=>String(s).replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
form.addEventListener('submit',async(e)=>{e.preventDefault();msg.innerHTML='';qr.style.display='none';btn.disabled=true;btn.textContent='Processing...';
  try{
    const res=await fetch(window.location.href,{method:'POST',body:new FormData(form)});
    const raw=await res.text();
    let r=null;
    try{r=JSON.parse(raw);}catch{throw new Error(raw.slice(0,180)||'Invalid JSON response');}
    if(!r.ok){msg.innerHTML=`<div class="err"><ul>${(r.errors||['Failed']).map(x=>`<li>${esc(x)}</li>`).join('')}</ul></div>`;}
    else{msg.innerHTML=`<div class="ok">${esc(r.message||'Success')}</div>`;
      if(r.redirect){ window.location.href=r.redirect; return; }
      qr.style.display='block';qr.innerHTML=`<h3 style="margin:0;color:#0b1f4d">Your QR Code</h3><img src="${esc(r.data.qr_image_url)}" alt="QR"><p style="word-break:break-all"><strong>Public URL:</strong><br>${esc(r.data.public_review_url)}</p><p><a href="${esc(r.data.qr_image_url)}" download>Download QR</a> | <a href="${esc(r.data.standee_image_url||'#')}" download>Download Print Standee</a></p>`;form.reset();}
  }catch(err){msg.innerHTML='<div class="err">'+esc(err?.message||'Network/server error.')+'</div>';}
  btn.disabled=false;btn.textContent='Register & Generate QR';
});

// Category-specific facilities from backend (AJAX)
async function loadFacilitiesByCategory(){
  const selected = Number(categorySelect.value || 0);
  facilityWrap.innerHTML = '<div style="color:#64748b">Loading facilities...</div>';
  if(!selected){
    facilityWrap.innerHTML = '<div style="color:#64748b">Select category to view facilities.</div>';
    return;
  }
  try{
    const res = await fetch('register_facilities.php?category_id=' + encodeURIComponent(String(selected)), { headers: { 'Accept': 'application/json' } });
    const data = await res.json();
    if(!data.ok){
      facilityWrap.innerHTML = '<div style="color:#dc2626">Could not load facilities.</div>';
      return;
    }
    const list = Array.isArray(data.facilities) ? data.facilities : [];
    if(list.length === 0){
      facilityWrap.innerHTML = '<div style="color:#64748b">No facilities configured for this category yet.</div>';
      return;
    }
    facilityWrap.innerHTML = list.map(f => `<label style="display:flex;gap:6px;align-items:center;color:#334155"><input type="checkbox" name="facilities[]" value="${esc(String(f.id))}" style="width:auto">${esc(String(f.facility_name||''))}</label>`).join('');
  }catch(e){
    facilityWrap.innerHTML = '<div style="color:#dc2626">Network error while loading facilities.</div>';
  }
}
categorySelect.addEventListener('change', loadFacilitiesByCategory);
loadFacilitiesByCategory();
</script>
</body>
</html>
