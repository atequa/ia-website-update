<?php
/** Back-office V3 — backend (auth magic link, multi-fournisseurs, auto-update). */
declare(strict_types=1);
require '/home/bafo9702/private/bo_auth.php';
require '/home/bafo9702/private/bo_llm.php';
require '/home/bafo9702/private/bo_control.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function fail($c,$m){ http_response_code($c); out(['ok'=>false,'error'=>$m]); }

@mkdir(BO_HISTORY, 0700, true);
@mkdir(BO_PROPOSALS, 0700, true);

function bo_newid(): string { return date('Ymd-His').'-'.bin2hex(random_bytes(2)); }
function bo_snapshot_all(string $id): void {
    $d = BO_HISTORY.'/'.$id; @mkdir($d, 0700, true);
    foreach (BO_EDITABLE as $n) { $p = BO_DOCROOT.'/'.$n; if (is_file($p)) copy($p, $d.'/'.$n); }
}
function bo_history_add(string $id, string $summary, array $changed): void {
    $h = bo_json_read(BO_HISTORY_FILE);
    array_unshift($h, ['id'=>$id, 'date'=>date('Y-m-d H:i'), 'summary'=>$summary, 'changed'=>$changed]);
    while (count($h) > BO_HISTORY_KEEP) {
        $old = array_pop($h); $d = BO_HISTORY.'/'.($old['id'] ?? '');
        if ($old && is_dir($d)) { foreach (glob($d.'/*') as $f) @unlink($f); @rmdir($d); }
    }
    bo_json_write(BO_HISTORY_FILE, $h);
}
function bo_restore_snapshot(string $id): array {
    $d = BO_HISTORY.'/'.$id; $restored = [];
    foreach (glob($d.'/*') as $bf) { $n = basename($bf); $p = editable_path($n); if ($p) { copy($bf, $p); $restored[] = $n; } }
    return $restored;
}

const BO_RULES =
"Tu es l'assistant d'édition d'un site web statique (HTML/CSS/JS pur, pas de framework). " .
"On te donne le contenu actuel de tous les fichiers éditables, puis une demande en français. RÈGLES STRICTES :\n" .
"- Ne modifie QUE ce que la demande implique. Ne refais pas la mise en page ; ne touche pas au SEO (title, meta, canonical, JSON-LD) sauf demande explicite.\n" .
"- Conserve structure HTML, classes CSS, header/footer/menu identiques sur TOUTES les pages (si tu changes le header, applique-le partout).\n" .
"- Le formulaire de contact poste vers /contact.php — n'y touche pas.\n" .
"- Pour chaque fichier modifié, renvoie son CONTENU COMPLET réécrit (jamais un extrait/diff). Ne renvoie QUE les fichiers réellement modifiés.\n" .
"- Si la demande est impossible/dangereuse, explique-le dans 'summary' et renvoie 'changes' vide.";

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---- Public ---- */
if ($action === 'login_request') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!bo_throttle('login_'.($_SERVER['REMOTE_ADDR'] ?? '0'), 8)) fail(429, "Merci de patienter quelques secondes.");
    $resp = ['ok'=>true, 'message'=>"Si cet email est autorisé, un lien de connexion vient d'être envoyé (vérifiez aussi les spams)."];
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && bo_email_authorized($email)) {
        $url = bo_magic_url(bo_create_magic($email));
        bo_send_magic_link($email, $url);
        if (BO_DEV_SHOW_LINK) $resp['dev_link'] = $url;
    }
    out($resp);
}

/* ---- Auth requise ---- */
$user = bo_current_user();
if (!$user) fail(401, 'Session expirée. Reconnectez-vous.');

if ($action === 'logout') { bo_logout(); out(['ok'=>true]); }

$ctrl = bo_control_state();
if (in_array($action,['propose','apply','undo','restore','upload','delete_image','update','set_key','set_provider'],true) && !$ctrl['enabled'])
    fail(403, $ctrl['message'] !== '' ? $ctrl['message'] : "Accès suspendu. Contactez votre prestataire.");
if (in_array($action,['set_key','set_provider'],true) && $ctrl['mode']==='managed')
    fail(403, "Le fournisseur et la clé sont gérés par votre prestataire.");

