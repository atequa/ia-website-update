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
  header{background:var(--navy);color:#fff;padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
  header h1{font-size:1.1rem;margin:0}
  header .meta{font-size:.82rem;opacity:.92;display:flex;gap:.7rem;align-items:center;flex-wrap:wrap}
  header a.logout{color:#fff;opacity:.85;font-size:.82rem}
  main{max-width:860px;margin:1.5rem auto;padding:0 1.25rem} .narrow{max-width:480px}
  .card{background:#fff;border:1px solid var(--line);border-radius:.75rem;padding:1.25rem;margin-bottom:1.25rem}
  h2{font-size:1.05rem;margin:.2rem 0 .8rem}
  textarea,input[type=text],input[type=email],input[type=password],select{width:100%;font:inherit;padding:.7rem;border:1px solid var(--line);border-radius:.5rem;background:#fff}
  textarea{min-height:120px;resize:vertical}
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

<?php elseif ($state==='setup'): ?>
<header><h1>✏️ <?=htmlspecialchars(BO_SITE_NAME)?></h1><div class="meta"><?=htmlspecialchars($user)?> · <a class="logout" href="#" id="logout">déconnexion</a></div></header>
<main class="narrow"><div class="card">
  <h2>Choisissez votre IA</h2>
  <p class="muted small">Sélectionnez un fournisseur, puis collez votre clé. Les fournisseurs 🆓 proposent un usage gratuit (qualité variable). Vous pourrez en changer à tout moment.</p>
  <label class="lbl">Fournisseur</label>
  <select id="prov"><?=opt_providers($providers,$selected)?></select>
  <p class="small muted" id="prov-help" style="margin:.4rem 0 0"></p>
  <label class="lbl">Votre clé</label>
  <input type="password" id="key" placeholder="collez votre clé ici">
  <div class="row"><button class="btn btn-navy" id="btn-key">Enregistrer et commencer</button><span class="small" id="key-status"></span></div>
</div></main>
<script>
const $=s=>document.querySelector(s);
const PROVS=<?=json_encode(array_map(fn($p)=>['id'=>$p['id'],'key_url'=>$p['key_url']??'','free'=>!empty($p['free'])],$providers), JSON_UNESCAPED_UNICODE)?>;
function help(){const p=PROVS.find(x=>x.id===$('#prov').value);if(p&&p.key_url)$('#prov-help').innerHTML='Obtenir une clé '+(p.free?'(gratuite) ':'')+': <a href="'+p.key_url+'" target="_blank" rel="noopener">'+new URL(p.key_url).host+'</a>';}
$('#prov').onchange=help; help();
$('#logout').onclick=async e=>{e.preventDefault();await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'logout'})});location.reload();};
$('#btn-key').onclick=async()=>{const key=$('#key').value.trim();if(!key)return;
  $('#btn-key').disabled=true;$('#key-status').innerHTML='<span class="spinner"></span>';
  const r=await(await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'set_key',provider:$('#prov').value,key})})).json();
  if(r.ok)location.reload();else{$('#key-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>';$('#btn-key').disabled=false;}};
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
<header><h1>✏️ <?=htmlspecialchars(BO_SITE_NAME)?></h1><div class="meta" id="meta"><?=htmlspecialchars($user)?> · <a class="logout" href="#" id="logout">déconnexion</a></div></header>
<main>
  <div class="card">
    <h2>Demander une modification</h2>
    <p class="muted small">Décrivez en français ce que vous voulez changer. Ex. : « Remplace le titre de l'accueil par … », « Ajoute une question à la FAQ : … », « Corrige la faute dans le 2ᵉ témoignage ».</p>
    <textarea id="request" placeholder="Votre demande…"></textarea>
    <div class="row"><button class="btn btn-navy" id="btn-propose">Prévisualiser la modification</button><span class="small muted" id="propose-status"></span></div>
  </div>

  <div class="card" id="proposal" style="display:none">
    <h2>Proposition de l'assistant</h2>
    <div class="summary" id="prop-summary"></div><div id="prop-files"></div>
    <div class="row"><button class="btn btn-green" id="btn-apply">✅ Appliquer</button><button class="btn btn-ghost" id="btn-cancel">Annuler</button><span class="small muted" id="apply-status"></span></div>
  </div>

  <div class="card">
    <h2>Ajouter / remplacer une image</h2>
    <div class="row"><input type="file" id="image" accept="image/*"><button class="btn btn-ghost" id="btn-upload">Téléverser</button><span class="small" id="upload-status"></span></div>
    <p class="muted small" style="margin-bottom:0">Puis demandez : « utilise l'image <code>assets/xxx.jpg</code> pour … ».</p>
  </div>

  <div class="card">
    <h2>Sécurité & sauvegarde</h2>
    <div class="row">
      <a class="btn btn-ghost" href="/" target="_blank" rel="noopener">👁 Voir le site</a>
      <button class="btn btn-ghost" id="btn-undo">↩︎ Annuler la dernière modification</button><span class="small" id="undo-status"></span>
    </div>
  </div>

  <?php if(!$managed): ?>
  <div class="card">
    <h2>Fournisseur IA</h2>
    <label class="lbl">Fournisseur actif (vous pouvez changer si le résultat ne vous convient pas)</label>
    <select id="prov"><?=opt_providers($providers,$selected)?></select>
    <div class="row">
      <button class="btn btn-ghost" id="btn-setkey">🔑 Saisir/changer la clé de ce fournisseur</button>
      <span class="small muted" id="prov-status"></span>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Maintenance</h2>
    <div class="row">
      <button class="btn btn-ghost" id="btn-update">⬆️ Mettre à jour le back-office</button>
      <span class="small muted" id="update-status"></span>
    </div>
    <p class="muted small" style="margin-bottom:0">Version : <span id="ver">—</span>. Récupère la dernière version signée et l'installe.</p>
  </div>
