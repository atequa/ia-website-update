<?php
/**
 * Tests de non-régression du cœur du back-office (édition globale menu/footer + passerelle).
 * NE MODIFIE RIEN : charge les VRAIES fonctions depuis les fichiers source (par extraction),
 * pour verrouiller tous les cas limites rencontrés (aria-current, menu <nav>, footer de citation,
 * insertion pure, repère ambigu, ancres site une-page…).
 *
 * Lancer :  php bo-package/tests/test_backoffice.php
 * Sortie   :  liste PASS/FAIL + résumé ; code de sortie ≠ 0 si un test échoue.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

/* --- extraction d'une fonction par comptage d'accolades (sans exécuter le fichier) --- */
function extract_fn(string $src, string $name): ?string {
    $p = strpos($src, "function $name(");
    if ($p === false) return null;
    $b = strpos($src, '{', $p);
    if ($b === false) return null;
    $depth = 0; $n = strlen($src);
    for ($i = $b; $i < $n; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) return substr($src, $p, $i - $p + 1); }
    }
    return null;
}
function load_fns(string $file, array $names): int {
    if (!is_file($file)) return 0;
    $src = file_get_contents($file); $loaded = 0;
    foreach ($names as $fn) {
        if (function_exists($fn)) { $loaded++; continue; }
        $code = extract_fn($src, $fn);
        if ($code !== null) { eval($code); $loaded++; }
    }
    return $loaded;
}

$API = __DIR__ . '/../dist/payload/admin/api.php';
load_fns($API, ['bo_extract_region','bo_region_diff','bo_menu_strip_active','bo_menu_restore_active','bo_global_new_region']);

// ai_apply_edits vit dans le projet atequa-web (passerelle) — chemins candidats, sinon on saute ces tests.
$GW = null;
foreach ([
    getenv('AI_GATEWAY_PHP') ?: '',
    __DIR__ . '/../../../atequa-web.com/private/ai_gateway.php',
    dirname(__DIR__, 3) . '/atequa-web.com/private/ai_gateway.php',
    '/Users/manueldelgoffe/Documents/Claude Code/atequa-web.com/private/ai_gateway.php',
] as $cand) { if ($cand && is_file($cand)) { $GW = $cand; break; } }
if ($GW) load_fns($GW, ['ai_apply_edits', 'ai_full_rewrite_sane']);

/* --- micro-framework d'assertions --- */
$PASS = 0; $FAIL = 0; $fails = [];
function ok(string $label, bool $cond) { global $PASS,$FAIL,$fails; if ($cond) { $PASS++; echo "  ✓ $label\n"; } else { $FAIL++; $fails[]=$label; echo "  ✗ $label\n"; } }
function eq(string $label, $a, $b) { ok($label . ($a===$b?'':" (obtenu: ".var_export($a,true).")"), $a === $b); }

echo "== Détection de région (menu / footer) ==\n";
// menu educ-like : <header> contenant <nav>
$educ = '<body><header class="h"><nav><a href="/">Accueil</a></nav></header><main>x</main><footer>site</footer></body>';
$r = bo_extract_region($educ, 'menu'); ok('menu = <header> quand il contient un <nav>', $r && stripos($r[2],'<header')===0);
// menu panda-like : <header> hero SANS nav, menu = <nav> direct
$panda = '<body><nav class="sticky"><a href="/">A</a></nav><header class="hero"><img></header><footer>site</footer></body>';
$r = bo_extract_region($panda, 'menu'); ok('menu = <nav> quand le <header> est un hero sans nav', $r && stripos($r[2],'<nav')===0);
// footer = DERNIER <footer> (le 1er peut être une citation)
$cite = '<blockquote><p>Bravo</p><footer>— Julie, présidente</footer></blockquote><main>x</main><footer class="site-footer">© 2026</footer>';
$r = bo_extract_region($cite, 'footer'); ok('footer = dernier <footer> (ignore la citation)', $r && strpos($r[2],'site-footer')!==false);
$one = '<main>x</main><footer class="only">© 2026</footer>';
$r = bo_extract_region($one, 'footer'); ok('footer unique inchangé', $r && strpos($r[2],'only')!==false);
$r = bo_extract_region('<div>rien</div>', 'footer'); ok('aucun footer → null', $r === null);