function usage_today(): array {
    $d = bo_json_read(BO_SPENDLOG); $e = $d[date('Y-m-d')] ?? [];
    if (!is_array($e)) $e = ['calls'=>(int)$e];
    return ['calls'=>(int)($e['calls']??0), 'in'=>(int)($e['in']??0), 'out'=>(int)($e['out']??0)];
}
function usage_record(int $in=0, int $out=0, int $calls=0): void {
    $d = bo_json_read(BO_SPENDLOG); $u = usage_today();
    $d[date('Y-m-d')] = ['calls'=>$u['calls']+$calls, 'in'=>$u['in']+$in, 'out'=>$u['out']+$out];
    bo_json_write(BO_SPENDLOG, $d);
}

if ($action === 'status') {
    $sel = bo_selected_id();
    $provs = array_map(fn($p)=>[
        'id'=>$p['id'],'label'=>$p['label'],'free'=>!empty($p['free']),
        'key_url'=>$p['key_url'] ?? '', 'model'=>$p['model'] ?? '',
        'free_note'=>$p['free_note'] ?? '',
        'has_key'=>bo_get_key($p['id'])!=='',
    ], bo_providers());
    $u = usage_today();
    $ver = is_file(BO_VERSION_FILE) ? (json_decode((string)file_get_contents(BO_VERSION_FILE),true)['version'] ?? '?') : 'local';
    out(['ok'=>true,'email'=>$user,'providers'=>$provs,'selected'=>$sel,
         'configured'=>bo_is_configured(),'calls_today'=>$u['calls'],'tokens_today'=>$u['in']+$u['out'],'cap'=>BO_DAILY_CALLS,
         'has_history'=>!empty(bo_json_read(BO_HISTORY_FILE)),'version'=>$ver,
         'enabled'=>$ctrl['enabled'],'mode'=>$ctrl['mode'],'message'=>$ctrl['message']]);
}

if ($action === 'set_provider') {
    $pid = (string)($_POST['provider'] ?? '');
    if (!bo_provider($pid)) fail(422, "Fournisseur inconnu.");
    bo_set_provider($pid);
    out(['ok'=>true]);
}

if ($action === 'set_key') {
    $pid = (string)($_POST['provider'] ?? bo_selected_id());
    $p = bo_provider($pid); if (!$p) fail(422, "Fournisseur inconnu.");
    $key = trim((string)($_POST['key'] ?? ''));
    $pref = (string)($p['key_prefix'] ?? '');
    if (strlen($key) < 8 || ($pref!=='' && strpos($key,$pref)!==0)) fail(422, "Clé invalide".($pref?" (doit commencer par $pref)":"").".");
    if (!bo_set_key($pid, $key)) fail(500, "Impossible d'enregistrer la clé.");
    bo_set_provider($pid);
    out(['ok'=>true]);
}

if ($action === 'update') {
    require '/home/bafo9702/private/bo_updater.php';
    out(bo_run_update());
}

/* ---- Édition ---- */
function editable_path(string $name): ?string {
    if (!in_array($name, BO_EDITABLE, true)) return null;
    $p = BO_DOCROOT.'/'.$name; $real=realpath(BO_DOCROOT); $rp=realpath(dirname($p));
    if ($real===false || $rp===false || strpos($rp,$real)!==0) return null;
    return $p;
}
function read_site_files(): array {
    $f=[]; foreach (BO_EDITABLE as $n){ $p=BO_DOCROOT.'/'.$n; if (is_file($p)) $f[$n]=file_get_contents($p); } return $f;
}

