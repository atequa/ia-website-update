<?php
/**
 * Back-office — authentification sans mot de passe (magic link) + sessions "se souvenir".
 * HORS docroot. Inclus par admin/index.php et admin/api.php.
 */
require_once __DIR__ . '/bo_config.php';

function bo_json_read(string $f): array {
    if (!is_file($f)) return [];
    return json_decode((string)file_get_contents($f), true) ?: [];
}
function bo_json_write(string $f, array $a): void {
    file_put_contents($f, json_encode($a), LOCK_EX);
    @chmod($f, 0600);
}
function bo_hash(string $token): string {
    return hash_hmac('sha256', $token, BO_SIGNING_SECRET);
}
function bo_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}
function bo_email_authorized(string $email): bool {
    $email = strtolower(trim($email));
    foreach (BO_AUTHORIZED_EMAILS as $a) if (strtolower($a) === $email) return true;
    return false;
}

/* ---- Sessions "se souvenir" ---- */
function bo_current_user(): ?string {
    $tok = $_COOKIE['bo_session'] ?? '';
    if ($tok === '') return null;
    $store = bo_json_read(BO_TOKENS_FILE);
    $h = bo_hash($tok);
    $e = $store[$h] ?? null;
    if (!$e || ($e['expires'] ?? 0) < time()) return null;
    return $e['email'] ?? null;
}
function bo_login(string $email): void {
    $tok = bin2hex(random_bytes(32));
    $store = bo_json_read(BO_TOKENS_FILE);
    foreach ($store as $k => $v) if (($v['expires'] ?? 0) < time()) unset($store[$k]); // GC
    $store[bo_hash($tok)] = ['email' => strtolower($email), 'expires' => time() + BO_SESSION_TTL];
    bo_json_write(BO_TOKENS_FILE, $store);
    setcookie('bo_session', $tok, [
        'expires' => time() + BO_SESSION_TTL, 'path' => '/admin/',
        'httponly' => true, 'samesite' => 'Lax', 'secure' => bo_is_https(),
    ]);
}
function bo_logout(): void {
    $tok = $_COOKIE['bo_session'] ?? '';
    if ($tok !== '') {
        $store = bo_json_read(BO_TOKENS_FILE);
        unset($store[bo_hash($tok)]);
        bo_json_write(BO_TOKENS_FILE, $store);
    }
    setcookie('bo_session', '', ['expires' => time() - 3600, 'path' => '/admin/']);
}

/* ---- Magic links ---- */
function bo_create_magic(string $email): string {
    $tok = bin2hex(random_bytes(24));
    $store = bo_json_read(BO_MAGIC_FILE);
    foreach ($store as $k => $v) if (($v['expires'] ?? 0) < time()) unset($store[$k]); // GC
    $store[bo_hash($tok)] = ['email' => strtolower($email), 'expires' => time() + BO_MAGIC_TTL];
    bo_json_write(BO_MAGIC_FILE, $store);
    return $tok;
}
function bo_verify_magic(string $tok): ?string {
    if ($tok === '') return null;
    $store = bo_json_read(BO_MAGIC_FILE);
    $e = $store[bo_hash($tok)] ?? null;
    if (!$e || ($e['expires'] ?? 0) < time()) return null;
    // Réutilisable jusqu'à expiration (15 min) : évite que les scanners de liens
    // (aperçu chat, anti-spam Outlook/Gmail SafeLinks) grillent un lien à usage unique.
    return $e['email'] ?? null;
}
function bo_safe_host(): string {
    // L'hôte du magic link ne doit JAMAIS être pris tel quel dans HTTP_HOST (un pirate
    // peut le falsifier pour détourner le lien). On n'accepte HTTP_HOST que s'il
    // correspond au domaine de l'email d'envoi (ou un de ses sous-domaines, ex. preprod.) ;
    // sinon on retombe sur ce domaine.
    $parts = explode('@', BO_MAIL_FROM); $maild = strtolower(trim((string)end($parts)));
    $host  = preg_replace('/[^a-z0-9.\-:]/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
    $bare  = preg_replace('/:\d+$/', '', $host);
    if ($maild !== '' && ($bare === $maild ||
        (strlen($bare) > strlen($maild) + 1 && substr($bare, -(strlen($maild) + 1)) === '.' . $maild)))
        return $host;
    return $maild !== '' ? $maild : $bare;
}
function bo_magic_url(string $tok): string {
    $scheme = bo_is_https() ? 'https' : 'http';
    return $scheme . '://' . bo_safe_host() . '/admin/?login=' . urlencode($tok);
}
function bo_send_magic_link(string $email, string $url): bool {
    $subject = '=?UTF-8?B?' . base64_encode('Votre lien de connexion — ' . BO_SITE_NAME) . '?=';
    $body = "Bonjour,\r\n\r\nVoici votre lien de connexion à l'espace d'édition de " . BO_SITE_NAME . " :\r\n\r\n"
          . $url . "\r\n\r\nCe lien est valable 15 minutes et ne fonctionne qu'une fois.\r\n"
          . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.\r\n";
    $headers = [
        'From: ' . BO_SITE_NAME . ' <' . BO_MAIL_FROM . '>',
        'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    return @mail($email, $subject, $body, implode("\r\n", $headers), '-f' . BO_MAIL_FROM);
}

/* ---- Anti-abus simple (throttle par clé) ---- */
function bo_throttle(string $key, int $seconds): bool {
    $f = sys_get_temp_dir() . '/bo_thr_' . md5($key);
    if (is_file($f) && (time() - filemtime($f)) < $seconds) return false;
    @touch($f);
    return true;
}