echo "== Diff minimal (bo_region_diff) ==\n";
// insertion pure (ajout d'un lien) : find non vide, unique, reproduit new
$old = '<nav><a href="/#a">A</a><a href="/#b">B</a></nav>';
$new = '<nav><a href="/#a">A</a><a href="/#b">B</a><a href="/#c">C</a></nav>';
$d = bo_region_diff($old,$new);
ok('insertion : diff non nul', $d !== null);
ok('insertion : find non vide', $d && $d['find'] !== '');
ok('insertion : find unique dans old', $d && substr_count($old,$d['find'])===1);
eq('insertion : appliquer reproduit new', $d ? str_replace($d['find'],$d['replace'],$old) : null, $new);
// modification de texte
$old2='<footer>Tel : 01</footer>'; $new2='<footer>Tel : 02</footer>';
$d2=bo_region_diff($old2,$new2); eq('modif texte : appliquer reproduit new', $d2?str_replace($d2['find'],$d2['replace'],$old2):null, $new2);
// identique → null
ok('identique → null', bo_region_diff($old,$old) === null);

echo "== État actif du menu (aria-current) ==\n";
$reg = '<nav><a href="/" aria-current="page">Accueil</a><a href="/contact">Contact</a></nav>';
[$stripped,$orig,$canon] = bo_menu_strip_active($reg);
ok('strip retire aria-current', strpos($stripped,'aria-current')===false);
eq('restore = identité (round-trip)', bo_menu_restore_active($stripped,$orig,$canon), $reg);
$noac = '<nav><a href="/">Accueil</a></nav>';
[$s2,$o2,$c2] = bo_menu_strip_active($noac);
ok('sans aria-current : strip inchangé', $s2 === $noac && $o2 === null);

echo "== Nouvelle région par page (bo_global_new_region) ==\n";
// FOOTER : page identique à la référence → remplacement entier
$oldF='<footer class="site">© 2026 · <a href="/#visite">Visite</a></footer>';
$newF='<footer class="site">© 2026 · <a href="/#visite">Visite</a> · <a href="https://insta">Insta</a></footer>';
$dF=bo_region_diff($oldF,$newF);
eq('footer : page = référence → newB', bo_global_new_region('footer',$oldF,$dF,$oldF,$newF), $newF);
// FOOTER : autre page (footer identique) via diff → applique aussi
eq('footer : autre page via diff', bo_global_new_region('footer',$oldF,$dF,$oldF,$newF), $newF);
// FOOTER : page au bloc différent, find absent → null (sautée)
ok('footer : page au bloc différent → null (sautée)', bo_global_new_region('footer','<footer>réduit</footer>',$dF,$oldF,$newF) === null);
// MENU : ajout d'un lien ; page dont l'actif est un AUTRE lien → actif préservé
$refMenu='<nav><a href="/" aria-current="page">Accueil</a><a href="/contact">Contact</a></nav>';
$refMenuNew='<nav><a href="/" aria-current="page">Accueil</a><a href="/blog">Blog</a><a href="/contact">Contact</a></nav>';
// diff en espace canonique (comme le fait propose pour le menu)
[$oc]=bo_menu_strip_active($refMenu); [$nc]=bo_menu_strip_active($refMenuNew); $dM=bo_region_diff($oc,$nc);
$pageContact='<nav><a href="/">Accueil</a><a href="/contact" aria-current="page">Contact</a></nav>';
$outContact=bo_global_new_region('menu',$pageContact,$dM,$refMenu,$refMenuNew);
ok('menu : lien Blog ajouté sur la page contact', $outContact && strpos($outContact,'/blog')!==false);
ok('menu : la page contact garde SON actif (Contact)', $outContact && preg_match('~/contact" aria-current="page"~',$outContact)===1);
ok('menu : un seul aria-current sur la page contact', $outContact && substr_count($outContact,'aria-current')===1);

