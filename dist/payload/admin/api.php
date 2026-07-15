<?php
/** Back-office V3 — backend (auth magic link, multi-fournisseurs, auto-update). */
declare(strict_types=1);
@ini_set('display_errors', '0');                 // jamais de détail d'erreur PHP renvoyé au client
require __DIR__ . '/bo_path.php';                 // définit BO_PRIVATE_DIR (généré par site, hors payload)
require BO_PRIVATE_DIR . '/bo_auth.php';
require BO_PRIVATE_DIR . '/bo_llm.php';
require BO_PRIVATE_DIR . '/bo_control.php';

// Repli : les configs de site générées avant l'ajout de l'upload d'images ne définissent pas
// BO_UPLOADS_FILE. Sans ce garde, y référer est une ERREUR FATALE en PHP 8 (constante indéfinie) →
// 500 sur upload/list_uploads/delete_image (l'upload d'image ne marchait sur AUCUN de ces sites).
if (!defined('BO_UPLOADS_FILE')) define('BO_UPLOADS_FILE', BO_PRIVATE . '/bo_uploads.json');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function fail($c,$m){ http_response_code($c); out(['ok'=>false,'error'=>$m]); }

// CSRF : une action modifiante doit provenir de la même origine que le site.
function bo_same_origin(): bool {
    $self = preg_replace('/:\d+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
    $src  = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($src === '') return true;                // ni Origin ni Referer : SameSite reste la défense
    $h = parse_url($src, PHP_URL_HOST);
    if (!is_string($h) || $h === '') return false;
    return strcasecmp(strtolower($h), $self) === 0;
}

@mkdir(BO_HISTORY, 0700, true);
@mkdir(BO_PROPOSALS, 0700, true);

function bo_newid(): string { return date('Ymd-His').'-'.bin2hex(random_bytes(2)); }
// Scan LIVE du docroot : toutes les pages .html réellement présentes (s'adapte aux pages ajoutées).
function bo_html_pages(): array {
    $out = [];
    foreach (glob(BO_DOCROOT.'/*.html') as $f) {
        if (!is_file($f)) continue;
        // Découplage : on exclut les pages auto-générées par le module « Performances »
        // (elles se signalent par un canonical vers /performances). Les éditer serait inutile
        // (le cron perf les réécrit) ; on ne code aucun nom de fichier en dur.
        $head = (string)@file_get_contents($f, false, null, 0, 2000);
        if (strpos($head, 'rel="canonical"') !== false && preg_match('~href="[^"]*/performances"~', $head)) continue;
        $out[] = basename($f);
    }
    sort($out);
    return $out;
}
// Nom LISIBLE d'une page pour le client (ex. index.html → « Accueil », sinon dérivé du <title>).
function bo_page_label(string $file): string {
    if ($file === 'index.html') return 'Accueil';
    // La 404 tire un <title> « Page introuvable » → trompeur dans la liste (l'utilisateur croit à un bug).
    // On la nomme explicitement pour que le client comprenne que c'est la page d'erreur du site.
    if ($file === '404.html') return 'Page 404 (erreur)';
    $head = (string)@file_get_contents(BO_DOCROOT.'/'.$file, false, null, 0, 2000);
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $head, $m)) {
        $t = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        // enlève le suffixe " · NomDuSite" / " - NomDuSite" (séparateur entouré d'espaces)
        $parts = preg_split('~\s+[·|–—-]\s+~u', $t);
        $t = trim($parts[0] ?? '');
        if ($t !== '') return mb_substr($t, 0, 40);
    }
    $n = str_replace('-', ' ', preg_replace('~\.html$~', '', $file));
    return function_exists('mb_convert_case') ? mb_convert_case($n, MB_CASE_TITLE, 'UTF-8') : ucfirst($n);
}
function bo_snapshot_all(string $id): void {
    $d = BO_HISTORY.'/'.$id; @mkdir($d, 0700, true);
    $names = BO_EDITABLE;
    foreach (bo_html_pages() as $n) if (!in_array($n, $names, true)) $names[] = $n; // inclut les pages ajoutées
    foreach ($names as $n) { $p = BO_DOCROOT.'/'.$n; if (is_file($p)) copy($p, $d.'/'.basename($n)); }
}
function bo_history_add(string $id, string $summary, array $changed, string $reqId = ''): void {
    $h = bo_json_read(BO_HISTORY_FILE);
    array_unshift($h, ['id'=>$id, 'date'=>date('Y-m-d H:i'), 'summary'=>$summary, 'changed'=>$changed, 'req_id'=>$reqId]);
    while (count($h) > BO_HISTORY_KEEP) {
        $old = array_pop($h); $d = BO_HISTORY.'/'.($old['id'] ?? '');
        if ($old && is_dir($d)) { foreach (glob($d.'/*') as $f) @unlink($f); @rmdir($d); }
    }
    bo_json_write(BO_HISTORY_FILE, $h);
    // IndexNow différé : signale au cron perf (perf_gen.php §8) qu'une modif a eu lieu → il re-notifiera
    // les moteurs au prochain passage (fichier vide = tout le sitemap). No-op si le site n'a pas d'indexnow_key.
    if ($changed) @file_put_contents(BO_PRIVATE.'/indexnow_pending', '', LOCK_EX);
}
/* Purge des brouillons de proposition > 24 h. Une proposition passée par la passerelle et JAMAIS
 * appliquée (le fichier serait sinon supprimé à l'apply/dismiss) = abandonnée → on remonte l'issue
 * 'abandoned' à la passerelle (boucle d'amélioration) avant de supprimer. Fire-and-forget. */
