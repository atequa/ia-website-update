<?php
/**
 * Back-office V3 — mise à jour à distance SIGNÉE (racine de confiance, jamais MAJ à distance).
 * Télécharge version.json depuis le repo central, vérifie la signature RSA-SHA256 (OpenSSL) avec
 * la clé publique locale, vérifie le sha256 de chaque fichier, PUIS écrit (tout ou rien).
 */
require_once __DIR__ . '/bo_config.php';

function bo_pubkey_pem(): string {
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(BO_UPDATE_PUBKEY, 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function bo_canonical(array $v): string {
    $s = (string)($v['version'] ?? '');
    foreach (($v['files'] ?? []) as $f) {
        $s .= "\n" . ($f['url'] ?? '') . "\t" . ($f['dest'] ?? '') . "\t" . ($f['sha256'] ?? '');
    }
    return $s;
}
function bo_fetch(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_FOLLOWLOCATION=>true]);
    $r = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($r === false || $http !== 200) ? null : $r;
}
function bo_dest_path(string $dest): ?string {
    if (strpos($dest, '..') !== false) return null;
    // Périmètre strict : SEULS les fichiers du back-office sont écrivables par une mise à jour.
    // (un manifeste, même signé, ne peut PAS écraser le contenu du site, .htaccess, etc.)
    if (preg_match('~^private/([a-zA-Z0-9_]+\.(?:php|json))$~', $dest, $m)) return BO_PRIVATE . '/' . $m[1];
    if (preg_match('~^docroot/admin/([a-zA-Z0-9_]+\.php)$~', $dest, $m)) return BO_DOCROOT . '/admin/' . $m[1];
    return null;
}
function bo_ver_parts(string $v): array { preg_match_all('/\d+/', $v, $m); return array_map('intval', $m[0]); }
function bo_ver_is_older(string $a, string $b): bool {   // $a est-il STRICTEMENT antérieur à $b ?
    $pa = bo_ver_parts($a); $pb = bo_ver_parts($b); $n = max(count($pa), count($pb));
    for ($i = 0; $i < $n; $i++) { $x = $pa[$i] ?? 0; $y = $pb[$i] ?? 0; if ($x !== $y) return $x < $y; }
    return false;
}

function bo_run_update(): array {
    if (!function_exists('openssl_verify'))
        return ['ok'=>false,'error'=>"Vérification de signature indisponible sur ce serveur (OpenSSL manquant)."];

    $raw = bo_fetch(BO_UPDATE_BASEURL . 'version.json');
    if ($raw === null) return ['ok'=>false,'error'=>"Source de mise à jour injoignable (repo pas encore publié ?)."];
    $v = json_decode($raw, true);
    if (!is_array($v) || empty($v['version']) || empty($v['files']) || empty($v['sig']))
        return ['ok'=>false,'error'=>"Manifeste de mise à jour invalide."];

    // Signature (RSA-SHA256, OpenSSL)
    $sig = base64_decode((string)$v['sig'], true);
    if ($sig === false ||
        openssl_verify(bo_canonical($v), $sig, bo_pubkey_pem(), OPENSSL_ALGO_SHA256) !== 1)
        return ['ok'=>false,'error'=>"Signature invalide — mise à jour refusée (sécurité)."];

    // Déjà à jour ?
    $cur = is_file(BO_VERSION_FILE) ? (json_decode((string)file_get_contents(BO_VERSION_FILE), true)['version'] ?? '') : '';
    if ($cur === $v['version']) return ['ok'=>true,'updated'=>false,'version'=>$v['version'],'message'=>"Déjà à jour (".$v['version'].")."];
    // Anti-rollback : refuser un manifeste de version ANTÉRIEURE (rejeu d'un vieux version.json signé).
    if ($cur !== '' && bo_ver_is_older((string)$v['version'], $cur))
        return ['ok'=>false,'error'=>"Version proposée (".$v['version'].") antérieure à l'installée (".$cur."). Mise à jour refusée (sécurité)."];

    // 1) Télécharger + vérifier TOUT en mémoire
    $pending = [];
    foreach ($v['files'] as $f) {
        $dest = bo_dest_path((string)($f['dest'] ?? ''));
        if ($dest === null) return ['ok'=>false,'error'=>"Destination non autorisée : ".($f['dest'] ?? '')];
        $data = bo_fetch(BO_UPDATE_BASEURL . (string)$f['url']);
        if ($data === null) return ['ok'=>false,'error'=>"Téléchargement échoué : ".($f['url'] ?? '')];
        if (hash('sha256', $data) !== (string)($f['sha256'] ?? ''))
            return ['ok'=>false,'error'=>"Empreinte incorrecte : ".($f['url'] ?? '')." (mise à jour refusée)."];
        $pending[] = [$dest, $data];
    }
    // 2) Écrire (tout ou rien — déjà tout vérifié)
    foreach ($pending as [$dest, $data]) {
        @mkdir(dirname($dest), 0755, true);
        if (file_put_contents($dest, $data, LOCK_EX) === false)
            return ['ok'=>false,'error'=>"Écriture impossible : $dest"];
    }
    file_put_contents(BO_VERSION_FILE, json_encode(['version'=>$v['version'],'at'=>date('c')]));
    @chmod(BO_VERSION_FILE, 0600);
    return ['ok'=>true,'updated'=>true,'version'=>$v['version'],'message'=>"Mis à jour vers ".$v['version']." ✔"];
}
