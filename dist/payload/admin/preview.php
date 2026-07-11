<?php
/**
 * Back-office — APERÇU visuel d'une proposition NON publiée.
 * Sert la version proposée d'un fichier (ou la version live si non modifié).
 * Pour le HTML, fait pointer les CSS/JS modifiés vers cet aperçu pour que les
 * changements de style/script soient visibles. Auth requise.
 */
declare(strict_types=1);
@ini_set('display_errors', '0');                 // jamais de détail d'erreur PHP affiché
require __DIR__ . '/bo_path.php';                 // définit BO_PRIVATE_DIR (généré par site, hors payload)
require BO_PRIVATE_DIR . '/bo_auth.php';

if (!bo_current_user()) { http_response_code(403); exit('Non autorisé'); }

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? ''));
$path  = basename((string)($_GET['path'] ?? ''));
if (!in_array($path, BO_EDITABLE, true) && !(substr($path,-5)==='.html' && is_file(BO_DOCROOT.'/'.$path))) { http_response_code(404); exit('Fichier non autorisé'); }

$pf = BO_PROPOSALS.'/'.$token.'.json';
$changes = is_file($pf) ? (json_decode((string)file_get_contents($pf), true)['changes'] ?? []) : [];
$map = [];
foreach ($changes as $c) { $map[$c['path']] = $c['new_content']; }

$content = array_key_exists($path, $map) ? $map[$path] : @file_get_contents(BO_DOCROOT.'/'.$path);
if ($content === false) { http_response_code(404); exit('Introuvable'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$ct = [
  'html'=>'text/html; charset=utf-8','css'=>'text/css; charset=utf-8',
  'js'=>'application/javascript; charset=utf-8','xml'=>'application/xml; charset=utf-8',
  'txt'=>'text/plain; charset=utf-8',
][$ext] ?? 'text/plain; charset=utf-8';
header('Content-Type: '.$ct);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
// L'aperçu peut contenir du HTML proposé par l'IA : on interdit TOUT script (script-src 'none')
// pour qu'aucun code injecté ne s'exécute dans la session de l'éditeur. Styles/images conservés
// pour que le rendu visuel reste fidèle.
header("Content-Security-Policy: default-src 'self' data:; script-src 'none'; "
     . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; "
     . "object-src 'none'; base-uri 'none'; form-action 'none'");

if ($ext === 'html') {
    foreach ($map as $f => $_) {
        if ($f === $path) continue;
        $fe = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($fe, ['css','js'], true)) continue;
        $content = preg_replace(
            '~/'.preg_quote($f, '~').'(\?[^"\'\s]*)?~',
            'preview.php?token='.$token.'&path='.rawurlencode($f),
            $content
        );
    }
    $banner = '<div style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#21386E;color:#fff;font:13px/1.4 system-ui;padding:6px 12px;text-align:center">👁 Aperçu — non publié</div><div style="height:30px"></div>';
    $content = preg_replace('~<body[^>]*>~i', '$0'.$banner, (string)$content, 1);
}
echo $content;