if ($action === 'propose') {
    $req = trim((string)($_POST['request'] ?? ''));
    if ($req==='') fail(422, "Demande vide.");
    if (mb_strlen($req) > 4000) fail(422, "Demande trop longue.");
    if (!bo_is_configured()) fail(409, "needs_key");
    if (usage_today()['calls'] >= BO_DAILY_CALLS) fail(429, "Plafond de ".BO_DAILY_CALLS." requêtes/jour atteint. Réessayez demain.");

    $pid = bo_selected_id(); $p = bo_provider($pid); $key = bo_get_key($pid);
    if (!$p) fail(409, "needs_key");
    $files = read_site_files();
    $corpus=''; foreach ($files as $n=>$c) $corpus .= "\n===== FICHIER: $n =====\n".$c."\n";

    usage_record(0, 0, 1);                 // compte la requête
    $r = bo_llm_edit($p, $key, BO_RULES, $corpus, $req);
    if (!$r['ok']) fail(502, $r['error']);
    usage_record((int)($r['in']??0), (int)($r['out']??0), 0);   // ajoute les tokens consommés
    $parsed = $r['parsed'];
    if (!is_array($parsed) || !isset($parsed['summary'])) fail(502, "Réponse du fournisseur illisible. Réessayez ou changez de fournisseur.");

    $changes=[];
    foreach (($parsed['changes'] ?? []) as $c) {
        $name = basename((string)($c['path'] ?? ''));
        if (editable_path($name)===null) continue;
        $old = $files[$name] ?? ''; $new=(string)($c['new_content'] ?? '');
        if ($new===''||$new===$old) continue;
        $changes[] = ['path'=>$name,'new_content'=>$new,'old_len'=>strlen($old),'new_len'=>strlen($new)];
    }
    $token = bin2hex(random_bytes(8));
    file_put_contents(BO_PROPOSALS.'/'.$token.'.json', json_encode(['changes'=>$changes,'summary'=>$parsed['summary']], JSON_UNESCAPED_UNICODE), LOCK_EX);
    out(['ok'=>true,'token'=>$token,'summary'=>$parsed['summary'],
         'changes'=>array_map(fn($c)=>['path'=>$c['path'],'old_len'=>$c['old_len'],'new_len'=>$c['new_len']], $changes),
         'tokens'=>['in'=>$r['in']??0,'out'=>$r['out']??0],'provider'=>$p['label']]);
}

if ($action === 'apply') {
    $token = preg_replace('/[^a-f0-9]/','',(string)($_POST['token'] ?? ''));
    $pf = BO_PROPOSALS.'/'.$token.'.json';
    if ($token===''||!is_file($pf)) fail(404, "Proposition introuvable ou expirée.");
    $prop = json_decode((string)file_get_contents($pf), true);
    $changes = $prop['changes'] ?? []; $summary = trim((string)($prop['summary'] ?? '')) ?: 'Modification';
    if (!$changes) fail(422, "Rien à appliquer.");
    $id = bo_newid(); bo_snapshot_all($id);                 // snapshot complet AVANT la modif
    $written=[]; foreach ($changes as $c){ $p=editable_path($c['path']); if ($p){ file_put_contents($p,$c['new_content'],LOCK_EX); $written[]=$c['path']; } }
    // Cache-busting : si un CSS/JS a changé, on incrémente ?v= dans tous les HTML (force le rechargement navigateur).
    if (preg_grep('/\.(css|js)$/', $written)) {
        $v = date('YmdHis');
        foreach (BO_EDITABLE as $n) {
            if (substr($n,-5) !== '.html') continue;
            $hp = BO_DOCROOT.'/'.$n; if (!is_file($hp)) continue;
            $html = file_get_contents($hp);
            $new = preg_replace('/(\.(?:css|js))\?v=[0-9A-Za-z._-]*/', '$1?v='.$v, $html);
            if ($new !== null && $new !== $html) file_put_contents($hp, $new, LOCK_EX);
        }
    }
    bo_history_add($id, $summary, $written);
    @unlink($pf);
    out(['ok'=>true,'written'=>$written]);
}

if ($action === 'history') {
    out(['ok'=>true,'entries'=>bo_json_read(BO_HISTORY_FILE)]);
}

if ($action === 'restore') {
    $id = preg_replace('/[^0-9A-Za-z_-]/','',(string)($_POST['id'] ?? ''));
    $h = bo_json_read(BO_HISTORY_FILE); $entry=null; foreach($h as $e) if(($e['id']??'')===$id){$entry=$e;break;}
    if ($id===''||!$entry||!is_dir(BO_HISTORY.'/'.$id)) fail(404, "Version introuvable.");
    $cur=bo_newid(); bo_snapshot_all($cur);                 // l'état actuel reste restaurable
    $restored = bo_restore_snapshot($id);
    bo_history_add($cur, "↩︎ Retour à l'état d'avant « ".mb_substr((string)$entry['summary'],0,90)." »", $restored);
    out(['ok'=>true,'restored'=>$restored]);
}

