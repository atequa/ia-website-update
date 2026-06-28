<?php
/**
 * Régénère bo-package/dist/version.json signé (RSA-SHA256, OpenSSL — portable partout) à partir des fichiers payload.
 * Usage :  BO_SIGN_PRIVKEY_B64="<clé privée PEM base64>" php build_version.php 2026.06.28-2
 * (clé privée = BO_SIGN_PRIVKEY_B64 dans .env.backoffice — JAMAIS dans le repo ni sur un site)
 * Puis pousser bo-package/dist/** vers le repo GitHub atequa/ia-website-update (dossier dist/).
 */
$privPem = base64_decode(getenv('BO_SIGN_PRIVKEY_B64') ?: '');
$ver = $argv[1] ?? null;
if (!$privPem || !$ver) { fwrite(STDERR, "BO_SIGN_PRIVKEY_B64 (clé privée PEM base64) + argument version requis\n"); exit(1); }
$pk = openssl_pkey_get_private($privPem);
if ($pk === false) { fwrite(STDERR, "Clé privée RSA invalide\n"); exit(1); }

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
openssl_sign($canon, $rawsig, $pk, OPENSSL_ALGO_SHA256);
$sig = base64_encode($rawsig);
file_put_contents($root . 'version.json',
    json_encode(['version' => $ver, 'files' => $files, 'sig' => $sig], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "version.json signé : $ver\n";
