<?php
/**
 * Régénère bo-package/dist/version.json signé (Ed25519) à partir des fichiers payload.
 * Usage :  BO_SEC="<clé privée base64>" php build_version.php 2026.06.28-2
 * (clé privée = BO_UPDATE_SECKEY dans .env.o2switch.local — JAMAIS dans le repo)
 * Puis pousser bo-package/dist/** vers le repo GitHub atequa/site-backoffice (dossier dist/).
 */
$sec = base64_decode(getenv('BO_SEC') ?: '');
$ver = $argv[1] ?? null;
if (strlen($sec) !== 64 || !$ver) { fwrite(STDERR, "BO_SEC (64o) + argument version requis\n"); exit(1); }

$root = __DIR__ . '/dist/';
$map = [
  ['payload/bo_llm.php',          'private/bo_llm.php'],
  ['payload/bo_providers.json',   'private/bo_providers.json'],
  ['payload/admin/index.php',     'docroot/admin/index.php'],
  ['payload/admin/api.php',       'docroot/admin/api.php'],
  ['payload/admin/preview.php',   'docroot/admin/preview.php'],
];
$files = [];
foreach ($map as [$url, $dest]) {
    $data = file_get_contents($root . $url);
    if ($data === false) { fwrite(STDERR, "manquant: $url\n"); exit(1); }
    $files[] = ['url' => $url, 'dest' => $dest, 'sha256' => hash('sha256', $data)];
}
$canon = $ver;
foreach ($files as $f) $canon .= "\n" . $f['url'] . "\t" . $f['dest'] . "\t" . $f['sha256'];
$sig = base64_encode(sodium_crypto_sign_detached($canon, $sec));
file_put_contents($root . 'version.json',
    json_encode(['version' => $ver, 'files' => $files, 'sig' => $sig], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "version.json signé : $ver\n";