function bo_purge_stale_proposals(): void {
    foreach (glob(BO_PROPOSALS.'/*.json') as $o) {
        if (!is_file($o) || time()-filemtime($o) <= 86400) continue;
        $prop = json_decode((string)@file_get_contents($o), true);
        $rid = is_array($prop) ? (string)($prop['req_id'] ?? '') : '';
        if ($rid !== '' && !empty($prop['changes']) && function_exists('bo_gateway_outcome')) bo_gateway_outcome($rid, 'abandoned');
        @unlink($o);
    }
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
"- Certaines zones sont délimitées par des commentaires <!--TPL:NOM_START--> et <!--TPL:NOM_END--> : leur contenu est géré automatiquement par le site (avis clients, etc.). Ne modifie JAMAIS ce qui se trouve entre ces marqueurs et conserve les deux commentaires intacts.\n" .
"- Pour chaque fichier modifié, renvoie son CONTENU COMPLET réécrit (jamais un extrait/diff). Ne renvoie QUE les fichiers réellement modifiés.\n" .
"- Si la demande est impossible/dangereuse, explique-le dans 'summary' et renvoie 'changes' vide.";

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ---- Public ---- */
if ($action === 'login_request') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!bo_throttle('login_'.($_SERVER['REMOTE_ADDR'] ?? '0'), 8)) fail(429, "Merci de patienter quelques secondes.");
    $ok_addr = filter_var($email, FILTER_VALIDATE_EMAIL) && bo_email_authorized($email);
    $magic_url = '';
    if ($ok_addr) {
        $magic_url = bo_magic_url(bo_create_magic($email));
        // Copie PRIVÉE hors docroot, écrite AVANT l'envoi (récupérable par le prestataire via
        // cPanel/FTP même si le mail tarde/échoue). JAMAIS renvoyée au navigateur.
        @file_put_contents(BO_PRIVATE.'/bo_lastmagic.txt', date('c')." ".$email."\n".$magic_url."\n");
        @chmod(BO_PRIVATE.'/bo_lastmagic.txt', 0600);
    }
    $msg = ['ok'=>true, 'message'=>"Si cet email est autorisé, un lien de connexion vient d'être envoyé (vérifiez aussi les spams)."];
    // L'envoi du mail peut être lent (vérif d'expéditeur exim sur certains hébergeurs). On répond au
    // navigateur TOUT DE SUITE, puis on envoie en arrière-plan → le bouton ne reste jamais bloqué.
    if ($ok_addr && (function_exists('litespeed_finish_request') || function_exists('fastcgi_finish_request'))) {
        @ignore_user_abort(true); @set_time_limit(120);
        echo json_encode($msg, JSON_UNESCAPED_UNICODE);
        if (function_exists('litespeed_finish_request')) litespeed_finish_request(); else fastcgi_finish_request();
        @bo_send_magic_link($email, $magic_url);
        exit;
    }
    if ($ok_addr) @bo_send_magic_link($email, $magic_url);
    out($msg);
}

/* ---- Auth requise ---- */
$user = bo_current_user();
if (!$user) fail(401, 'Session expirée. Reconnectez-vous.');

// CSRF : toutes les actions modifiantes doivent venir de la même origine.
$BO_MUTATING = ['logout','set_provider','set_key','update','propose','apply','replace','restore','undo','upload','delete_image'];
if (in_array($action, $BO_MUTATING, true) && !bo_same_origin())
    fail(403, "Requête bloquée (origine invalide). Rechargez la page et réessayez.");

if ($action === 'logout') { bo_logout(); out(['ok'=>true]); }

$ctrl = bo_control_state();
if (in_array($action,['propose','apply','replace','undo','restore','upload','delete_image','update','set_key','set_provider'],true) && !$ctrl['enabled'])
    fail(403, $ctrl['message'] !== '' ? $ctrl['message'] : "Accès suspendu. Contactez votre prestataire.");
