<?php
/**
 * Régénère dist-perf/version.json signé (RSA-SHA256) à partir de dist-perf/payload/perf_gen.php.
 * Réutilise la clé privée maîtresse (la même que le back-office).
 * Usage :  BO_SIGN_PRIVKEY_B64="<clé privée PEM base64>" php build_perf_version.php 2026.07.10-1
 * Puis pousser dist-perf/** vers atequa/ia-website-update (branche main).
 */
/* Clé privée DÉDIÉE au module perf (PERF_SIGN_PRIVKEY_B64), avec repli sur l'ancienne
   variable pour compat. En CI, c'est le secret GitHub PERF_SIGN_PRIVKEY_B64. */
$privPem = base64_decode(getenv('PERF_SIGN_PRIVKEY_B64') ?: getenv('BO_SIGN_PRIVKEY_B64') ?: '');
$ver = $argv[1] ?? null;
if (!$privPem || !$ver) { fwrite(STDERR, "PERF_SIGN_PRIVKEY_B64 + version requis\n"); exit(1); }
$pk = openssl_pkey_get_private($privPem);
if ($pk === false) { fwrite(STDERR, "Clé privée RSA invalide\n"); exit(1); }

$root = __DIR__ . '/dist-perf/';
$map = [
    ['payload/perf_gen.php', 'payload/perf_gen.php'],   // url (dans dist-perf/), dest (jugée par perf_dest_path)
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

file_put_contents($root . 'version.json',
    json_encode(['version' => $ver, 'enabled' => true, 'files' => $files, 'sig' => base64_encode($rawsig)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "dist-perf/version.json signé : $ver\n";
