<?php
declare(strict_types=1);
@ini_set('display_errors', '0');                 // jamais de détail d'erreur PHP affiché
require __DIR__ . '/bo_path.php';                 // définit BO_PRIVATE_DIR (généré par site, hors payload)
require BO_PRIVATE_DIR . '/bo_auth.php';
require BO_PRIVATE_DIR . '/bo_llm.php';
require BO_PRIVATE_DIR . '/bo_control.php';

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
$gateway = bo_gateway_enabled();   // passerelle centrale : plus de choix de fournisseur/modèle côté client
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
  .uploads{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.7rem;align-items:center}
  .uploads .up-lbl{font-size:.8rem;color:var(--muted);width:100%;margin-bottom:.1rem}
  .uploads .up{position:relative;display:inline-flex;align-items:stretch;border:1px solid var(--line,#e2e8f0);border-radius:.5rem;background:#f8fafc}
  .uploads .up-name{border:0;background:transparent;border-radius:.5rem 0 0 .5rem;padding:.3rem .55rem;font:inherit;font-size:.82rem;color:#21386E;cursor:pointer;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .uploads .up-name:hover{background:#eef2ff;text-decoration:underline}
  .uploads .up-del{border:0;border-left:1px solid var(--line,#e2e8f0);background:transparent;border-radius:0 .5rem .5rem 0;padding:0 .5rem;font-size:1rem;line-height:1;color:#b3261e;cursor:pointer}
  .uploads .up-del:hover{background:#fdecea}
  .uploads .up-prev{display:none;position:absolute;left:0;top:calc(100% + 6px);z-index:30;max-width:240px;max-height:240px;object-fit:contain;border:1px solid var(--line,#e2e8f0);border-radius:.4rem;background:#fff;padding:3px;box-shadow:0 8px 26px rgba(0,0,0,.2)}
  .uploads .up:hover .up-prev{display:block}
  .thumb{position:relative;width:96px}
  .thumb img{width:96px;height:72px;object-fit:cover;border:1px solid var(--line);border-radius:.4rem;background:#fff}
  .thumb .x{position:absolute;top:-8px;right:-8px;width:22px;height:22px;border-radius:50%;border:0;background:#b3261e;color:#fff;cursor:pointer;font-weight:700;line-height:1;font-size:14px}
  .thumb .fn{font-size:.62rem;color:var(--muted);word-break:break-all;margin-top:.15rem}
  .thumb .filecard{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.15rem;width:96px;height:72px;border:1px solid var(--line);border-radius:.4rem;background:#fff;font-size:1.7rem;text-decoration:none;color:var(--navy)}
  .thumb .filecard span{font-size:.6rem;font-weight:700}
  .banner{background:#e8f5ee;border:1px solid #bfe3cf;border-radius:.5rem;padding:.7rem .9rem;margin:0 0 1rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
  .composer{border:1px solid var(--line);border-radius:.75rem;background:#fff;overflow:hidden}
  .composer textarea{border:0;border-radius:0;min-height:96px;padding:.9rem;width:100%}
  .composer textarea:focus{outline:none}
  .composer-bar{display:flex;align-items:center;gap:.4rem;padding:.5rem .6rem;border-top:1px solid var(--line);flex-wrap:wrap}
  .composer-bar .spacer{flex:1}
  .iconbtn{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid var(--line);border-radius:.5rem;background:#fff;cursor:pointer;font-size:1.05rem;line-height:1;padding:0}
  .iconbtn:hover{background:var(--surface)}
  .iconbtn.rec{background:#fdecea;border-color:#f5b3ab;animation:pulse 1.2s infinite}
  @keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(179,38,30,.4)}50%{box-shadow:0 0 0 6px rgba(179,38,30,0)}}
  .mode-sel{width:auto;padding:.45rem .5rem;border-radius:.5rem;font-size:.86rem}
  .chips{display:flex;flex-wrap:wrap;gap:.45rem;margin:.7rem 0 0}
  .chip{border:1px solid var(--line);background:#fff;border-radius:999px;padding:.35rem .8rem;font:inherit;font-size:.85rem;cursor:pointer;color:var(--navy)}
  .chip:hover{background:#eef4fa}
  .fr-grid{display:grid;gap:.8rem;grid-template-columns:1fr;margin-top:.4rem}
  @media(min-width:640px){.fr-grid{grid-template-columns:1fr 1fr}}
  .fr-pages-head{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-top:.7rem;flex-wrap:wrap}
  .fr-pages{display:flex;flex-wrap:wrap;gap:.4rem .6rem;margin:.3rem 0 .2rem}
  .fr-pages label{display:inline-flex;align-items:center;gap:.35rem;font-size:.88rem;cursor:pointer;border:1px solid var(--line);border-radius:.5rem;padding:.3rem .6rem;background:#fff}
  .fr-pages input{width:auto}
  .btn-sm{padding:.4rem .7rem;font-size:.85rem}
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
</div></main>
<script>
const $=s=>document.querySelector(s);
$('#btn-login').onclick=async()=>{const email=$('#email').value.trim();if(!email)return;
  $('#btn-login').disabled=true;$('#login-status').innerHTML='<span class="spinner"></span>';
  try{const r=await(await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'login_request',email})})).json();
    $('#login-status').innerHTML='<span class="ok">'+(r.message||'Envoyé.')+'</span>';
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
      <p class="muted small" style="margin-top:0">Choisissez la page à modifier, puis décrivez le changement — ou dictez-le avec le micro 🎤. Joignez une image/PDF avec le trombone 📎 (ou collez avec Ctrl/Cmd + V).</p>
      <div class="row" style="margin:.2rem 0 .7rem"><label class="lbl" style="margin:0">📄 Page à modifier</label><select id="ai-page" style="width:auto;min-width:210px"><option value="">— Choisir une page —</option></select></div>
      <div class="composer">
        <textarea id="request" placeholder="Votre demande…  (micro 🎤 pour dicter)"></textarea>
        <div class="composer-bar">
          <button class="iconbtn" id="btn-attach" type="button" title="Joindre une image ou un document">📎</button>
          <input type="file" id="image" hidden accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv">
          <button class="iconbtn" id="btn-mic" type="button" title="Dicter à la voix" hidden>🎤</button>
          <span class="small" id="upload-status"></span>
          <span class="spacer"></span>
<?php if(!$managed && !$gateway): ?>          <select class="mode-sel" id="mode-sel" title="Qualité des réponses"></select>
<?php endif; ?>          <button class="btn btn-navy" id="btn-propose">Préparer</button>
        </div>
      </div>
      <div class="uploads" id="uploads" hidden></div>
      <div class="small muted" id="propose-status" style="margin-top:.5rem"></div>
      <div class="chips" id="chips">
        <button class="chip" type="button" data-tpl="Remplace le texte « … » par « … »">✏️ Modifier un texte</button>
        <button class="chip" type="button" data-tpl="Utilise l'image assets/… à la place de la photo …">🖼️ Changer une photo</button>
        <button class="chip" type="button" data-tpl="Mets à jour les horaires : …">🕒 Horaires</button>
        <button class="chip" type="button" data-tpl="Ajoute une actualité sur la page d'accueil : …">📣 Ajouter une actu</button>
        <button class="chip" type="button" data-tpl="Corrige les fautes d'orthographe de la page d'accueil.">✅ Corriger les fautes</button>
      </div>
    </div>

    <div class="card">
      <h2>Remplacer un mot dans les pages (sans IA)</h2>
      <p class="muted small" style="margin-top:0">Correction <b>identique</b> dans les pages cochées — ex. remplacer « — » par « - », corriger une faute récurrente, changer un numéro. Instantané, gratuit, réversible via l'historique. <b>Ne touche qu'au texte des pages</b> (jamais le style ni la configuration).</p>
      <div class="fr-grid">
        <label class="lbl">Chercher (texte exact)<input type="text" id="fr-find" placeholder="ex. —"></label>
        <label class="lbl">Remplacer par<input type="text" id="fr-repl" placeholder="ex. -   (vide = supprimer)"></label>
      </div>
      <div class="fr-pages-head">
        <span class="lbl" style="margin:0">Pages concernées</span>
        <button class="btn btn-ghost btn-sm" id="fr-all" type="button">Tout cocher / décocher</button>
      </div>
      <div class="fr-pages" id="fr-pages"></div>
      <div class="row"><button class="btn btn-ghost" id="fr-check">🔍 Vérifier</button><button class="btn btn-green" id="fr-apply" style="display:none">Remplacer dans les pages cochées</button><span class="small muted" id="fr-status"></span></div>
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
      <h2><?= $gateway ? 'Budget d\'édition (mois en cours)' : 'Consommation (aujourd\'hui)' ?></h2>
      <p class="muted small" id="usage-line">…</p>
    </div>
    <?php if(!$managed && !$gateway): ?>
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
let pageLabels={}; function labelOf(f){return pageLabels[f]||f;}
const PROVS=<?=json_encode(array_map(fn($p)=>['id'=>$p['id'],'key_url'=>$p['key_url']??'','free'=>!empty($p['free'])],$providers), JSON_UNESCAPED_UNICODE)?>;
const HTML_PAGES=<?=json_encode($html_pages, JSON_UNESCAPED_UNICODE)?>;
async function api(action,data,isForm,timeoutMs){const opt={method:'POST'};
  if(isForm){data.append('action',action);opt.body=data;}else{const b=new URLSearchParams(data||{});b.set('action',action);opt.body=b;opt.headers={'Content-Type':'application/x-www-form-urlencoded'};}
  // Filet anti-blocage : si le réseau cale, on abandonne au bout de timeoutMs plutôt que de tourner à
  // l'infini. Par défaut aucun timeout (les propositions IA peuvent légitimement durer ~2 min).
  let tid=null; if(timeoutMs){const ac=new AbortController();opt.signal=ac.signal;tid=setTimeout(()=>ac.abort(),timeoutMs);}
  try{const r=await fetch('api.php',opt); if(r.status===401){location.reload();throw new Error('auth');} return r.json();}
  catch(e){ if(e&&e.name==='AbortError') throw new Error('timeout'); throw e; }
  finally{ if(tid) clearTimeout(tid); }}

/* ---- navigation par onglets ---- */
$$('.topnav a[data-view]').forEach(a=>a.onclick=()=>{
  $$('.topnav a[data-view]').forEach(x=>x.classList.remove('active')); a.classList.add('active');
  const v=a.dataset.view; $$('section[data-section]').forEach(s=>s.hidden=(s.dataset.section!==v));
  if(v==='history') loadHistory();
});

async function refresh(){try{const s=await api('status');
  if($('#ver'))$('#ver').textContent=s.version;
  if(s.gateway){ /* passerelle : jauge budget mensuelle (% en gros, € en petit) */
    const b=s.budget, eur=v=>(Math.round(v*100)/100).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}),
      fr=d=>(d||'').split('-').reverse().join('/');
    if($('#quota')){const q=$('#quota');
      if(b){q.textContent='📊 '+b.pct_used+'%';q.title='Budget d\'édition du mois : '+eur(b.spent_eur)+' € utilisés sur '+eur(b.budget_eur+b.credit_eur)+' € · remis à zéro le '+fr(b.resets_on);}
      else{q.textContent='📊 —';q.title='Jauge budget momentanément indisponible';}}
    if($('#usage-line')){
      if(b){$('#usage-line').innerHTML='<span style="font-size:1.6rem;font-weight:700;color:var(--ink)">'+b.pct_used+'&nbsp;%</span> du budget mensuel utilisé <span class="muted small">('+eur(b.spent_eur)+' € sur '+eur(b.budget_eur+b.credit_eur)+' €'+(b.credit_eur>0?', dont '+eur(b.credit_eur)+' € de recharge':'')+')</span><br><span class="muted">'+b.requests_month+' demande'+(b.requests_month>1?'s':'')+' ce mois-ci · budget remis à zéro le '+fr(b.resets_on)+' · besoin de plus&nbsp;? contactez votre prestataire.</span>';}
      else{$('#usage-line').innerHTML='<span class="muted">Jauge budget momentanément indisponible — l\'édition reste possible.</span>';}}
    return;}
  const _p=s.providers.find(x=>x.id===s.selected);
  if($('#quota')){const pct=s.cap?Math.round(s.calls_today/s.cap*100):0;const q=$('#quota');q.textContent='📊 '+pct+'%';q.title=s.calls_today+'/'+s.cap+' requêtes aujourd\'hui · ~'+(s.tokens_today||0).toLocaleString('fr-FR')+' tokens'+(_p&&_p.free?' · fournisseur gratuit':'');}
  const pp=s.providers.find(x=>x.id===s.selected);
  if($('#prov-status'))$('#prov-status').textContent=(pp&&pp.has_key)?'clé en place ✔':'⚠️ aucune clé pour ce fournisseur';
  const ms=$('#mode-sel');
  if(ms){const keyed=s.providers.filter(p=>p.has_key);
    if(keyed.length){ms.innerHTML=keyed.map(p=>'<option value="'+p.id+'"'+(p.id===s.selected?' selected':'')+'>'+(p.free?'⚡ Rapide':'✨ Soigné')+' — '+escapeHtml((p.label||'').replace(/ —.*$/,''))+'</option>').join('');ms.disabled=false;}
    else{ms.innerHTML='<option>⚙️ Clé à configurer (Réglages)</option>';ms.disabled=true;}}
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
  const page=$('#ai-page')?$('#ai-page').value:'';
  if($('#ai-page')&&page===''){$('#propose-status').innerHTML='<span class="err">Choisissez d\'abord la page à modifier.</span>';return;}
  $('#btn-propose').disabled=true;$('#proposal').hidden=true;
  const t0=Date.now();const tick=()=>{const s=Math.round((Date.now()-t0)/1000);if($('#propose-status'))$('#propose-status').innerHTML='<span class="spinner"></span> L\'assistant travaille… '+s+' s'+(s>=20?' <span class="muted">(certains modèles prennent 1 à 2 min)</span>':'');};tick();const iv=setInterval(tick,1000);
  try{const r=await api('propose',{request:req,page});clearInterval(iv);
    if(!r.ok){$('#propose-status').innerHTML='<span class="err">'+(r.error==='needs_key'?'Aucune clé pour le fournisseur (onglet Réglages).':(r.error||'Erreur'))+'</span>';}
    else{token=r.token;$('#prop-summary').textContent=r.summary;
      if(r.global){
        $('#prop-files').innerHTML=r.changes.length?'<p class="small muted">🌐 Cette modification s\'appliquera à <b>'+r.targets+' page'+(r.targets>1?'s':'')+'</b> du site en une fois'+(r.skipped?' <span class="muted">('+r.skipped+' page(s) avec une mise en page différente ne seront pas touchées)</span>':'')+'. Aperçu ci-contre.</p>':'<p class="small muted">Aucun changement à appliquer (voir l\'explication).</p>';
      } else {
        $('#prop-files').innerHTML=r.changes.length?'<p class="small muted">Pages modifiées :</p><ul class="files">'+r.changes.map(c=>'<li><span class="pill">'+escapeHtml(labelOf(c.path))+'</span></li>').join('')+'</ul>':'<p class="small muted">Aucune page à modifier (voir l\'explication).</p>';
      }
      $('#btn-apply').style.display=r.changes.length?'':'none';
      // aperçu : page modifiée prioritaire, sinon page choisie parmi toutes
      const changedHtml=r.changes.map(c=>c.path).filter(p=>p.endsWith('.html'));
      const opts=(changedHtml.length?changedHtml:HTML_PAGES);
      $('#prev-page').innerHTML=opts.map(p=>'<option value="'+escapeHtml(p)+'">'+escapeHtml(labelOf(p))+'</option>').join('');
      setPreview();
      $('#proposal').hidden=false;
      $('#propose-status').innerHTML='<span class="small muted">'+r.provider+' · '+(r.tokens.in+r.tokens.out)+' tokens</span>';refresh();}
  }catch(e){clearInterval(iv);if(e.message!=='auth')$('#propose-status').innerHTML='<span class="err">Erreur réseau</span>';}
  $('#btn-propose').disabled=false;};

/* ---- Chercher / Remplacer (déterministe, sans IA) ---- */
let frTotal=0;
function frPages(){return [...$$('.fr-pg')].filter(b=>b.checked).map(b=>b.value);}
function frHideApply(){if($('#fr-apply'))$('#fr-apply').style.display='none';}
function frBoxes(pgs){return pgs.map(p=>'<label><input type="checkbox" class="fr-pg" value="'+escapeHtml(p.file)+'" checked>'+escapeHtml(p.label)+'</label>').join('');}
async function loadPages(){let pgs=HTML_PAGES.map(f=>({file:f,label:f}));
  try{const r=await api('list_pages');if(r&&r.ok&&r.pages&&r.pages.length)pgs=r.pages.map(p=>typeof p==='string'?{file:p,label:p}:{file:p.file,label:p.label||p.file,global:!!p.global});}catch(e){}
  pageLabels={};pgs.forEach(p=>pageLabels[p.file]=p.label);
  const reals=pgs.filter(p=>!p.global), globals=pgs.filter(p=>p.global);
  // Chercher/Remplacer : uniquement de vraies pages (pas les sections globales).
  if($('#fr-pages'))$('#fr-pages').innerHTML=frBoxes(reals);
  // « Page à modifier » : sections globales en tête, puis les pages.
  if($('#ai-page')){let h='<option value="">— Choisir une page —</option>';
    if(globals.length){h+='<optgroup label="Tout le site">'+globals.map(p=>'<option value="'+escapeHtml(p.file)+'">'+escapeHtml(p.label)+'</option>').join('')+'</optgroup><optgroup label="Pages">';}
    h+=reals.map(p=>'<option value="'+escapeHtml(p.file)+'">'+escapeHtml(p.label)+'</option>').join('');
    if(globals.length)h+='</optgroup>';
    $('#ai-page').innerHTML=h;}}
loadPages();
loadUploads();
if($('#fr-pages'))$('#fr-pages').addEventListener('change',frHideApply);
['fr-find','fr-repl'].forEach(id=>{const el=$('#'+id);if(el)el.addEventListener('input',frHideApply);});
if($('#fr-all'))$('#fr-all').onclick=()=>{const b=$$('.fr-pg');const on=[...b].some(x=>!x.checked);b.forEach(x=>x.checked=on);frHideApply();};
if($('#fr-check'))$('#fr-check').onclick=async()=>{const find=$('#fr-find').value,pages=frPages();
  if(!find){$('#fr-status').innerHTML='<span class="err">Indiquez le texte à chercher.</span>';return;}
  if(!pages.length){$('#fr-status').innerHTML='<span class="err">Cochez au moins une page.</span>';return;}
  $('#fr-status').innerHTML='<span class="spinner"></span>';frHideApply();
  try{const r=await api('replace',{find,replace:$('#fr-repl').value,mode:'preview',pages:JSON.stringify(pages)});
    if(!r.ok){$('#fr-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>';return;}
    frTotal=r.total;
    if(!r.total){$('#fr-status').innerHTML='<span class="muted">Introuvable dans les pages cochées.</span>';}
    else{$('#fr-status').innerHTML='<b>'+r.total+'</b> occurrence'+(r.total>1?'s':'')+' dans '+r.files.length+' page'+(r.files.length>1?'s':'')+' : '+r.files.map(f=>escapeHtml(f.path)+' ('+f.count+')').join(', ');$('#fr-apply').style.display='';}
  }catch(e){if(e.message!=='auth')$('#fr-status').innerHTML='<span class="err">Erreur réseau</span>';}};
if($('#fr-apply'))$('#fr-apply').onclick=async()=>{const find=$('#fr-find').value,pages=frPages();if(!find||!pages.length)return;
  if(!confirm('Remplacer '+frTotal+' occurrence(s) dans '+pages.length+' page(s) cochée(s) ?\n(réversible via l\'historique)'))return;
  $('#fr-apply').disabled=true;$('#fr-status').innerHTML='<span class="spinner"></span> remplacement…';
  try{const r=await api('replace',{find,replace:$('#fr-repl').value,mode:'apply',pages:JSON.stringify(pages)});
    if(r.ok){$('#edit-status').innerHTML='<div class="banner"><span class="ok">✔ '+r.total+' remplacement(s) publié(s) sur '+r.written.length+' page(s).</span> <a class="btn btn-ghost" href="/" target="_blank" rel="noopener">👁 Vérifier sur le site</a></div>';$('#fr-status').textContent='';frHideApply();$('#fr-find').value='';$('#fr-repl').value='';window.scrollTo({top:0,behavior:'smooth'});refresh();}
    else $('#fr-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>';
  }catch(e){if(e.message!=='auth')$('#fr-status').innerHTML='<span class="err">Erreur réseau</span>';}
  $('#fr-apply').disabled=false;};
function setPreview(){ if(!token)return; const pg=$('#prev-page').value||'index.html';
  $('#prev-frame').src='preview.php?token='+token+'&path='+encodeURIComponent(pg)+'&t='+Date.now(); }
$('#prev-page').onchange=setPreview;
$('#btn-cancel').onclick=()=>{$('#proposal').hidden=true;token=null;$('#prev-frame').src='about:blank';};
$('#btn-apply').onclick=async()=>{if(!token)return;$('#btn-apply').disabled=true;$('#apply-status').textContent='Publication…';
  const r=await api('apply',{token});
  if(r.ok){$('#edit-status').innerHTML='<div class="banner"><span class="ok">✔ Modification publiée.</span> <a class="btn btn-ghost" href="/" target="_blank" rel="noopener">👁 Vérifier sur le site</a> <span class="small muted">(un rechargement normal suffit)</span></div>';$('#request').value='';$('#proposal').hidden=true;$('#prev-frame').src='about:blank';token=null;window.scrollTo({top:0,behavior:'smooth'});refresh();}
  else $('#apply-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>';
  $('#btn-apply').disabled=false;};

/* ---- Fichiers (trombone) + dictée + suggestions + mode ---- */
$('#btn-attach').onclick=()=>$('#image').click();
$('#image').onchange=async()=>{const f=$('#image').files[0];if(!f)return;await doUpload(f);$('#image').value='';};
async function doUpload(f){$('#upload-status').innerHTML='<span class="spinner"></span> envoi…';
  try{
    const fd=new FormData();fd.append('image',f); const r=await api('upload',fd,true,90000);
    if(r.ok){$('#upload-status').innerHTML='<span class="ok">Ajouté : <code>'+escapeHtml(baseName(r.filename))+'</code> — cliquez-le ci-dessous pour l\'insérer dans votre demande.</span>';loadUploads();}
    else $('#upload-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>';
  }catch(e){ $('#upload-status').innerHTML='<span class="err">'+(e&&e.message==='timeout'
      ?'Envoi interrompu (connexion trop lente ou coupée). Réessayez.'
      :'Envoi impossible pour le moment. Vérifiez votre connexion et réessayez.')+'</span>'; }}
/* ---- Galerie des fichiers envoyés : insérer au clic + supprimer ---- */
function baseName(p){return String(p||'').split('/').pop();}
function insertRef(ref){const t=$('#request');if(!t)return;const a=t.selectionStart??t.value.length,b=t.selectionEnd??t.value.length;
  const before=t.value.slice(0,a),after=t.value.slice(b);
  const p1=(before&&!/\s$/.test(before))?' ':'',p2=(after&&!/^\s/.test(after))?' ':'';
  t.value=before+p1+ref+p2+after;const pos=(before+p1+ref).length;t.focus();t.setSelectionRange(pos,pos);}
async function loadUploads(){const box=$('#uploads');if(!box)return;
  try{const r=await api('list_uploads');const files=(r&&r.files)||[];
    if(!files.length){box.hidden=true;box.innerHTML='';return;}
    box.hidden=false;
    const IMG_EXT=['jpg','jpeg','png','webp','gif'];
    box.innerHTML='<span class="up-lbl">Fichiers envoyés (cliquez le nom pour l\'insérer, × pour supprimer) :</span>'+
      files.map(f=>{const isImg=IMG_EXT.indexOf(baseName(f).split('.').pop().toLowerCase())>=0;
        const prev=isImg?'<img class="up-prev" src="/'+escapeHtml(f)+'" alt="" loading="lazy">':'';
        return '<span class="up"><button type="button" class="up-name" data-ref="'+escapeHtml(f)+'" title="Insérer dans la demande">'+escapeHtml(baseName(f))+'</button>'+prev+'<button type="button" class="up-del" data-fn="'+escapeHtml(f)+'" title="Supprimer ce fichier">×</button></span>';}).join('');
    box.querySelectorAll('.up-name').forEach(b=>b.onclick=()=>insertRef(b.dataset.ref));
    box.querySelectorAll('.up-del').forEach(b=>b.onclick=()=>deleteUpload(b.dataset.fn,b));
  }catch(e){box.hidden=true;}}
async function deleteUpload(fn,btn){if(!confirm('Supprimer « '+baseName(fn)+' » ? Ce fichier ne sera plus disponible sur le site.'))return;
  if(btn){btn.disabled=true;btn.textContent='…';}
  try{const r=await api('delete_image',{filename:fn});
    if(r&&r.ok){loadUploads();}else{alert('Suppression impossible : '+((r&&r.error)||''));if(btn){btn.disabled=false;btn.textContent='×';}}}
  catch(e){alert('Suppression impossible.');if(btn){btn.disabled=false;btn.textContent='×';}}}
/* suggestions cliquables : remplit la zone et place le curseur sur le premier « … » */
$$('#chips .chip').forEach(c=>c.onclick=()=>{const t=$('#request');const tpl=c.dataset.tpl;
  t.value=(t.value.trim()?t.value.trim()+'\n':'')+tpl;t.focus();
  const i=t.value.indexOf('…');if(i>=0)t.setSelectionRange(i,i+1);});
/* Ctrl/Cmd + Entrée = préparer la modification */
$('#request').addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();$('#btn-propose').click();}});
/* choix Rapide/Soigné = fournisseur (libellé simplifié), synchronisé avec Réglages */
if($('#mode-sel'))$('#mode-sel').onchange=async()=>{if($('#mode-sel').disabled)return;await api('set_provider',{provider:$('#mode-sel').value});refresh();};
/* dictée vocale — API du navigateur, 100% local ; le micro reste masqué si non supporté */
(function(){const SR=window.SpeechRecognition||window.webkitSpeechRecognition;const mic=$('#btn-mic');if(!SR||!mic)return;
  mic.hidden=false;let rec=null,on=false,base='';
  mic.onclick=()=>{if(on){rec&&rec.stop();return;}
    rec=new SR();rec.lang='fr-FR';rec.interimResults=true;rec.continuous=true;
    base=$('#request').value;if(base&&!/\s$/.test(base))base+=' ';
    rec.onstart=()=>{on=true;mic.classList.add('rec');mic.title='Arrêter la dictée';};
    rec.onend=()=>{on=false;mic.classList.remove('rec');mic.title='Dicter à la voix';};
    rec.onerror=()=>{};
    rec.onresult=(e)=>{let txt='';for(let i=0;i<e.results.length;i++)txt+=e.results[i][0].transcript;$('#request').value=base+txt;};
    try{rec.start();}catch(_){}};
})();
$('#request').addEventListener('paste',async(e)=>{
  const items=(e.clipboardData&&e.clipboardData.items)?e.clipboardData.items:[];
  for(const it of items){ if(it.type&&it.type.indexOf('image')===0){ const blob=it.getAsFile(); if(!blob)continue; e.preventDefault();
    $('#upload-status').innerHTML='<span class="spinner"></span> collage…';
    try{
      const fd=new FormData();fd.append('image',blob,'collage.png'); const r=await api('upload',fd,true,90000);
      if(r.ok){insertRef(r.filename);$('#upload-status').innerHTML='<span class="ok">Image collée et insérée : <code>'+escapeHtml(baseName(r.filename))+'</code></span>';loadUploads();}
      else $('#upload-status').innerHTML='<span class="err">'+(r.error||'Erreur')+'</span>';
    }catch(e){ $('#upload-status').innerHTML='<span class="err">'+(e&&e.message==='timeout'
        ?'Collage interrompu (connexion trop lente ou coupée). Réessayez.'
        :'Collage impossible pour le moment. Réessayez.')+'</span>'; } }}
});

/* ---- Historique ---- */
async function loadHistory(){const el=$('#hist-list');el.innerHTML='<li class="muted small">Chargement…</li>';
  try{const r=await api('history');
    if(!r.ok||!r.entries.length){el.innerHTML='<li class="muted small">Aucune modification enregistrée pour l\'instant.</li>';return;}
    el.innerHTML=r.entries.map(e=>'<li><div><div>'+escapeHtml(e.summary)+'</div><div class="when">'+e.date+(e.changed&&e.changed.length?' · '+e.changed.map(f=>escapeHtml(labelOf(f))).join(', '):'')+'</div></div><button class="btn btn-ghost btn-restore" data-id="'+e.id+'">↩︎ Revenir à avant</button></li>').join('');
    $$('.btn-restore').forEach(b=>b.onclick=async()=>{ if(!confirm('Revenir à l\'état d\'avant cette modification ?'))return;
      b.disabled=true;b.textContent='…'; const r=await api('restore',{id:b.dataset.id});
      if(r.ok){alert('Restauré ✔');loadHistory();}else{alert('Erreur : '+(r.error||''));b.disabled=false;}});
  }catch(e){el.innerHTML='<li class="err small">Erreur de chargement.</li>';}}
function escapeHtml(s){return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
</script>
<?php endif; ?>
</body>
</html>
