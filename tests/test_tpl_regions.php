<?php
declare(strict_types=1);
error_reporting(E_ALL);

// --- Charge les VRAIES fonctions bo_tpl_* depuis api.php (pas de copie divergente) ---
$api = file_get_contents(__DIR__ . '/../dist/payload/admin/api.php');
$a = strpos($api, 'function bo_tpl_regions');
$b = strpos($api, "if (\$action === 'propose')", $a);
if ($a === false || $b === false) { fwrite(STDERR, "Impossible d'extraire les fonctions bo_tpl_*\n"); exit(2); }
eval(substr($api, $a, $b - $a));

// --- Page LIVE simulée : témoignages PUBLIÉS (état serveur après render-testimonials) ---
$START = '<!--TPL:TESTIMONIALS_START-->';
$END   = '<!--TPL:TESTIMONIALS_END-->';
$liveInner = "\n      <div class=\"section-head reveal\">\n        <h2 class=\"section-title\">Témoignages clients</h2>\n"
    . "        <p class=\"testimonials-avg\">4,9/5 &middot; 2 avis vérifiés</p>\n      </div>\n"
    . "      <div class=\"testimonials-grid\">\n"
    . "        <article class=\"card testimonial-card\"><p class=\"tc-text\">&laquo;&nbsp;Boris a élagué nos chênes avec un soin remarquable.&nbsp;&raquo;</p><span class=\"tc-name\">Julie D.</span></article>\n"
    . "        <article class=\"card testimonial-card\"><p class=\"tc-text\">&laquo;&nbsp;Abattage complexe géré sans casse. Parfait.&nbsp;&raquo;</p><span class=\"tc-name\">Marc L.</span></article>\n"
    . "      </div>\n    ";
$live = "<!doctype html><html lang=\"fr\"><head><title>Boris Alataille — Arboriste</title></head><body>\n"
    . "  <h1 class=\"hero-title\">Arboriste-grimpeur dans le Grand Est</h1>\n"
    . "  <section class=\"section testimonials\" id=\"temoignages\"><div class=\"container\">\n"
    . "      $START$liveInner$END\n"
    . "    </div></section>\n"
    . "  <footer><p>© Boris Alataille</p></footer>\n</body></html>\n";

$pass = 0; $fail = 0;
function check(string $label, bool $cond) { global $pass,$fail; if ($cond){$pass++; echo "  ✅ $label\n";} else {$fail++; echo "  ❌ $label\n";} }
function inner(string $html, string $s, string $e): ?string {
    $a = strpos($html,$s); if($a===false) return null; $a += strlen($s);
    $b = strpos($html,$e,$a); if($b===false) return null; return substr($html,$a,$b-$a);
}

echo "TEST 1 — Masquage : le modèle ne voit JAMAIS les avis réels\n";
$masked = bo_tpl_mask($live);
check("les 2 marqueurs sont conservés dans le corpus masqué", strpos($masked,$START)!==false && strpos($masked,$END)!==false);
check("le nom d'un client réel (Julie D.) est ABSENT du corpus masqué", strpos($masked,'Julie D.')===false);
check("le texte d'un avis réel est ABSENT du corpus masqué", strpos($masked,'élagué nos chênes')===false);
check("hors zone, le contenu reste intact (H1 visible)", strpos($masked,'Arboriste-grimpeur dans le Grand Est')!==false);

echo "\nTEST 2 — Modèle bien élevé : édite le H1, garde le placeholder TPL\n";
// Le modèle a reçu le corpus MASQUÉ ; il renvoie ce corpus avec le H1 modifié.
$out2 = str_replace('Arboriste-grimpeur dans le Grand Est','Arboriste-grimpeur certifié dans le Grand Est',$masked);
check("bo_tpl_intact = true", bo_tpl_intact($out2,$live));
$res2 = bo_tpl_restore($out2,$live);
check("l'édition du H1 est préservée", strpos($res2,'certifié dans le Grand Est')!==false);
check("les avis RÉELS sont restaurés (Julie D. + Marc L.)", strpos($res2,'Julie D.')!==false && strpos($res2,'Marc L.')!==false);
check("le placeholder de masquage a disparu du résultat", strpos($res2,'Contenu géré automatiquement')===false);
check("la zone restaurée == zone LIVE au caractère près", inner($res2,$START,$END) === $liveInner);

echo "\nTEST 3 — Modèle indiscipliné : réécrit le contenu ENTRE les marqueurs\n";
// Simule un modèle qui aurait inventé/écrasé des avis à l'intérieur de la zone.
$out3 = preg_replace('~('.preg_quote($START,'~').').*?('.preg_quote($END,'~').')~s',
    '$1'."\n      <p>FAUX AVIS INVENTÉ PAR L'IA — 10/5</p>\n    ".'$2', $masked);
check("bo_tpl_intact = true (marqueurs présents)", bo_tpl_intact($out3,$live));
$res3 = bo_tpl_restore($out3,$live);
check("le faux avis inventé est ÉCRASÉ (absent du résultat)", strpos($res3,'FAUX AVIS INVENTÉ')===false);
check("les vrais avis sont bien là", strpos($res3,'Julie D.')!==false);
check("zone restaurée == zone LIVE", inner($res3,$START,$END) === $liveInner);

echo "\nTEST 4 — Modèle destructeur : supprime carrément la section (perte des marqueurs)\n";
$out4 = preg_replace('~<section class="section testimonials".*?</section>~s','<!-- section supprimée -->',$masked);
check("bo_tpl_intact = FALSE → l'écriture sera REFUSÉE", bo_tpl_intact($out4,$live) === false);

echo "\nTEST 5 — Idempotence : masquer puis restaurer sans édition = fichier LIVE identique\n";
$round = bo_tpl_restore(bo_tpl_mask($live),$live);
check("round-trip identique au fichier LIVE", $round === $live);

echo "\nTEST 6 — Compat render-testimonials.php : après restauration, la régénération serveur retrouve sa zone\n";
// Reproduit la logique de regenerate_homepage_testimonials sur le résultat du TEST 2.
$s = strpos($res2,$START); $e = strpos($res2,$END);
check("les deux marqueurs sont retrouvables dans l'ordre", $s!==false && $e!==false && $e>$s);
$s2 = $s + strlen($START);
$newEmpty = "\n      <p>ÉTAT VIDE RÉGÉNÉRÉ</p>\n    ";
$regen = substr($res2,0,$s2) . $newEmpty . substr($res2,$e);
check("le serveur peut réécrire la zone (avis remplacés par l'état régénéré)", strpos($regen,'ÉTAT VIDE RÉGÉNÉRÉ')!==false && strpos($regen,'Julie D.')===false);
check("l'édition du H1 (hors zone) survit à la régénération serveur", strpos($regen,'certifié dans le Grand Est')!==false);

echo "\nTEST 7 — Page SANS marqueur TPL (autre page du site) : aucun effet\n";
$plain = "<html><body><h1>Mentions légales</h1></body></html>";
check("mask = no-op", bo_tpl_mask($plain) === $plain);
check("intact = true", bo_tpl_intact($plain,$plain));
check("restore = no-op", bo_tpl_restore($plain,$plain) === $plain);

echo "\n================ ".($fail===0 ? "TOUS LES TESTS PASSENT" : "$fail ÉCHEC(S)")." — $pass ok / $fail ko ================\n";
exit($fail===0 ? 0 : 1);