</main>
<script>
const $=s=>document.querySelector(s); let token=null;
const PROVS=<?=json_encode(array_map(fn($p)=>['id'=>$p['id'],'key_url'=>$p['key_url']??'','free'=>!empty($p['free'])],$providers), JSON_UNESCAPED_UNICODE)?>;
async function api(action,data,isForm){const opt={method:'POST'};
  if(isForm){data.append('action',action);opt.body=data;}else{const b=new URLSearchParams(data||{});b.set('action',action);opt.body=b;opt.headers={'Content-Type':'application/x-www-form-urlencoded'};}
  const r=await fetch('api.php',opt); if(r.status===401){location.reload();throw new Error('auth');} return r.json();}
async function refresh(){try{const s=await api('status');
  $('#meta').childNodes[0].nodeValue=s.email+' · ';
  $('#ver').textContent=s.version;
  $('#btn-undo').disabled=!s.has_backup;
  if($('#prov-status')){const p=s.providers.find(x=>x.id===s.selected);
    $('#prov-status').textContent=(p&&p.has_key)?('clé en place · '+(s.calls_today)+'/'+s.cap+' requêtes aujourd\'hui'):'⚠️ aucune clé pour ce fournisseur';}
}catch(e){}}
refresh();
$('#logout').onclick=async e=>{e.preventDefault();await api('logout');location.reload();};
if($('#prov'))$('#prov').onchange=async()=>{await api('set_provider',{provider:$('#prov').value});refresh();};
if($('#btn-setkey'))$('#btn-setkey').onclick=async()=>{const p=PROVS.find(x=>x.id===$('#prov').value);
  const k=prompt('Collez la clé pour ce fournisseur'+(p&&p.key_url?'\n(obtenir : '+p.key_url+')':'')+' :'); if(!k)return;
  const r=await api('set_key',{provider:$('#prov').value,key:k.trim()}); alert(r.ok?'Clé enregistrée ✔':'Erreur : '+(r.error||'')); refresh();};
$('#btn-update').onclick=async()=>{$('#update-status').innerHTML='<span class="spinner"></span>';
  const r=await api('update'); $('#update-status').innerHTML=r.ok?'<span class="ok">'+(r.message||'OK')+'</span>':'<span class="err">'+(r.error||'Erreur')+'</span>'; refresh();};
$('#btn-propose').onclick=async()=>{const req=$('#request').value.trim();if(!req)return;
  $('#btn-propose').disabled=true;$('#propose-status').innerHTML='<span class="spinner"></span> L\'assistant réfléchit…';$('#proposal').style.display='none';
  try{const r=await api('propose',{request:req});
    if(!r.ok){$('#propose-status').innerHTML='<span class="err">'+(r.error==='needs_key'?'Aucune clé pour le fournisseur sélectionné (section Fournisseur IA).':(r.error||'Erreur'))+'</span>';}
    else{token=r.token;$('#prop-summary').textContent=r.summary;
      $('#prop-files').innerHTML=r.changes.length?'<p class="small muted">Fichiers modifiés :</p><ul class="files">'+r.changes.map(c=>'<li><span class="pill">'+c.path+'</span> <span class="small muted">'+c.old_len+' → '+c.new_len+' car.</span></li>').join('')+'</ul>':'<p class="small muted">Aucun fichier à modifier (voir l\'explication).</p>';
      $('#btn-apply').style.display=r.changes.length?'':'none';$('#proposal').style.display='';
      $('#propose-status').innerHTML='<span class="small muted">'+r.provider+' · '+(r.tokens.in+r.tokens.out)+' tokens</span>';refresh();}
  }catch(e){if(e.message!=='auth')$('#propose-status').innerHTML='<span class="err">Erreur réseau</span>';}
  $('#btn-propose').disabled=false;};
$('#btn-cancel').onclick=()=>{$('#proposal').style.display='none';token=null;};
$('#btn-apply').onclick=async()=>{if(!token)return;$('#btn-apply').disabled=true;$('#apply-status').textContent='Application…';
  const r=await api('apply',{token});
  if(r.ok){$('#apply-status').innerHTML='<span class="ok">Publié ✔ ('+r.written.join(', ')+'). <a href="/" target="_blank" rel="noopener">Voir</a></span>';$('#request').value='';$('#proposal').style.display='none';refresh();}
  else $('#apply-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>';
  $('#btn-apply').disabled=false;};
$('#btn-undo').onclick=async()=>{if(!confirm('Revenir à l\'état précédent ?'))return;$('#undo-status').textContent='…';
  const r=await api('undo'); $('#undo-status').innerHTML=r.ok?'<span class="ok">Restauré</span>':'<span class="err">'+(r.error||'Erreur')+'</span>';refresh();};
$('#btn-upload').onclick=async()=>{const f=$('#image').files[0];if(!f)return;$('#upload-status').textContent='Envoi…';
  const fd=new FormData();fd.append('image',f); const r=await api('upload',fd,true);
  $('#upload-status').innerHTML=r.ok?'<span class="ok">OK : <code>'+r.filename+'</code></span>':'<span class="err">'+(r.error||'Erreur')+'</span>';};
</script>
<?php endif; ?>
</body>
</html>
