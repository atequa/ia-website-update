<?php
/**
 * Signe dist/control.json à partir de control.sites.json (source éditable).
 * Ajouter/modifier un site = éditer control.sites.json puis lancer ce script + git push.
 * usage : BO_SIGN_PRIVKEY_B64=<clé privée PEM base64> php build_control.php
 */
$privPem = base64_decode(getenv('BO_SIGN_PRIVKEY_B64') ?: '');
$pk = $privPem ? openssl_pkey_get_private($privPem) : false;
if ($pk === false) { fwrite(STDERR, "BO_SIGN_PRIVKEY_B64 (clé privée PEM base64) requis/invalide\n"); exit(1); }
$src = __DIR__ . '/control.sites.json';
$data = json_decode((string)file_get_contents($src), true);
if (!is_array($data) || !isset($data['sites'])) { fwrite(STDERR, "control.sites.json invalide\n"); exit(1); }
$payload = json_encode(
    ['version' => (string)($data['version'] ?? '1'), 'sites' => $data['sites']],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
openssl_sign($payload, $rawsig, $pk, OPENSSL_ALGO_SHA256);
$sig = base64_encode($rawsig);
file_put_contents(__DIR__ . '/dist/control.json',
    json_encode(['payload' => $payload, 'sig' => $sig], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "control.json signé — " . count($data['sites']) . " site(s) : " . implode(', ', array_keys($data['sites'])) . "\n";