echo "== Passerelle : application des edits ciblés (ai_apply_edits) ==\n";
if (function_exists('ai_apply_edits')) {
    $content='<p>Prix : 15 euros. Menu enfant : 15 euros.</p>';
    // repère UNIQUE
    [$nn,$ap,$fail]=ai_apply_edits('<p>Bonjour le monde</p>',[['find'=>'le monde','replace'=>'à tous']]);
    ok('unique : appliqué sans échec', !$fail && $ap===1 && strpos($nn,'à tous')!==false);
    // repère ABSENT → échec (repli)
    [$nn,$ap,$fail]=ai_apply_edits('<p>abc</p>',[['find'=>'introuvable','replace'=>'x']]);
    ok('absent : échec (repli)', $fail === true);
    // repère AMBIGU (2 occurrences) → échec (repli)
    [$nn,$ap,$fail]=ai_apply_edits($content,[['find'=>'15 euros','replace'=>'18 euros']]);
    ok('ambigu (2×) : échec (repli, jamais deviner)', $fail === true);
    // no-op (find == replace)
    [$nn,$ap,$fail]=ai_apply_edits('<p>x</p>',[['find'=>'x','replace'=>'x']]);
    ok('no-op : pas d\'échec, 0 appliqué', !$fail && $ap===0);
} else {
    echo "  (ai_gateway.php introuvable — tests passerelle sautés ; définir AI_GATEWAY_PHP pour les activer)\n";
}

echo "\n== Passerelle : garde-fou anti-troncature d'une réécriture complète (ai_full_rewrite_sane) ==\n";
if (function_exists('ai_full_rewrite_sane')) {
    $orig = '<!DOCTYPE html><html><head><title>T</title></head><body>'
          . str_repeat('<section>contenu réel de la page, du texte, des blocs, des images </section>', 200)
          . '<footer>Atelier Montmédy (55)</footer></body></html>';
    // cas RÉEL couturieuse 14/07 : le modèle renvoie juste le bloc modifié entouré de « ... reste de la page ... »
    $frag = '<!-- ... reste de la page ... --><div class="info-card"><p class="info-value">'
          . '14 Rue du général Leclerc<br/>Appartement 6<br/>55600 Montmédy</p></div><!-- ... reste de la page ... -->';
    ok('fragment abrégé (cas couturieuse) : REFUSÉ', ai_full_rewrite_sane($orig, $frag) === false);
    ok('chaîne vide : REFUSÉE', ai_full_rewrite_sane($orig, '') === false);
    ok('perte de >50% du volume : REFUSÉE', ai_full_rewrite_sane($orig, substr($orig, 0, (int)(strlen($orig)*0.4))) === false);
    // une réécriture complète légitime (même page, footer corrigé) reste ACCEPTÉE
    $good = str_replace('Atelier Montmédy (55)', '14 Rue du général Leclerc, Appartement 6, 55600 Montmédy', $orig);
    ok('réécriture complète légitime : ACCEPTÉE', ai_full_rewrite_sane($orig, $good) === true);
    // page sans </html> à l'origine (fragment JS/CSS) : la règle structurelle ne s'applique pas abusivement
    ok('petit fichier complet sans balise html : ACCEPTÉ', ai_full_rewrite_sane(str_repeat('a',500), str_repeat('b',480)) === true);
} else {
    echo "  (ai_full_rewrite_sane introuvable — garde-fou non testé ici)\n";
}

echo "\n== Intégrité : toute constante BO_* utilisée dans api.php a un repli ou une définition ==\n";
$apiSrc = @file_get_contents(__DIR__.'/../dist/payload/admin/api.php');
if ($apiSrc !== false) {
    // BO_UPLOADS_FILE était utilisée (upload/list_uploads/delete_image) SANS être définie → erreur
    // fatale en PHP 8 sur les sites dont le bo_config est antérieur (incident couturieuse 14/07).
    $used = strpos($apiSrc, 'BO_UPLOADS_FILE') !== false;
    $guarded = preg_match('/if\s*\(\s*!\s*defined\(\s*[\'"]BO_UPLOADS_FILE[\'"]\s*\)\s*\)\s*define\(/', $apiSrc) === 1;
    ok('BO_UPLOADS_FILE utilisée → repli define() présent (sinon 500 sur PHP 8)', !$used || $guarded);
} else {
    echo "  (api.php introuvable — check d'intégrité sauté)\n";
}

echo "\n==================================================\n";
echo "Résultat : $PASS réussis, $FAIL échoués\n";
if ($FAIL) { echo "ÉCHECS :\n  - ".implode("\n  - ",$fails)."\n"; exit(1); }
echo "TOUT VERT ✓\n";