if (in_array($action,['set_key','set_provider'],true) && ($ctrl['mode']==='managed' || bo_gateway_enabled()))
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
    $gw = bo_gateway_enabled();
    $sel = $gw ? '' : bo_selected_id();
    $provs = $gw ? [] : array_map(fn($p)=>[
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
         'gateway'=>$gw,'budget'=>$gw ? bo_gateway_status() : null,   // jauge mensuelle € (null si passerelle injoignable)
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
    require BO_PRIVATE_DIR . '/bo_updater.php';
    out(bo_run_update());
}

if ($action === 'list_pages') {
    $out = [];
    // Sections globales en tête — seulement si le site porte réellement la région, et via la passerelle.
    if (bo_gateway_enabled()) {
        foreach (['__menu__', '__footer__'] as $g) {
            $tag = bo_global_tag($g);
            foreach (bo_html_pages() as $f) {
                if (bo_extract_region((string)file_get_contents(BO_DOCROOT.'/'.$f), $tag)) {
                    $out[] = ['file'=>$g, 'label'=>bo_global_label($g), 'global'=>true]; break;
                }
            }
        }
    }
    foreach (bo_html_pages() as $f) $out[] = ['file'=>$f, 'label'=>bo_page_label($f)];
    out(['ok'=>true, 'pages'=>$out]);
}

/* ---- Édition ---- */
function editable_path(string $name): ?string {
    // autorisé si listé dans BO_EDITABLE, OU si c'est une page .html réellement présente dans le docroot
    $ok = in_array($name, BO_EDITABLE, true) || (substr($name,-5)==='.html' && is_file(BO_DOCROOT.'/'.$name));
    if (!$ok) return null;
    $p = BO_DOCROOT.'/'.$name; $real=realpath(BO_DOCROOT); $rp=realpath(dirname($p));
    if ($real===false || $rp===false || strpos($rp,$real)!==0) return null;
    return $p;
}
function read_site_files(): array {
    $f=[]; foreach (BO_EDITABLE as $n){ $p=BO_DOCROOT.'/'.$n; if (is_file($p)) $f[$n]=file_get_contents($p); } return $f;
}

/* ---- Sections GLOBALES (menu / pied de page — communes à toutes les pages) ----
 * Le client édite le bloc UNE fois ; on propage le changement à toutes les pages qui portent
 * la même section. Bloc délimité par <header>…</header> (menu) ou <footer>…</footer> (pied). */
function bo_global_tag(string $page): ?string {
    // 'menu' = pseudo-région : détection robuste de la barre de navigation (voir bo_extract_region).
    $m = ['__menu__' => 'menu', '__footer__' => 'footer'];
    return $m[$page] ?? null;
}
function bo_global_label(string $page): string {
    return $page === '__menu__' ? 'Menu (tout le site)' : 'Pied de page (tout le site)';
}
// Extrait un bloc du HTML. Retourne [offset, longueur, contenu] ou null.
// Cas spécial 'menu' : la barre de navigation n'a pas la même balise selon les sites
// (educ-care = <header> contenant un <nav> ; panda = <nav> direct, son <header> étant le hero).
// Règle robuste : 1er <header> qui CONTIENT un <nav>, sinon le 1er <nav>.
function bo_extract_region(string $html, string $tag): ?array {
    if ($tag === 'menu') {
        $off = 0;
        while (preg_match('~<header\b[^>]*>.*?</header>~is', $html, $m, PREG_OFFSET_CAPTURE, $off)) {
            $blk = $m[0][0]; $pos = $m[0][1];
            if (stripos($blk, '<nav') !== false) return [$pos, strlen($blk), $blk];
            $off = $pos + strlen($blk);
        }
        if (preg_match('~<nav\b[^>]*>.*?</nav>~is', $html, $m, PREG_OFFSET_CAPTURE)) return [$m[0][1], strlen($m[0][0]), $m[0][0]];
        return null;
    }
    if ($tag === 'footer') {
        // Le footer du SITE est le DERNIER <footer> : un <footer> peut aussi servir d'attribution
        // de citation dans le contenu (ex. « <footer>— Julie, présidente</footer> » sous un témoignage).
        // On ne veut jamais éditer celui-là.
        if (preg_match_all('~<footer\b[^>]*>.*?</footer>~is', $html, $ms, PREG_OFFSET_CAPTURE)) {
            $m = end($ms[0]); return [$m[1], strlen($m[0]), $m[0]];
        }
        return null;
    }
    if (!preg_match('~<'.$tag.'\b[^>]*>.*?</'.$tag.'>~is', $html, $m, PREG_OFFSET_CAPTURE)) return null;
    return [$m[0][1], strlen($m[0][0]), $m[0][0]];
}
// Diff minimal entre deux blocs → {find, replace} avec juste assez de contexte pour être UNIQUE
// dans l'ancien bloc. null si le changement ne peut pas être isolé (l'appelant remplacera tout le bloc).
function bo_region_diff(string $old, string $new): ?array {
    if ($old === $new) return null;
    $ol = strlen($old); $nl = strlen($new); $mm = min($ol, $nl);
    $p = 0; while ($p < $mm && $old[$p] === $new[$p]) $p++;
    $s = 0; while ($s < ($ol - $p) && $s < ($nl - $p) && $old[$ol-1-$s] === $new[$nl-1-$s]) $s++;
    // Départ : au moins 24 car. de contexte de chaque côté (une insertion pure a un cœur vide → 'find'
    // serait vide sinon). On élargit tant que 'find' n'est pas UNIQUE dans l'ancien bloc.
    $fS = $p; $fE = $ol - $s; $rS = $p; $rE = $nl - $s; $ctx = 24;
    while (true) {
        $fs = max(0, $fS - $ctx); $fe = min($ol, $fE + $ctx);
        $find = substr($old, $fs, $fe - $fs);
        $replace = substr($new, max(0, $rS - $ctx), min($nl, $rE + $ctx) - max(0, $rS - $ctx));
        if ($find !== '' && substr_count($old, $find) === 1) return ['find' => $find, 'replace' => $replace, 'mode' => 'diff'];
        if (($fs === 0 && $fe === $ol) || $ctx > 6000) return null;
        $ctx += 24;
    }
    return null;
}
// MENU : neutralise l'état actif (aria-current="page") pour comparer/propager de façon uniforme
// d'une page à l'autre (chaque page marque un lien différent comme actif). Retourne
// [blocSansActif, tagActifOriginal|null, tagActifSansAttribut|null].
function bo_menu_strip_active(string $region): array {
    if (!preg_match('~<a\b[^>]*\saria-current="page"[^>]*>~i', $region, $m)) return [$region, null, null];
    $orig = $m[0];
    $canon = preg_replace('~\s*aria-current="page"~i', '', $orig);
    $pos = strpos($region, $orig);
    return [substr($region, 0, $pos) . $canon . substr($region, $pos + strlen($orig)), $orig, $canon];
}
function bo_menu_restore_active(string $region, ?string $orig, ?string $canon): string {
    if ($orig === null || $canon === null) return $region;
    $pos = strpos($region, $canon);
    return $pos === false ? $region : substr($region, 0, $pos) . $orig . substr($region, $pos + strlen($canon));
}
// Nouvelle version de la région d'UNE page après la modif globale, ou null si non applicable.
// Pour le menu : on retire l'état actif, on applique le diff, on remet l'état actif propre à la page.
function bo_global_new_region(string $tag, string $region, ?array $diff, string $oldB, string $newB): ?string {
    $isMenu = ($tag === 'menu');
    if ($isMenu) [$canon, $orig, $cn] = bo_menu_strip_active($region);
    else { $canon = $region; $orig = $cn = null; }
    if ($diff && substr_count($canon, $diff['find']) === 1) {
        $nc = str_replace($diff['find'], $diff['replace'], $canon);
        return $isMenu ? bo_menu_restore_active($nc, $orig, $cn) : $nc;
    }
    if ($region === $oldB) return $newB;   // bloc identique à la référence → remplacement entier (footer)
    return null;
}
// Pages du site où la modification globale s'appliquera (région présente + applicable).
function bo_global_targets(string $tag, string $oldBlock, string $newBlock, ?array $diff): array {
    $ok = []; $skip = [];
    foreach (bo_html_pages() as $f) {
        $hp = BO_DOCROOT.'/'.$f; if (!is_file($hp)) continue;
        $reg = bo_extract_region((string)file_get_contents($hp), $tag);
        if (!$reg) { $skip[] = $f; continue; }
        $nr = bo_global_new_region($tag, $reg[2], $diff, $oldBlock, $newBlock);
        if ($nr !== null && $nr !== $reg[2]) $ok[] = $f; else $skip[] = $f;
    }
    return ['ok' => $ok, 'skip' => $skip];
}

/* ---- Régions TEMPLATE gérées côté SERVEUR (contenu dynamique dans une page statique) ----
 * Convention : <!--TPL:NOM_START--> … <!--TPL:NOM_END-->. Le contenu ENTRE ces marqueurs est
 * régénéré par le serveur (ex. avis clients via render-testimonials.php) et ne doit JAMAIS être
 * réécrit par l'éditeur (IA ou chercher/remplacer). Toute paire de marqueurs TPL présente dans la
 * page est protégée automatiquement — aucun réglage par site. Trois temps :
 *   1) bo_tpl_mask()    : avant l'envoi au modèle, on remplace le contenu de la zone par un
 *                         placeholder → le modèle ne voit pas / ne réécrit pas les données dynamiques.
 *   2) bo_tpl_restore() : avant toute écriture, on réinjecte le contenu réel depuis le fichier LIVE
 *                         (un avis a pu être publié entre la proposition et la validation).
 *   3) bo_tpl_intact()  : refuse d'écrire une page qui aurait perdu une paire de marqueurs (sinon le
 *                         rendu serveur des avis ne retrouverait plus sa zone). */
function bo_tpl_regions(string $html): array {
    $out = [];
    if (!preg_match_all('~<!--TPL:([A-Z0-9_]+)_START-->~', $html, $ms, PREG_OFFSET_CAPTURE)) return $out;
    foreach ($ms[1] as $i => $nm) {
        $name = $nm[0];
        $startMark = $ms[0][$i][0];
        $endMark = '<!--TPL:'.$name.'_END-->';
        $innerStart = $ms[0][$i][1] + strlen($startMark);
        $ePos = strpos($html, $endMark, $innerStart);
        if ($ePos === false) continue;                 // marqueur de fin manquant → paire ignorée
        $out[$name] = ['start'=>$startMark, 'end'=>$endMark, 'inner'=>substr($html, $innerStart, $ePos - $innerStart)];
    }
    return $out;
}
function bo_tpl_mask(string $html): string {
    foreach (bo_tpl_regions($html) as $r) {
        $ph = "\n<!-- Contenu géré automatiquement par le site (ne pas modifier ; conserver ces deux commentaires TPL tels quels). -->\n";
        $html = str_replace($r['start'].$r['inner'].$r['end'], $r['start'].$ph.$r['end'], $html);
    }
    return $html;
}
function bo_tpl_restore(string $newHtml, string $liveHtml): string {
    foreach (bo_tpl_regions($liveHtml) as $r) {
        $sPos = strpos($newHtml, $r['start']); if ($sPos === false) continue;
        $innerStart = $sPos + strlen($r['start']);
        $ePos = strpos($newHtml, $r['end'], $innerStart); if ($ePos === false) continue;
        $newHtml = substr($newHtml, 0, $innerStart) . $r['inner'] . substr($newHtml, $ePos);
    }
    return $newHtml;
}
function bo_tpl_intact(string $newHtml, string $liveHtml): bool {
    foreach (bo_tpl_regions($liveHtml) as $r) {
        $sPos = strpos($newHtml, $r['start']); if ($sPos === false) return false;
        if (strpos($newHtml, $r['end'], $sPos + strlen($r['start'])) === false) return false;
    }
    return true;
}

if ($action === 'propose') {
    $req = trim((string)($_POST['request'] ?? ''));
    if ($req==='') fail(422, "Demande vide.");
    if (mb_strlen($req) > 4000) fail(422, "Demande trop longue.");
    if (!bo_is_configured()) fail(409, "needs_key");
    $gw = bo_gateway_enabled();            // passerelle : quota mensuel € géré côté central (plus de plafond/jour local)
    if (!$gw && usage_today()['calls'] >= BO_DAILY_CALLS) fail(429, "Plafond de ".BO_DAILY_CALLS." requêtes/jour atteint. Réessayez demain.");

    $p = null; $key = '';
    if (!$gw) {
        $pid = bo_selected_id(); $p = bo_provider($pid); $key = bo_get_key($pid);
        if (!$p) fail(409, "needs_key");
    }

    $page = basename((string)($_POST['page'] ?? ''));

    // ---- Section GLOBALE (menu / pied de page) : édite le bloc une fois, propage à tout le site ----
    if (($gtag = bo_global_tag($page)) !== null) {
        if (!$gw) fail(422, "Les sections globales passent par la passerelle centrale.");
        // Page de référence = index.html (sinon 1re page portant la région).
        $ref = null; $refHtml = ''; $reg = null;
        foreach (array_merge(['index.html'], bo_html_pages()) as $cand) {
            $cp = BO_DOCROOT.'/'.$cand;
            if (!is_file($cp)) continue;
            $h = file_get_contents($cp); $rr = bo_extract_region($h, $gtag);
            if ($rr) { $ref = $cand; $refHtml = $h; $reg = $rr; break; }
        }
        if ($ref === null) fail(422, "Cette section n'existe pas sur ce site.");
        $oldBlock = $reg[2];
        usage_record(0, 0, 1);
        @set_time_limit(200);
        $r = bo_edit_gateway($page === '__menu__' ? 'menu' : 'pied de page', $oldBlock, $req);
        if (!$r['ok']) fail(502, $r['error']);
        usage_record((int)($r['in']??0), (int)($r['out']??0), 0);
        $parsed = $r['parsed'];
        if (!is_array($parsed) || !isset($parsed['summary'])) fail(502, "Réponse illisible. Réessayez.");
        $newBlock = '';
        foreach (($parsed['changes'] ?? []) as $c) { $nc = (string)($c['new_content'] ?? ''); if ($nc !== '') { $newBlock = $nc; break; } }

        bo_purge_stale_proposals();
        $token = bin2hex(random_bytes(8));
        if ($newBlock === '' || $newBlock === $oldBlock) {   // rien à changer
            file_put_contents(BO_PROPOSALS.'/'.$token.'.json', json_encode(['changes'=>[],'summary'=>$parsed['summary']], JSON_UNESCAPED_UNICODE), LOCK_EX);
            out(['ok'=>true,'token'=>$token,'summary'=>$parsed['summary'],'changes'=>[],
                 'tokens'=>['in'=>$r['in']??0,'out'=>$r['out']??0],'provider'=>(string)($r['label']??'Assistant'),'global'=>true]);
        }
        // Menu : diff calculé en espace « sans état actif » pour matcher toutes les pages.
        if ($gtag === 'menu') { [$oc] = bo_menu_strip_active($oldBlock); [$ncc] = bo_menu_strip_active($newBlock); $diff = bo_region_diff($oc, $ncc); }
        else $diff = bo_region_diff($oldBlock, $newBlock);
        $tgt = bo_global_targets($gtag, $oldBlock, $newBlock, $diff);
        // Aperçu : la page de référence avec le nouveau bloc.
        $previewHtml = substr($refHtml, 0, $reg[0]) . $newBlock . substr($refHtml, $reg[0] + $reg[1]);
        file_put_contents(BO_PROPOSALS.'/'.$token.'.json', json_encode([
            'global'=>$gtag, 'summary'=>$parsed['summary'], 'req_id'=>(string)($r['req_id'] ?? ''),
            'old_block'=>$oldBlock, 'new_block'=>$newBlock, 'diff'=>$diff,
            'changes'=>[['path'=>$ref, 'new_content'=>$previewHtml]],   // pour l'aperçu uniquement
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
        out(['ok'=>true,'token'=>$token,'summary'=>$parsed['summary'],
             'changes'=>[['path'=>$ref,'old_len'=>strlen($oldBlock),'new_len'=>strlen($newBlock)]],
             'tokens'=>['in'=>$r['in']??0,'out'=>$r['out']??0],'provider'=>(string)($r['label']??'Assistant'),
             'global'=>true, 'region_label'=>bo_global_label($page), 'preview_path'=>$ref,
             'targets'=>count($tgt['ok']), 'skipped'=>count($tgt['skip'])]);
    }

    // Ciblage d'UNE page : corpus limité à cette seule page → beaucoup plus rapide, pas de dépassement de délai.
    $rules = BO_RULES;
    if ($page !== '' && $page !== '__all__') {
        if (!in_array($page, bo_html_pages(), true)) fail(422, "Page à modifier inconnue.");
        $pp = BO_DOCROOT.'/'.$page;
        $files = is_file($pp) ? [$page => file_get_contents($pp)] : [];
        $rules .= "\n- Tu ne modifies QUE le fichier « ".$page." ». Ne renvoie AUCUN autre fichier.";
    } else {
        if ($gw) fail(422, "Choisissez la page à modifier.");   // la passerelle travaille page par page
        $files = read_site_files();
        $page = '';
    }
    if ($gw && !isset($files[$page])) fail(422, "Page introuvable.");
    $corpus=''; foreach ($files as $n=>$c) $corpus .= "\n===== FICHIER: $n =====\n".bo_tpl_mask($c)."\n";

    usage_record(0, 0, 1);                 // compte la requête
    @set_time_limit(200);                  // certains modèles (ex. GLM-4.6) répondent en ~1-2 min
    $r = $gw ? bo_edit_gateway($page, bo_tpl_mask((string)$files[$page]), $req)
             : bo_llm_edit($p, $key, $rules, $corpus, $req);
    if (!$r['ok']) fail(502, $r['error']);
    usage_record((int)($r['in']??0), (int)($r['out']??0), 0);   // ajoute les tokens consommés
    $parsed = $r['parsed'];
    if (!is_array($parsed) || !isset($parsed['summary'])) fail(502, "Réponse du fournisseur illisible. Réessayez ou changez de fournisseur.");

    $changes=[];
    foreach (($parsed['changes'] ?? []) as $c) {
        $name = basename((string)($c['path'] ?? ''));
        if ($page !== '' && $name !== $page) continue;   // ciblage : on ignore tout autre fichier
        if (editable_path($name)===null) continue;
        $old = $files[$name] ?? ''; $new=(string)($c['new_content'] ?? '');
        if ($new===''||$new===$old) continue;
        // Régions TPL gérées par le serveur (ex. avis clients) : on restaure leur contenu depuis le
        // fichier LIVE. Si le modèle a cassé une paire de marqueurs, on écarte ce fichier (jamais de
        // page proposée sans ses marqueurs → sinon le rendu serveur des avis perdrait sa zone).
        if (substr($name,-5)==='.html') {
            if (!bo_tpl_intact($new, $old)) continue;
            $new = bo_tpl_restore($new, $old);
            if ($new === $old) continue;
        }
        $changes[] = ['path'=>$name,'new_content'=>$new,'old_len'=>strlen($old),'new_len'=>strlen($new)];
    }
    bo_purge_stale_proposals();   // purge des brouillons > 24 h (+ ping 'abandoned' des propositions non appliquées)
    $token = bin2hex(random_bytes(8));
    file_put_contents(BO_PROPOSALS.'/'.$token.'.json', json_encode(['changes'=>$changes,'summary'=>$parsed['summary'],'req_id'=>(string)($r['req_id'] ?? '')], JSON_UNESCAPED_UNICODE), LOCK_EX);
    out(['ok'=>true,'token'=>$token,'summary'=>$parsed['summary'],
         'changes'=>array_map(fn($c)=>['path'=>$c['path'],'old_len'=>$c['old_len'],'new_len'=>$c['new_len']], $changes),
         'tokens'=>['in'=>$r['in']??0,'out'=>$r['out']??0],
         'provider'=>$gw ? (string)($r['label'] ?? 'Assistant') : $p['label']]);
}

if ($action === 'apply') {
    $token = preg_replace('/[^a-f0-9]/','',(string)($_POST['token'] ?? ''));
    $pf = BO_PROPOSALS.'/'.$token.'.json';
    if ($token===''||!is_file($pf)) fail(404, "Proposition introuvable ou expirée.");
    $prop = json_decode((string)file_get_contents($pf), true);
    $summary = trim((string)($prop['summary'] ?? '')) ?: 'Modification';

    // ---- Application GLOBALE : propage le bloc menu/footer à toutes les pages qui le portent ----
    if (!empty($prop['global'])) {
        $tag = (string)$prop['global']; $diff = $prop['diff'] ?? null;
        $oldB = (string)($prop['old_block'] ?? ''); $newB = (string)($prop['new_block'] ?? '');
        $id = bo_newid(); bo_snapshot_all($id);
        $written = [];
        foreach (bo_html_pages() as $f) {
            $hp = BO_DOCROOT.'/'.$f; if (!is_file($hp)) continue;
            $h = file_get_contents($hp); $reg = bo_extract_region($h, $tag); if (!$reg) continue;
            $newRegion = bo_global_new_region($tag, $reg[2], $diff, $oldB, $newB);
            if ($newRegion !== null && $newRegion !== $reg[2]) {
                $nh = substr($h, 0, $reg[0]) . $newRegion . substr($h, $reg[0] + $reg[1]);
                if (file_put_contents($hp, $nh, LOCK_EX) !== false) $written[] = $f;
            }
        }
        if (!$written) { @unlink($pf); fail(422, "Aucune page n'a pu être mise à jour (section absente ou différente partout)."); }
        $rid = (string)($prop['req_id'] ?? '');
        bo_history_add($id, $summary, $written, $rid);
        bo_gateway_outcome($rid, 'applied');   // issue = appliquée (boucle d'amélioration)
        @unlink($pf);
        out(['ok'=>true, 'written'=>$written, 'global'=>true]);
    }

    $changes = $prop['changes'] ?? [];
    if (!$changes) fail(422, "Rien à appliquer.");
    $id = bo_newid(); bo_snapshot_all($id);                 // snapshot complet AVANT la modif
    $written=[]; foreach ($changes as $c){
        $p=editable_path($c['path']); if (!$p) continue;
        $content = (string)$c['new_content'];
        // Garde-fou FINAL avant écriture : restaurer les régions TPL depuis le fichier LIVE du moment
        // (un avis a pu être publié entre la proposition et la validation). On n'écrit jamais une page
        // qui aurait perdu ses marqueurs TPL.
        if (substr($c['path'],-5)==='.html' && is_file($p)) {
            $live = (string)file_get_contents($p);
            if (!bo_tpl_intact($content, $live)) continue;
            $content = bo_tpl_restore($content, $live);
        }
        file_put_contents($p,$content,LOCK_EX); $written[]=$c['path'];
    }
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
    $rid = (string)($prop['req_id'] ?? '');
    bo_history_add($id, $summary, $written, $rid);
    bo_gateway_outcome($rid, 'applied');   // issue = appliquée (boucle d'amélioration)
    @unlink($pf);
    out(['ok'=>true,'written'=>$written]);
}

/* ---- Chercher / Remplacer (déterministe, SANS IA) ---- */
if ($action === 'replace') {
    $find = (string)($_POST['find'] ?? '');
    $repl = (string)($_POST['replace'] ?? '');
    $mode = (($_POST['mode'] ?? 'preview') === 'apply') ? 'apply' : 'preview';
    if ($find === '') fail(422, "Indiquez le texte à rechercher.");
    if (mb_strlen($find) > 2000 || mb_strlen($repl) > 2000) fail(422, "Texte trop long (max 2000 caractères).");

    // On ne touche QU'aux pages HTML cochées (scan live du docroot) — jamais CSS/JS/robots/sitemap ni config.
    $pages = json_decode((string)($_POST['pages'] ?? '[]'), true);
    if (!is_array($pages)) $pages = [];
    $valid = bo_html_pages();
    $files = [];
    foreach ($pages as $pg) {
        $pg = basename((string)$pg);
        if (in_array($pg, $valid, true)) { $p = BO_DOCROOT.'/'.$pg; if (is_file($p)) $files[$pg] = file_get_contents($p); }
    }
    if (!$files) fail(422, "Sélectionnez au moins une page.");
    $hits = []; $total = 0;
    foreach ($files as $name => $content) {
        $n = substr_count($content, $find);
        if ($n > 0) { $hits[] = ['path'=>$name, 'count'=>$n]; $total += $n; }
    }
    if ($mode === 'preview') out(['ok'=>true, 'total'=>$total, 'files'=>$hits]);

    if ($total === 0) fail(422, "Texte introuvable : rien à remplacer.");
    if ($repl === $find) fail(422, "Le texte de remplacement est identique.");
    $id = bo_newid(); bo_snapshot_all($id);                 // snapshot complet AVANT (réversible)
    $written = [];
    foreach ($hits as $h) {
        $p = editable_path($h['path']); if (!$p) continue;
        $content = $files[$h['path']];
        $replaced = str_replace($find, $repl, $content);
        // Ne jamais laisser un chercher/remplacer réécrire une région TPL gérée par le serveur.
        if (substr($h['path'],-5)==='.html' && bo_tpl_intact($replaced, $content)) $replaced = bo_tpl_restore($replaced, $content);
        if ($replaced !== $content) { file_put_contents($p, $replaced, LOCK_EX); $written[] = $h['path']; }
    }
    // Cache-busting si un CSS/JS a changé (même logique que 'apply').
    if (preg_grep('/\.(css|js)$/', $written)) {
        $v = date('YmdHis');
        foreach (BO_EDITABLE as $n) {
            if (substr($n,-5) !== '.html') continue;
            $hp = BO_DOCROOT.'/'.$n; if (!is_file($hp)) continue;
            $html = file_get_contents($hp);
            $nv = preg_replace('/(\.(?:css|js))\?v=[0-9A-Za-z._-]*/', '$1?v='.$v, $html);
            if ($nv !== null && $nv !== $html) file_put_contents($hp, $nv, LOCK_EX);
        }
    }
    $summary = "Chercher/remplacer : « ".mb_substr($find,0,40)." » → « ".mb_substr($repl,0,40)." » (".$total." occurrence".($total>1?'s':'').")";
    bo_history_add($id, $summary, $written);
    out(['ok'=>true, 'total'=>$total, 'written'=>$written]);
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
    $undoneReqId = (string)($h[0]['req_id'] ?? '');   // avant d'empiler la nouvelle entrée
    $restored = bo_restore_snapshot($h[0]['id']);
    bo_history_add($cur, "↩︎ Annulation de « ".mb_substr((string)$h[0]['summary'],0,90)." »", $restored);
    bo_gateway_outcome($undoneReqId, 'undone');   // issue = annulée (signal fort : la modif ne convenait pas)
    out(['ok'=>true,'restored'=>$restored]);
}

// SVG volontairement EXCLU des uploads : un .svg peut contenir du JavaScript et,
// servi depuis /assets/ sur le domaine du site, exécuterait ce code (XSS stocké).
const BO_UPLOAD_EXT = ['jpg','jpeg','png','webp','gif','pdf','doc','docx','xls','xlsx','ppt','pptx','csv'];
const BO_IMG_EXT    = ['jpg','jpeg','png','webp','gif'];
if ($action === 'upload') {
    if (empty($_FILES['image']) || $_FILES['image']['error']!==UPLOAD_ERR_OK) fail(422, "Aucun fichier reçu.");
    $f=$_FILES['image'];
    if ($f['size'] > 20*1024*1024) fail(422, "Fichier trop lourd (max 20 Mo).");
    $ext=strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, BO_UPLOAD_EXT, true)) fail(422, "Type non autorisé. Acceptés : images, PDF, doc/xls/ppt, csv.");
    if (in_array($ext, BO_IMG_EXT, true) && @getimagesize($f['tmp_name'])===false) fail(422, "Image illisible.");
    $head=@file_get_contents($f['tmp_name'],false,null,0,512);
    if ($head!==false && stripos($head,'<?php')!==false) fail(422, "Fichier refusé (contenu non autorisé).");
    $base=preg_replace('/[^a-zA-Z0-9_-]/','-', pathinfo($f['name'], PATHINFO_FILENAME));
    $base=trim(substr($base,0,40),'-') ?: 'fichier';
    $dest=BO_DOCROOT.'/assets/'.$base.'.'.$ext; $i=1;
    while (is_file($dest)){ $dest=BO_DOCROOT.'/assets/'.$base.'-'.($i++).'.'.$ext; }
    if (!move_uploaded_file($f['tmp_name'],$dest)) fail(500, "Échec de l'enregistrement.");
    @chmod($dest,0644);
    $fn = 'assets/'.basename($dest);
    $up = bo_json_read(BO_UPLOADS_FILE); if (!in_array($fn,$up,true)) { $up[]=$fn; bo_json_write(BO_UPLOADS_FILE,$up); }
    out(['ok'=>true,'filename'=>$fn]);
}

if ($action === 'list_uploads') {
    $up = bo_json_read(BO_UPLOADS_FILE);
    if (!is_array($up)) $up = [];
    // On ne liste QUE les fichiers réellement téléversés via l'admin (suivis dans bo_uploads.json) et
    // encore présents. On NE globbe PLUS tout /assets/ : les images du design du site ne doivent JAMAIS
    // apparaître dans la galerie « supprimables » (un client non technique pourrait effacer une vraie photo).
    $up = array_values(array_filter($up, fn($f)=>is_string($f) && strpos($f,'assets/')===0 && is_file(BO_DOCROOT.'/'.$f)));
    bo_json_write(BO_UPLOADS_FILE, $up);
    out(['ok'=>true,'files'=>$up]);
}

if ($action === 'delete_image') {
    $fn = (string)($_POST['filename'] ?? '');
    $up = bo_json_read(BO_UPLOADS_FILE);
    if (!in_array($fn,$up,true)) fail(404, "Image inconnue.");   // on ne supprime QUE des images téléversées ici
    // Garde-fou : refuser si le fichier est ENCORE utilisé dans une page (sinon image cassée sur le site).
    $bn = basename($fn);
    foreach (bo_html_pages() as $pg) {
        $html = (string)@file_get_contents(BO_DOCROOT.'/'.$pg);
        if ($html !== '' && strpos($html, $bn) !== false)
            fail(409, "Ce fichier est utilisé sur la page « ".bo_page_label($pg)." ». Retirez-le d'abord de la page (par une demande de modification), puis supprimez-le.");
    }
    $rp = realpath(BO_DOCROOT.'/'.$fn); $base = realpath(BO_DOCROOT.'/assets');
    if ($rp && $base && strpos($rp,$base)===0 && is_file($rp)) @unlink($rp);
    bo_json_write(BO_UPLOADS_FILE, array_values(array_diff($up,[$fn])));
    out(['ok'=>true]);
}

fail(400, "Action inconnue.");
