<?php
/**
 * Back-office V3 — plan de contrôle à distance (kill switch + mode géré/autonome).
 * Racine de confiance LOCALE (jamais mise à jour à distance) : vérifie la signature
 * Ed25519 de control.json avec la clé publique locale. Cache + tolérance de panne :
 *  - un "kill" déjà reçu PERSISTE même si GitHub devient injoignable ;
 *  - si aucun contrôle n'existe encore, on ne bloque pas (fail-open) et on ne re-tente
 *    qu'une fois par TTL (pas de latence à chaque chargement).
 *
 * control.json (signé par Manu) :
 *   {"payload":"{\"version\":\"..\",\"sites\":{\"educ-care\":{\"enabled\":true,\"mode\":\"self\"}}}",
 *    "sig":"<base64 Ed25519 sur la chaîne payload>"}
 */
require_once __DIR__ . '/bo_config.php';

function bo_ctrl_verify(string $payload, string $sig): bool {
    if (!function_exists('sodium_crypto_sign_verify_detached')) return false;
    $s = base64_decode($sig, true); $p = base64_decode(BO_UPDATE_PUBKEY, true);
    return $s !== false && $p !== false && sodium_crypto_sign_verify_detached($s, $payload, $p);
}
function bo_ctrl_parse(string $payload): array {
    $def = ['enabled'=>true, 'mode'=>BO_DEFAULT_MODE, 'message'=>''];
    $d = json_decode($payload, true) ?: [];
    $site = $d['sites'][BO_SITE_ID] ?? null;
    if (!is_array($site)) return $def;
    return [
        'enabled' => array_key_exists('enabled',$site) ? (bool)$site['enabled'] : true,
        'mode'    => in_array(($site['mode'] ?? ''),['self','managed'],true) ? $site['mode'] : BO_DEFAULT_MODE,
        'message' => (string)($site['message'] ?? ''),
    ];
}
function bo_ctrl_save(array $c): void { @file_put_contents(BO_CONTROL_CACHE, json_encode($c), LOCK_EX); @chmod(BO_CONTROL_CACHE, 0600); }

function bo_control_state(): array {
    $def = ['enabled'=>true, 'mode'=>BO_DEFAULT_MODE, 'message'=>''];
    $cache = is_file(BO_CONTROL_CACHE) ? (json_decode((string)file_get_contents(BO_CONTROL_CACHE), true) ?: []) : [];
    $fresh = isset($cache['fetched']) && (time() - (int)$cache['fetched']) < BO_CONTROL_TTL;

    if ($fresh) {
        if (!empty($cache['payload']) && bo_ctrl_verify($cache['payload'], $cache['sig'] ?? '')) return bo_ctrl_parse($cache['payload']);
        return $def; // négatif récent
    }
    // périmé ou absent → tenter un fetch court
    $ch = curl_init(BO_CONTROL_URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5, CURLOPT_FOLLOWLOCATION=>true]);
    $raw = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw !== false && $http === 200) {
        $j = json_decode($raw, true);
        if (isset($j['payload'],$j['sig']) && bo_ctrl_verify((string)$j['payload'], (string)$j['sig'])) {
            bo_ctrl_save(['payload'=>$j['payload'],'sig'=>$j['sig'],'fetched'=>time()]);
            return bo_ctrl_parse((string)$j['payload']);
        }
    }
    // fetch KO : préserver un contrôle vérifié antérieur (le kill persiste), sinon négatif
    if (!empty($cache['payload']) && bo_ctrl_verify($cache['payload'], $cache['sig'] ?? '')) {
        $cache['fetched'] = time(); bo_ctrl_save($cache); // throttle des re-tentatives
        return bo_ctrl_parse($cache['payload']);
    }
    bo_ctrl_save(['none'=>true,'fetched'=>time()]);
    return $def;
}
