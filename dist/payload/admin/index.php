<?php
declare(strict_types=1);
require '/home/bafo9702/private/bo_auth.php';
require '/home/bafo9702/private/bo_llm.php';
require '/home/bafo9702/private/bo_control.php';

// L'admin ne doit JAMAIS être mis en cache (sinon on re-sert la page de connexion en cache → boucle).
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (isset($_GET['login'])) {
    $email = bo_verify_magic((string)$_GET['login']);
    if ($email) bo_login($email);
    header('Location: /admin/'); exit;
}
$user = bo_current_user();
$ctrl = $user ? bo_control_state() : ['enabled'=>true,'mode'=>BO_DEFAULT_MODE,'message'=>''];
$managed = ($ctrl['mode'] === 'managed');
if (!$user) $state='login';
elseif (!$ctrl['enabled']) $state='suspended';
elseif (!bo_is_configured()) $state = $managed ? 'pending' : 'setup';
else $state='editor';
$providers = $user ? bo_providers() : [];
$selected  = $user ? bo_selected_id() : '';
$html_pages = array_values(array_filter(BO_EDITABLE, fn($f)=>substr($f,-5)==='.html'));
function opt_providers(array $providers, string $selected): string {
    $h='';
    foreach ($providers as $p) {
        $sel = $p['id']===$selected ? ' selected' : '';
        $free = !empty($p['free']) ? ' 🆓' : '';
        $h .= '<option value="'.htmlspecialchars($p['id']).'"'.$sel.'>'.htmlspecialchars($p['label']).$free.'</option>';
    }
    return $h;
}
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Espace d'édition — <?=htmlspecialchars(BO_SITE_NAME)?></title>
<style>
  :root{--navy:#21386E;--ink:#1B2A4E;--orange:#F5A012;--sky:#57C1E0;--surface:#FAF9F6;--line:#E3E1D9;--muted:#4F5B7A;}
  *{box-sizing:border-box} body{margin:0;background:var(--surface);color:var(--ink);font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
  header{background:var(--navy);color:#fff;padding:.8rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem}
  header h1{font-size:1.05rem;margin:0}
  header .meta{font-size:.82rem;opacity:.92;display:flex;gap:.7rem;align-items:center;flex-wrap:wrap}
  header a.logout{color:#fff;opacity:.85;font-size:.82rem}
  .topnav{display:flex;gap:.2rem;flex-wrap:wrap}
  .topnav a{color:#fff;opacity:.8;padding:.35rem .75rem;border-radius:.45rem;font-size:.9rem;text-decoration:none;cursor:pointer}
  .topnav a.active{background:rgba(255,255,255,.18);opacity:1;font-weight:700}
  main{max-width:980px;margin:1.5rem auto;padding:0 1.25rem} .narrow{max-width:480px}
  [hidden]{display:none!important}
  .card{background:#fff;border:1px solid var(--line);border-radius:.75rem;padding:1.25rem;margin-bottom:1.25rem}
  h2{font-size:1.05rem;margin:.2rem 0 .8rem}
  textarea,input[type=text],input[type=email],input[type=password],select{width:100%;font:inherit;padding:.7rem;border:1px solid var(--line);border-radius:.5rem;background:#fff}
  textarea{min-height:110px;resize:vertical}
  .btn{display:inline-flex;align-items:center;gap:.4rem;border:0;border-radius:.5rem;padding:.7rem 1.1rem;font:inherit;font-weight:700;cursor:pointer}
  .btn-navy{background:var(--navy);color:#fff}.btn-navy:hover{background:var(--ink)}
  .btn-green{background:#1f7a4d;color:#fff}.btn-green:hover{background:#155c39}
  .btn-ghost{background:#fff;border:1px solid var(--line);color:var(--ink)}.btn-ghost:hover{background:var(--surface)}
  .btn[disabled]{opacity:.55;cursor:default}
  .row{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;margin-top:.8rem}
  .muted{color:var(--muted)} .small{font-size:.85rem}
  .summary{white-space:pre-wrap;background:var(--surface);border:1px solid var(--line);border-radius:.5rem;padding:.9rem;margin:.6rem 0}
  .files li{margin:.15rem 0} .ok{color:#1f7a4d;font-weight:700} .err{color:#b3261e;font-weight:700}
  .pill{display:inline-block;background:#eef4fa;color:var(--navy);border-radius:999px;padding:.1rem .6rem;font-size:.8rem}
  a{color:var(--navy)}
  .spinner{width:1rem;height:1rem;border:2px solid #cbd5e1;border-top-color:#21386E;border-radius:50%;animation:s .7s linear infinite;display:inline-block}
  @keyframes s{to{transform:rotate(360deg)}}
  label.lbl{display:block;font-weight:700;margin:.6rem 0 .2rem;font-size:.9rem}
  .proposal-grid{display:grid;gap:1rem;grid-template-columns:1fr}
  @media(min-width:900px){.proposal-grid{grid-template-columns:minmax(0,340px) 1fr}}
  .preview-frame{width:100%;height:540px;border:1px solid var(--line);border-radius:.5rem;background:#fff}
  .hist{list-style:none;padding:0;margin:.4rem 0}
  .hist li{border:1px solid var(--line);border-radius:.5rem;padding:.7rem .9rem;margin-bottom:.5rem;display:flex;justify-content:space-between;gap:.8rem;align-items:flex-start;flex-wrap:wrap}
  .hist .when{font-size:.78rem;color:var(--muted)}
  .uploads{display:flex;flex-wrap:wrap;gap:.7rem;margin-top:.8rem}
  .thumb{position:relative;width:96px}
  .thumb img{width:96px;height:72px;object-fit:cover;border:1px solid var(--line);border-radius:.4rem;background:#fff}
  .thumb .x{position:absolute;top:-8px;right:-8px;width:22px;height:22px;border-radius:50%;border:0;background:#b3261e;color:#fff;cursor:pointer;font-weight:700;line-height:1;font-size:14px}
  .thumb .fn{font-size:.62rem;color:var(--muted);word-break:break-all;margin-top:.15rem}
  .thumb .filecard{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.15rem;width:96px;height:72px;border:1px solid var(--line);border-radius:.4rem;background:#fff;font-size:1.7rem;text-decoration:none;color:var(--navy)}
  .thumb .filecard span{font-size:.6rem;font-weight:700}
  .banner{background:#e8f5ee;border:1px solid #bfe3cf;border-radius:.5rem;padding:.7rem .9rem;margin:0 0 1rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
</style>
</head>
<body>

<?php if ($state==='login'): ?>
<header><h1>🔐 Espace d'édition — <?=htmlspecialchars(BO_SITE_NAME)?></h1></header>
<main class="narrow"><div class="card">
  <h2>Connexion</h2>
  <p class="muted small">Entrez votre email : vous recevrez un lien de connexion (15 min). Aucun mot de passe à retenir.</p>
  <input type="email" id="email" placeholder="vous@exemple.com" autocomplete="email">
  <div class="row"><button class="btn btn-navy" id="btn-login">Recevoir mon lien</button><span class="small" id="login-status"></span></div>
  <div id="dev-link" class="small" style="margin-top:.8rem"></div>
</div></main>
<script>
const $=s=>document.querySelector(s);
$('#btn-login').onclick=async()=>{const email=$('#email').value.trim();if(!email)return;
  $('#btn-login').disabled=true;$('#login-status').innerHTML='<span class="spinner"></span>';
  try{const r=await(await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'login_request',email})})).json();
    $('#login-status').innerHTML='<span class="ok">'+(r.message||'Envoyé.')+'</span>';
    if(r.dev_link)$('#dev-link').innerHTML='Lien de test : <a href="'+r.dev_link+'">se connecter</a>';
  }catch(e){$('#login-status').innerHTML='<span class="err">Erreur réseau</span>';}
  $('#btn-login').disabled=false;};
$('#email').addEventListener('keydown',e=>{if(e.key==='Enter')$('#btn-login').click();});
</script>

<?php elseif ($state==='suspended'): ?>
<header><h1>⛔ <?=htmlspecialchars(BO_SITE_NAME)?></h1></header>
<main class="narrow"><div class="card">
  <h2>Accès suspendu</h2>
  <p class="muted"><?= $ctrl['message']!=='' ? htmlspecialchars($ctrl['message']) : "L'accès à l'espace d'édition est temporairement suspendu. Merci de contacter votre prestataire." ?></p>
</div></main>

<?php elseif ($state==='pending'): ?>
<header><h1>✏️ <?=htmlspecialchars(BO_SITE_NAME)?></h1><div class="meta"><?=htmlspecialchars($user)?> · <a class="logout" href="#" id="logout">déconnexion</a></div></header>
<main class="narrow"><div class="card">
  <h2>Configuration en cours</h2>
  <p class="muted">Votre espace est en cours de configuration par votre prestataire. Revenez bientôt.</p>
</div></main>
<script>const $=s=>document.querySelector(s);$('#logout').onclick=async e=>{e.preventDefault();await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'logout'})});location.reload();};</script>

<?php else: ?>
<header>
  <h1>✏️ <?=htmlspecialchars(BO_SITE_NAME)?></h1>
  <nav class="topnav">
    <a data-view="edit" class="active">✏️ Éditer</a>
    <a data-view="history">🕘 Historique</a>
    <a data-view="settings">⚙️ Réglages</a>
    <a href="/" target="_blank" rel="noopener">👁 Voir le site</a>
  </nav>
  <div class="meta"><span><?=htmlspecialchars($user)?></span> · <span id="quota" class="small" title="Consommation IA du jour via ce site">📊 …</span> · <a class="logout" href="#" id="logout">déconnexion</a></div>
</header>
<main>

  <!-- ===== ÉDITER ===== -->
  <section data-section="edit">
    <div id="edit-status"></div>
    <div class="card">
      <h2>Demander une modification</h2>
      <p class="muted small">Décrivez en français ce que vous voulez changer. Ex. : « Remplace le titre de l'accueil par … », « Ajoute une question à la FAQ : … ».</p>
      <textarea id="request" placeholder="Votre demande…"></textarea>
      <div class="row"><button class="btn btn-navy" id="btn-propose">Préparer la modification</button><span class="small muted" id="propose-status"></span></div>
    </div>

    <div class="card" id="proposal" hidden>
      <h2>Proposition de l'assistant</h2>
      <p class="muted small" style="margin-top:0">⚠️ Rien n'est encore publié. Vérifiez l'aperçu à droite, puis cliquez <b>« Appliquer »</b>.</p>
      <div class="proposal-grid">
        <div>
          <div class="summary" id="prop-summary"></div>
          <div id="prop-files"></div>
          <div class="row">
            <button class="btn btn-green" id="btn-apply">✅ Appliquer (publier)</button>
            <button class="btn btn-ghost" id="btn-cancel">Abandonner</button>
          </div>
          <p class="small" id="apply-status" style="margin-top:.6rem"></p>
        </div>
        <div>
          <label class="lbl">Aperçu (non publié) — page : <select id="prev-page" style="width:auto;display:inline-block;padding:.3rem .5rem"></select></label>
          <iframe class="preview-frame" id="prev-frame" title="Aperçu"></iframe>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Fichiers (images, PDF, documents)</h2>
      <p class="muted small" style="margin-top:0">Téléversez une image ou un document (PDF, Word, Excel…) <b>— ou collez une image</b> (Ctrl/Cmd + V) dans la zone de demande ci-dessus. Puis : « remplace la carte PDF par <code>assets/xxx.pdf</code> » ou « utilise l'image <code>assets/xxx.jpg</code> pour … ».</p>
      <div class="row"><input type="file" id="image" accept=".jpg,.jpeg,.png,.webp,.gif,.svg,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv"><button class="btn btn-ghost" id="btn-upload">Téléverser</button><span class="small" id="upload-status"></span></div>
      <div id="uploads" class="uploads"></div>
    </div>
  </section>

  <!-- ===== HISTORIQUE ===== -->
  <section data-section="history" hidden>
    <div class="card">
      <h2>Historique des modifications</h2>
      <p class="muted small">Chaque modification publiée est enregistrée. Vous pouvez revenir à l'état d'avant n'importe laquelle.</p>
      <div class="row"><button class="btn btn-ghost" id="btn-refresh-hist">Rafraîchir</button> <a class="btn btn-ghost" href="/" target="_blank" rel="noopener">👁 Voir le site</a></div>
      <ul class="hist" id="hist-list"><li class="muted small">Chargement…</li></ul>
    </div>
  </section>

  <!-- ===== RÉGLAGES ===== -->
  <section data-section="settings" hidden>
    <div class="card">
      <h2>Consommation (aujourd'hui)</h2>
      <p class="muted small" id="usage-line">…</p>
    </div>
    <?php if(!$managed): ?>
    <div class="card">
      <h2>Fournisseur IA</h2>
      <label class="lbl">Fournisseur actif (changez-en si le résultat ne vous convient pas)</label>
      <select id="prov"><?=opt_providers($providers,$selected)?></select>
      <div class="row"><button class="btn btn-ghost" id="btn-setkey">🔑 Saisir / changer la clé de ce fournisseur</button><span class="small muted" id="prov-status"></span></div>
    </div>
    <?php endif; ?>
    <div class="card">
      <h2>Maintenance</h2>
      <div class="row"><button class="btn btn-ghost" id="btn-update">⬆️ Mettre à jour le back-office</button><span class="small muted" id="update-status"></span></div>
      <p class="muted small" style="margin-bottom:0">Version installée : <span id="ver">—</span>.</p>
    </div>
  </section>
</main>

<script>
const $=s=>document.querySelector(s), $$=s=>document.querySelectorAll(s); let token=null;
const PROVS=<?=json_encode(array_map(fn($p)=>['id'=>$p['id'],'key_url'=>$p['key_url']??'','free'=>!empty($p['free'])],$providers), JSON_UNESCAPED_UNICODE)?>;
const HTML_PAGES=<?=json_encode($html_pages, JSON_UNESCAPED_UNICODE)?>;
async function api(action,data,isForm){const opt={method:'POST'};
  if(isForm){data.append('action',action);opt.body=data;}else{const b=new URLSearchParams(data||{});b.set('action',action);opt.body=b;opt.headers={'Content-Type':'application/x-www-form-urlencoded'};}
  const r=await fetch('api.php',opt); if(r.status===401){location.reload();throw new Error('auth');} return r.json();}

/* ---- navigation par onglets ---- */
$$('.topnav a[data-view]').forEach(a=>a.onclick=()=>{
  $$('.topnav a[data-view]').forEach(x=>x.classList.remove('active')); a.classList.add('active');
  const v=a.dataset.view; $$('section[data-section]').forEach(s=>s.hidden=(s.dataset.section!==v));
  if(v==='history') loadHistory();
});

async function refresh(){try{const s=await api('status');
  if($('#ver'))$('#ver').textContent=s.version;
  const _p=s.providers.find(x=>x.id===s.selected);
  if($('#quota')){const pct=s.cap?Math.round(s.calls_today/s.cap*100):0;const q=$('#quota');q.textContent='📊 '+pct+'%';q.title=s.calls_today+'/'+s.cap+' requêtes aujourd\'hui · ~'+(s.tokens_today||0).toLocaleString('fr-FR')+' tokens'+(_p&&_p.free?' · fournisseur gratuit':'');}
  const pp=s.providers.find(x=>x.id===s.selected);
  if($('#prov-status'))$('#prov-status').textContent=(pp&&pp.has_key)?'clé en place ✔':'⚠️ aucune clé pour ce fournisseur';
  if($('#usage-line')){const pct=s.cap?Math.round(s.calls_today/s.cap*100):0;
    $('#usage-line').innerHTML='📊 Aujourd\'hui via ce site : <b>'+s.calls_today+'/'+s.cap+'</b> requêtes (<b>'+pct+'%</b> du quota quotidien) · <b>~'+(s.tokens_today||0).toLocaleString('fr-FR')+'</b> tokens.'+(pp&&pp.free_note?'<br>Fournisseur <b>'+escapeHtml(pp.label)+'</b> : quota '+escapeHtml(pp.free_note)+'.':'')+'<br><span class="muted">↻ Compteur remis à zéro chaque jour à minuit (heure du serveur). Le quota gratuit restant exact se vérifie sur la console du fournisseur.</span>';}
}catch(e){}}
refresh();
$('#logout').onclick=async e=>{e.preventDefault();await api('logout');location.reload();};

/* ---- Réglages : fournisseur + clé + update ---- */
if($('#prov'))$('#prov').onchange=async()=>{await api('set_provider',{provider:$('#prov').value});refresh();};
if($('#btn-setkey'))$('#btn-setkey').onclick=async()=>{const p=PROVS.find(x=>x.id===$('#prov').value);
  const k=prompt('Collez la clé pour ce fournisseur'+(p&&p.key_url?'\n(obtenir : '+p.key_url+')':'')+' :'); if(!k)return;
  const r=await api('set_key',{provider:$('#prov').value,key:k.trim()}); alert(r.ok?'Clé enregistrée ✔':'Erreur : '+(r.error||'')); refresh();};
$('#btn-update').onclick=async()=>{$('#update-status').innerHTML='<span class="spinner"></span>';
  const r=await api('update'); $('#update-status').innerHTML=r.ok?'<span class="ok">'+(r.message||'OK')+'</span>':'<span class="err">'+(r.error||'Erreur')+'</span>'; refresh();};

/* ---- Édition : préparer / aperçu / appliquer ---- */
$('#btn-propose').onclick=async()=>{const req=$('#request').value.trim();if(!req)return;
  $('#btn-propose').disabled=true;$('#propose-status').innerHTML='<span class="spinner"></span> L\'assistant réfléchit…';$('#proposal').hidden=true;
  try{const r=await api('propose',{request:req});
    if(!r.ok){$('#propose-status').innerHTML='<span class="err">'+(r.error==='needs_key'?'Aucune clé pour le fournisseur (onglet Réglages).':(r.error||'Erreur'))+'</span>';}
    else{token=r.token;$('#prop-summary').textContent=r.summary;
      $('#prop-files').innerHTML=r.changes.length?'<p class="small muted">Fichiers modifiés :</p><ul class="files">'+r.changes.map(c=>'<li><span class="pill">'+c.path+'</span></li>').join('')+'</ul>':'<p class="small muted">Aucun fichier à modifier (voir l\'explication).</p>';
      $('#btn-apply').style.display=r.changes.length?'':'none';
      // aperçu : page modifiée prioritaire, sinon page choisie parmi toutes
      const changedHtml=r.changes.map(c=>c.path).filter(p=>p.endsWith('.html'));
      const opts=(changedHtml.length?changedHtml:HTML_PAGES);
      $('#prev-page').innerHTML=opts.map(p=>'<option>'+p+'</option>').join('');
      setPreview();
      $('#proposal').hidden=false;
      $('#propose-status').innerHTML='<span class="small muted">'+r.provider+' · '+(r.tokens.in+r.tokens.out)+' tokens</span>';refresh();}
  }catch(e){if(e.message!=='auth')$('#propose-status').innerHTML='<span class="err">Erreur réseau</span>';}
  $('#btn-propose').disabled=false;};
function setPreview(){ if(!token)return; const pg=$('#prev-page').value||'index.html';
  $('#prev-frame').src='preview.php?token='+token+'&path='+encodeURIComponent(pg)+'&t='+Date.now(); }
$('#prev-page').onchange=setPreview;
$('#btn-cancel').onclick=()=>{$('#proposal').hidden=true;token=null;$('#prev-frame').src='about:blank';};
$('#btn-apply').onclick=async()=>{if(!token)return;$('#btn-apply').disabled=true;$('#apply-status').textContent='Publication…';
  const r=await api('apply',{token});
  if(r.ok){$('#edit-status').innerHTML='<div class="banner"><span class="ok">✔ Modification publiée.</span> <a class="btn btn-ghost" href="/" target="_blank" rel="noopener">👁 Vérifier sur le site</a> <span class="small muted">(un rechargement normal suffit)</span></div>';$('#request').value='';$('#proposal').hidden=true;$('#prev-frame').src='about:blank';token=null;window.scrollTo({top:0,behavior:'smooth'});refresh();}
  else $('#apply-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>';
  $('#btn-apply').disabled=false;};

/* ---- Image ---- */
$('#btn-upload').onclick=async()=>{const f=$('#image').files[0];if(!f)return;$('#upload-status').textContent='Envoi…';
  const fd=new FormData();fd.append('image',f); const r=await api('upload',fd,true);
  if(r.ok){$('#upload-status').innerHTML='<span class="ok">Fichier ajouté : <code>'+r.filename+'</code></span>';$('#image').value='';loadUploads();}
  else $('#upload-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>';};
async function loadUploads(){const el=$('#uploads');if(!el)return;
  try{const r=await api('list_uploads');if(!r.ok)return;
    el.innerHTML=r.files.map(f=>{const ext=f.split('.').pop().toLowerCase();const isImg=['jpg','jpeg','png','webp','gif','svg'].includes(ext);const inner=isImg?'<img src="/'+f+'" alt="">':'<a class="filecard" href="/'+f+'" target="_blank" rel="noopener">📄<span>'+ext.toUpperCase()+'</span></a>';return '<div class="thumb">'+inner+'<button class="x" data-f="'+f+'" title="Supprimer">×</button><div class="fn">'+escapeHtml(f.replace('assets/',''))+'</div></div>';}).join('');
    el.querySelectorAll('.x').forEach(b=>b.onclick=async()=>{if(!confirm('Supprimer '+b.dataset.f+' ?'))return;const r=await api('delete_image',{filename:b.dataset.f});if(r.ok){if($('#upload-status'))$('#upload-status').textContent='';loadUploads();}else alert(r.error||'Erreur');});
  }catch(e){}}
loadUploads();
$('#request').addEventListener('paste',async(e)=>{
  const items=(e.clipboardData&&e.clipboardData.items)?e.clipboardData.items:[];
  for(const it of items){ if(it.type&&it.type.indexOf('image')===0){ const blob=it.getAsFile(); if(!blob)continue; e.preventDefault();
    $('#upload-status').innerHTML='<span class="spinner"></span> collage…';
    const fd=new FormData();fd.append('image',blob,'collage.png'); const r=await api('upload',fd,true);
    if(r.ok){const t=$('#request');const a=t.selectionStart||t.value.length,b2=t.selectionEnd||t.value.length;t.value=t.value.slice(0,a)+' '+r.filename+' '+t.value.slice(b2);$('#upload-status').innerHTML='<span class="ok">Image collée : <code>'+r.filename+'</code></span>';loadUploads();}
    else $('#upload-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>'; }}
});

/* ---- Historique ---- */
async function loadHistory(){const el=$('#hist-list');el.innerHTML='<li class="muted small">Chargement…</li>';
  try{const r=await api('history');
    if(!r.ok||!r.entries.length){el.innerHTML='<li class="muted small">Aucune modification enregistrée pour l\'instant.</li>';return;}
    el.innerHTML=r.entries.map(e=>'<li><div><div>'+escapeHtml(e.summary)+'</div><div class="when">'+e.date+(e.changed&&e.changed.length?' · '+e.changed.join(', '):'')+'</div></div><button class="btn btn-ghost btn-restore" data-id="'+e.id+'">↩︎ Revenir à avant</button></li>').join('');
    $$('.btn-restore').forEach(b=>b.onclick=async()=>{ if(!confirm('Revenir à l\'état d\'avant cette modification ?'))return;
      b.disabled=true;b.textContent='…'; const r=await api('restore',{id:b.dataset.id});
      if(r.ok){alert('Restauré ✔');loadHistory();}else{alert('Erreur : '+(r.error||''));b.disabled=false;}});
  }catch(e){el.innerHTML='<li class="err small">Erreur de chargement.</li>';}}
function escapeHtml(s){return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
</script>
<?php endif; ?>
</body>
</html>
