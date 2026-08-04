<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Rate Your Experience</title>
  <style>
    :root{--navy:#0b1f4d;--saffron:#ff8c1a;--gold:#d4af37;--peacock:#005f8f}*{box-sizing:border-box}
    html,body{height:100%}
    body{margin:0;background:linear-gradient(180deg,#f7f8fc,#eef2ff);font-family:Segoe UI,Arial,sans-serif;display:flex;flex-direction:column;min-height:100vh}
    .wrap{max-width:720px;margin:22px auto;padding:14px;flex:1;width:100%;padding-bottom:130px}
    .card{background:#fff;border-radius:16px;padding:20px;border-top:5px solid var(--saffron);box-shadow:0 10px 26px rgba(11,31,77,.1)}
    .stars{display:flex;justify-content:center;gap:8px;margin:16px 0}.star{width:52px;height:52px;border-radius:50%;border:1px solid #dbe3f0;background:#f8fafc;cursor:pointer;font-size:24px;color:#9ca3af}
    .star.active,.star:hover{color:#d97706;border-color:var(--gold);background:#fff7e6}
    .box{display:none;margin-top:12px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:12px;padding:12px}.show{display:block}
    label{display:block;margin:8px 0 5px;color:#0b1f4d;font-weight:600} input,textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px}
    .btn{margin-top:10px;width:100%;border:0;border-radius:12px;padding:12px;font-weight:700;background:linear-gradient(90deg,var(--saffron),var(--gold));cursor:pointer}
    .ok,.err{display:none;margin-top:10px;padding:10px;border-radius:10px}.ok{background:#ecfdf5;border:1px solid #bbf7d0}.err{background:#fef2f2;border:1px solid #fecaca}
    .review{white-space:pre-wrap;border:1px dashed #cbd5e1;background:#fff;border-radius:10px;padding:10px;margin-top:8px}

    /* ---------- Sticky Lead-Generation Footer ---------- */
    .lead-footer{
      position:fixed;left:0;right:0;bottom:0;
      background:linear-gradient(90deg,var(--peacock),var(--navy));
      color:#fff;text-align:center;padding:12px 14px 14px;
      box-shadow:0 -6px 18px rgba(11,31,77,.18);
      font-family:Segoe UI,Arial,sans-serif;
      z-index:100;
    }
    .lead-footer .powered{font-size:.92rem;font-weight:600;opacity:.95}
    .lead-footer .powered strong{color:#ffd34d}
    .lead-footer .cta{
      margin-top:6px;font-weight:700;font-size:.95rem;
      display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;
    }
    .lead-footer .cta a{
      color:#1e293b;background:linear-gradient(90deg,#f4b400,var(--gold));
      text-decoration:none;border-radius:999px;
      padding:7px 14px;font-weight:800;font-size:.95rem;
      display:inline-flex;align-items:center;gap:6px;
    }
    .lead-footer .cta a:hover{filter:brightness(1.05)}
    .lead-footer .cta .phone-icon{display:inline-block;font-size:1rem}
    @media (max-width:480px){
      .lead-footer .cta{font-size:.86rem}
      .lead-footer .cta a{padding:6px 12px;font-size:.86rem}
      .lead-footer .powered{font-size:.82rem}
    }
  </style>
</head>
<body>
<div class="wrap"><div class="card">
  <h2 style="margin:0;color:#0b1f4d"><?= htmlspecialchars($businessName) ?></h2>
  <p style="margin:6px 0 12px;color:#475569">Please rate your experience.</p>
  <div class="stars"><?php for($i=1;$i<=5;$i++): ?><button class="star" data-rating="<?= $i ?>">&#9733;</button><?php endfor; ?></div>
  <p id="lbl" style="text-align:center;color:#475569">Tap a star to continue</p>

  <div id="feedbackBox" class="box">
    <h3 style="margin:0;color:#0b1f4d">Help us improve</h3>
    <label>Name (optional)</label><input id="fbName">
    <label>Mobile (optional)</label><input id="fbMobile">
    <label>Your feedback *</label><textarea id="fbText"></textarea>
    <button class="btn" id="submitFeedbackBtn">Submit Feedback</button>
  </div>

  <div id="positiveBox" class="box">
    <h3 style="margin:0;color:#0b1f4d">Your ready review text</h3>
    <div id="reviewText" class="review"></div>
    <button class="btn" id="copyPostBtn">Copy & Post on Google</button>
  </div>

  <div id="ok" class="ok"></div>
  <div id="err" class="err"></div>
</div></div>

<footer class="lead-footer" role="contentinfo">
  <div class="powered">Powered by <strong><?= htmlspecialchars((string)$systemName) ?></strong></div>
  <?php if (!empty($helplineNumber) || !empty($helplineTel)): ?>
    <div class="cta">
      <span>Want this AI system for your business?</span>
      <a href="tel:<?= htmlspecialchars($helplineTel !== '' ? $helplineTel : (string)$helplineNumber) ?>" aria-label="Call helpline">
        <span class="phone-icon">&#9743;</span>
        Call <?= htmlspecialchars((string)$helplineNumber) ?>
      </a>
    </div>
  <?php endif; ?>
</footer>

<script>
const sessionUuid=<?= json_encode($sessionUuid) ?>,csrfToken=<?= json_encode($csrfToken) ?>,fallbackGoogleUrl=<?= json_encode($googleReviewUrl) ?>;
let selectedRating=0,googleUrl=fallbackGoogleUrl,currentReviewId=0,reviewUsed=false;
const q=(s)=>document.querySelector(s),qa=(s)=>document.querySelectorAll(s);
const fbBox=q('#feedbackBox'),posBox=q('#positiveBox'),ok=q('#ok'),err=q('#err');
function showOk(m){ok.textContent=m;ok.style.display='block';err.style.display='none';}
function showErr(m){err.textContent=m;err.style.display='block';ok.style.display='none';}
async function post(data){
  const fd=new FormData();Object.keys(data).forEach(k=>fd.append(k,data[k]));fd.append('csrf_token',csrfToken);
  const r=await fetch('review_api.php',{method:'POST',body:fd,headers:{'Accept':'application/json'}});
  const raw=await r.text();
  let parsed=null;
  try{parsed=JSON.parse(raw);}catch{
    throw new Error('Invalid JSON from server: '+raw.slice(0,180));
  }
  if(!r.ok){throw new Error(parsed.message||'Server returned HTTP '+r.status);}
  return parsed;
}
function setStars(r){qa('.star').forEach(b=>b.classList.toggle('active',Number(b.dataset.rating)<=r));}

qa('.star').forEach(btn=>btn.addEventListener('click',async()=>{
  try{
    const r=Number(btn.dataset.rating);selectedRating=r;setStars(r);q('#lbl').textContent=`You selected ${r} star${r>1?'s':''}`;
    const res=await post({action:'rate',session_uuid:sessionUuid,rating:r}); if(!res.ok){showErr(res.message||'Failed');return;}
    if(res.path==='feedback'){fbBox.classList.add('show');posBox.classList.remove('show');return;}
    posBox.classList.add('show');fbBox.classList.remove('show');q('#reviewText').textContent='Preparing your review...';
    const p=await post({action:'get_positive_review',session_uuid:sessionUuid});
    if(!p.ok){q('#reviewText').textContent='';showErr(p.message||'No review ready');return;}
    q('#reviewText').textContent=p.review_text;googleUrl=p.google_review_url;currentReviewId=Number(p.review_id||0);reviewUsed=false;
    q('#copyPostBtn').textContent='Copy & Post on Google';
    q('#copyPostBtn').disabled=false;
    showOk('Review text is ready.');
  }catch(e){
    q('#reviewText').textContent='';
    showErr((e&&e.message)?e.message:'Unable to load review. Please try again.');
  }
}));

q('#submitFeedbackBtn').addEventListener('click',async()=>{
  try{
    if(selectedRating<1||selectedRating>3){showErr('Select 1-3 stars first.');return;}
    if(!q('#fbText').value.trim()){showErr('Feedback text is required.');return;}
    const r=await post({action:'submit_feedback',session_uuid:sessionUuid,name:q('#fbName').value.trim(),mobile:q('#fbMobile').value.trim(),feedback_text:q('#fbText').value.trim()});
    if(!r.ok){showErr(r.message||'Could not submit.');return;} showOk(r.message||'Thank you for your feedback.'); q('#fbText').value='';
  }catch(e){
    showErr((e&&e.message)?e.message:'Could not submit feedback.');
  }
});

q('#copyPostBtn').addEventListener('click',async()=>{const t=q('#reviewText').textContent.trim(); if(!t){showErr('Review text not ready.');return;}
  if(currentReviewId<=0){showErr('Review reference missing. Please select rating again.');return;}
  if(reviewUsed){showOk('Review Used');setTimeout(()=>location.href=googleUrl,300);return;}
  try{
    const mark=await post({action:'mark_review_used',session_uuid:sessionUuid,review_id:currentReviewId,review_text:t});
    if(!mark.ok){showErr(mark.message||'Could not mark review used.');return;}
    try{await navigator.clipboard.writeText(t);}catch{const ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);}
    reviewUsed=true;
    q('#copyPostBtn').textContent='Review Used';
    q('#copyPostBtn').disabled=true;
    showOk('Copied. Redirecting to Google...'); setTimeout(()=>location.href=googleUrl,500);
  }catch(e){
    showErr((e&&e.message)?e.message:'Could not complete review action.');
  }
});
</script>
</body>
</html>
