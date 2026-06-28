<?php
/**
 * Signe dist/control.json à partir de control.sites.json (source éditable).
 * Ajouter/modifier un site = éditer control.sites.json puis lancer ce script + git push.
 * usage : BO_SEC=<BO_UPDATE_SECKEY> php build_control.php
 */
$sec = base64_decode(getenv('BO_SEC') ?: '');
if (strlen($sec) !== 64) { fwrite(STDERR, "BO_SEC (clé privée base64, 64o) requis\n"); exit(1); }
$src = __DIR__ . '/control.sites.json';
$data = json_decode((string)file_get_contents($src), true);
if (!is_array($data) || !isset($data['sites'])) { fwrite(STDERR, "control.sites.json invalide\n"); exit(1); }
$payload = json_encode(
    ['version' => (string)($data['version'] ?? '1'), 'sites' => $data['sites']],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
$sig = base64_encode(sodium_crypto_sign_detached($payload, $sec));
file_put_contents(__DIR__ . '/dist/control.json',
    json_encode(['payload' => $payload, 'sig' => $sig], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "control.json signé — " . count($data['sites']) . " site(s) : " . implode(', ', array_keys($data['sites'])) . "\n";