if ($action === 'undo') {
    $h = bo_json_read(BO_HISTORY_FILE);
    if (!$h || !is_dir(BO_HISTORY.'/'.$h[0]['id'])) fail(404, "Aucune modification à annuler.");
    $cur=bo_newid(); bo_snapshot_all($cur);
    $restored = bo_restore_snapshot($h[0]['id']);
    bo_history_add($cur, "↩︎ Annulation de « ".mb_substr((string)$h[0]['summary'],0,90)." »", $restored);
    out(['ok'=>true,'restored'=>$restored]);
}

if ($action === 'upload') {
    if (empty($_FILES['image']) || $_FILES['image']['error']!==UPLOAD_ERR_OK) fail(422, "Aucun fichier reçu.");
    $f=$_FILES['image'];
    if ($f['size'] > 6*1024*1024) fail(422, "Image trop lourde (max 6 Mo).");
    $info=@getimagesize($f['tmp_name']); if ($info===false) fail(422, "Fichier non reconnu comme image.");
    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/svg+xml'=>'svg','image/gif'=>'gif'][$info['mime']??'']??null;
    if ($ext===null) fail(422, "Format non autorisé (jpg/png/webp/svg/gif).");
    $base=preg_replace('/[^a-zA-Z0-9_-]/','-', pathinfo($f['name'], PATHINFO_FILENAME));
    $base=trim(substr($base,0,40),'-') ?: 'image';
    $dest=BO_DOCROOT.'/assets/'.$base.'.'.$ext; $i=1;
    while (is_file($dest)){ $dest=BO_DOCROOT.'/assets/'.$base.'-'.($i++).'.'.$ext; }
    if (!move_uploaded_file($f['tmp_name'],$dest)) fail(500, "Échec de l'enregistrement.");
    @chmod($dest,0644);
    $fn = 'assets/'.basename($dest);
    $up = bo_json_read(BO_UPLOADS_FILE); if (!in_array($fn,$up,true)) { $up[]=$fn; bo_json_write(BO_UPLOADS_FILE,$up); }
    out(['ok'=>true,'filename'=>$fn]);
}

const BO_CORE_ASSETS = ['logo.svg','portrait.webp','portrait.jpg','portrait.png','og-image.jpg','og-image.png','favicon.ico','favicon-32.png','favicon-180.png'];
if ($action === 'list_uploads') {
    $up = bo_json_read(BO_UPLOADS_FILE);
    // inclure aussi les images présentes dans /assets non protégées (ex. uploads antérieurs au suivi)
    foreach (glob(BO_DOCROOT.'/assets/*') as $f) {
        if (!is_file($f)) continue; $b = basename($f);
        $ext = strtolower(pathinfo($b, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif','svg'], true)) continue;
        if (in_array($b, BO_CORE_ASSETS, true)) continue;
        $rel = 'assets/'.$b; if (!in_array($rel, $up, true)) $up[] = $rel;
    }
    $up = array_values(array_filter($up, fn($f)=>is_file(BO_DOCROOT.'/'.$f)));
    bo_json_write(BO_UPLOADS_FILE, $up);
    out(['ok'=>true,'files'=>$up]);
}

if ($action === 'delete_image') {
    $fn = (string)($_POST['filename'] ?? '');
    $up = bo_json_read(BO_UPLOADS_FILE);
    if (!in_array($fn,$up,true)) fail(404, "Image inconnue.");   // on ne supprime QUE des images téléversées ici
    $rp = realpath(BO_DOCROOT.'/'.$fn); $base = realpath(BO_DOCROOT.'/assets');
    if ($rp && $base && strpos($rp,$base)===0 && is_file($rp)) @unlink($rp);
    bo_json_write(BO_UPLOADS_FILE, array_values(array_diff($up,[$fn])));
    out(['ok'=>true]);
}

fail(400, "Action inconnue.");
