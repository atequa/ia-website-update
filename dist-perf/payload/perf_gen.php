<?php
/* PERF_ENGINE_VERSION: 2026.07.10 (moteur central mutualisé — MAJ signée + auto-publiée via CI ia-website-update) */
/**
 * Générateur de la page publique « Performances du site » (skill site-perf-page).
 * Exécuté par cron cPanel : php /home/<user>/clients/<site>/private/perf_gen.php
 * - Lit perf_config.php et perf_template.html dans le même dossier (HORS docroot)
 * - Accumule l'historique des passages de bots IA dans perf_state.json (90 j glissants)
 * - Mesure poids de page, certificat SSL, PageSpeed (option), uptime (option)
 * - Écrit la page STATIQUE dans le docroot (écriture atomique tmp+rename)
 * Aucune donnée personnelle : seuls les user-agents de robots sont agrégés.
 */
error_reporting(0);
date_default_timezone_set('Europe/Paris');
const PERF_ENGINE_VERSION_STR = '2026.07.10-1';

$C = require __DIR__ . '/perf_config.php';
$stateFile = __DIR__ . '/perf_state.json';
$state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : [];
if (!is_array($state)) $state = [];
$state += ['bots' => [], 'filepos' => [], 'psi' => null, 'installed' => date('Y-m-d')];

/* ============ 1. Passages des bots IA (logs Apache, archives .gz + raw) ============ */
$BOTS_IA = [
    'ChatGPT (OpenAI)'   => '/GPTBot|OAI-SearchBot|ChatGPT-User/i',
    'Claude (Anthropic)' => '/ClaudeBot|Claude-User|Claude-SearchBot|anthropic-ai/i',
    'Perplexity'         => '/PerplexityBot|Perplexity-User/i',
    'IA Google (Gemini)' => '/Google-Extended|GoogleOther/i',
    'Mistral'            => '/MistralAI/i',
    'Autres IA'          => '/Applebot-Extended|meta-externalagent|Amazonbot|Bytespider|cohere-ai|CCBot|Diffbot|PetalBot/i',
];
$BOTS_SEARCH = [
    'Google' => '/Googlebot/i',
    'Bing'   => '/bingbot/i',
];

$logFiles = [];
foreach ((array)$C['log_globs'] as $g) {
    foreach ((array)glob($g) as $f) {
        $b = basename($f);
        if (stripos($b, 'preprod') !== false || stripos($b, 'ftp_log') !== false) continue;
        $logFiles[] = $f;
    }
}

function perf_parse_line($line, $BOTS_IA, $BOTS_SEARCH, &$state) {
    if (!preg_match('/\[(\d{2}\/[A-Za-z]{3}\/\d{4}):(\d{2}:\d{2}:\d{2}) [+\-]\d{4}\]/', $line, $m)) return;
    if (!preg_match('/"([^"]*)"\s*$/', $line, $u)) return;
    $ua = $u[1];
    if ($ua === '' || $ua === '-') return;
    $ts = strtotime(str_replace('/', ' ', $m[1]) . ' ' . $m[2]);
    if (!$ts) return;
    $day = date('Y-m-d', $ts);
    foreach ([$BOTS_IA, $BOTS_SEARCH] as $set) {
        foreach ($set as $name => $re) {
            if (preg_match($re, $ua)) {
                if (!isset($state['bots'][$name])) $state['bots'][$name] = ['days' => [], 'last' => 0];
                $state['bots'][$name]['days'][$day] = ($state['bots'][$name]['days'][$day] ?? 0) + 1;
                if ($ts > $state['bots'][$name]['last']) $state['bots'][$name]['last'] = $ts;
                return;
            }
        }
    }
}

foreach ($logFiles as $f) {
    $key = basename($f);
    $lastTs = $state['filepos'][$key] ?? 0;   // timestamp de la dernière ligne déjà comptée
    $maxTs = $lastTs;
    $isGz = substr($f, -3) === '.gz';
    $h = $isGz ? gzopen($f, 'rb') : fopen($f, 'rb');
    if (!$h) continue;
    while (($line = $isGz ? gzgets($h, 8192) : fgets($h, 8192)) !== false) {
        if (!preg_match('/\[(\d{2}\/[A-Za-z]{3}\/\d{4}):(\d{2}:\d{2}:\d{2}) [+\-]\d{4}\]/', $line, $m)) continue;
        $ts = strtotime(str_replace('/', ' ', $m[1]) . ' ' . $m[2]);
        if (!$ts || $ts <= $lastTs) continue;
        if ($ts > $maxTs) $maxTs = $ts;
        perf_parse_line($line, $BOTS_IA, $BOTS_SEARCH, $state);
    }
    $isGz ? gzclose($h) : fclose($h);
    $state['filepos'][$key] = $maxTs;
}

/* purge > 92 jours */
$cutoff = date('Y-m-d', strtotime('-92 days'));
foreach ($state['bots'] as $name => $b) {
    foreach ($b['days'] as $d => $n) if ($d < $cutoff) unset($state['bots'][$name]['days'][$d]);
}

function perf_count($state, $name, $days) {
    $from = date('Y-m-d', strtotime("-$days days"));
    $n = 0;
    foreach (($state['bots'][$name]['days'] ?? []) as $d => $c) if ($d >= $from) $n += $c;
    return $n;
}
function perf_ago($ts) {
    if (!$ts) return 'pas encore vu';
    $d = time() - $ts;
    if ($d < 3600) return 'il y a moins d\'une heure';
    if ($d < 86400) return 'il y a ' . max(1, round($d / 3600)) . ' h';
    if ($d < 172800) return 'hier';
    return 'il y a ' . round($d / 86400) . ' jours';
}

$aiTotal30 = 0;
$aiRows = '';
foreach ($BOTS_IA as $name => $re) {
    $n30 = perf_count($state, $name, 30);
    $aiTotal30 += $n30;
    if ($n30 === 0 && !isset($state['bots'][$name])) continue;
    $last = perf_ago($state['bots'][$name]['last'] ?? 0);
    $aiRows .= "<tr><td>" . htmlspecialchars($name) . "</td><td>{$n30}</td><td>" . htmlspecialchars($last) . "</td></tr>\n";
}
if ($aiRows === '') $aiRows = '<tr><td colspan="3">Comptage démarré le ' . date('d/m/Y', strtotime($state['installed'])) . ' — premières visites en cours d\'enregistrement.</td></tr>';

$searchLine = [];
foreach ($BOTS_SEARCH as $name => $re) {
    $n = perf_count($state, $name, 30);
    if ($n > 0) $searchLine[] = htmlspecialchars($name) . ' : ' . $n . ' passages';
}
$searchLine = $searchLine ? ('Moteurs de recherche classiques (30 j) — ' . implode(' · ', $searchLine) . '.') : '';

$periodLabel = ($state['installed'] > date('Y-m-d', strtotime('-30 days')))
    ? 'depuis le ' . date('d/m/Y', strtotime($state['installed']))
    : '30 derniers jours';

/* ============ 2. Poids de la première visite (transfert réel, compressé) ============ */
$weightBytes = 0; $reqCount = 0;
foreach ((array)$C['weight_urls'] as $u) {
    $ch = curl_init($C['site_url'] . $u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_ENCODING => '', CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'perf-page-generator']);
    if (curl_exec($ch) !== false) {
        $weightBytes += (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $reqCount++;
    }
    curl_close($ch);
}
$weightKo = max(1, (int)round($weightBytes / 1024));
$weightFactor = $weightBytes > 0 ? (int)round(2600 / ($weightBytes / 1024)) : 0; /* page web médiane ≈ 2,6 Mo (HTTP Archive) */

/* ============ 3. Certificat SSL ============ */
$sslDate = ''; $sslTs = null;
$ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
$sock = @stream_socket_client('ssl://' . $C['domain'] . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
if ($sock) {
    $params = stream_context_get_params($sock);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    if (!empty($cert['validTo_time_t'])) {
        $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $t = $cert['validTo_time_t']; $sslTs = (int)$t;
        $sslDate = date('j', $t) . ' ' . $mois[(int)date('n', $t)] . ' ' . date('Y', $t);
    }
    fclose($sock);
}

/* ============ 4. Google PageSpeed (option, rafraîchi tous les 7 jours) ============
   Sans clé API le quota par IP est vite épuisé (429) sur une IP mutualisée :
   une seule TENTATIVE par 20 h, et une clé gratuite peut être fournie via psi_api_key. */
if (!empty($C['psi'])) {
    $lastOk = $state['psi']['ts'] ?? 0;
    $lastTry = $state['psi_try'] ?? 0;
    if (time() - $lastOk > 7 * 86400 && time() - $lastTry > 20 * 3600) {
        $state['psi_try'] = time();
        $got = ['ts' => time()];
        foreach (['mobile', 'desktop'] as $strat) {
            $url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' . urlencode($C['site_url'] . '/') . '&strategy=' . $strat . '&category=performance&locale=fr';
            if (!empty($C['psi_api_key'])) $url .= '&key=' . urlencode($C['psi_api_key']);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $j = $resp ? json_decode($resp, true) : null;
            $score = $j['lighthouseResult']['categories']['performance']['score'] ?? null;
            if ($score !== null) {
                $got[$strat] = [
                    'score' => (int)round($score * 100),
                    'lcp' => $j['lighthouseResult']['audits']['largest-contentful-paint']['displayValue'] ?? '',
                ];
            }
        }
        if (isset($got['mobile']) || isset($got['desktop'])) $state['psi'] = $got;
    }
}
/* Lecture PageSpeed avec compat ascendante (ancien état plat {score,lcp} → mobile) */
$psi = $state['psi'] ?? [];
if (isset($psi['score']) && !isset($psi['mobile'])) $psi['mobile'] = ['score' => $psi['score'], 'lcp' => $psi['lcp'] ?? ''];
$fmtLcp = static function ($s) { return trim(str_replace('&nbsp;', ' ', (string)$s)); };
$mob  = $psi['mobile'] ?? null;
$desk = $psi['desktop'] ?? null;
$psiDate   = isset($psi['ts']) ? date('d/m/Y', $psi['ts']) : '';
$psiScore  = $mob['score'] ?? null;                 /* rétro-compat : placeholders séparés */
$psiLcp    = $mob ? $fmtLcp($mob['lcp']) : '';
$psiScoreD = $desk['score'] ?? null;
$psiLcpD   = $desk ? $fmtLcp($desk['lcp']) : '';

/* Bloc PageSpeed auto-porté (mobile + ordinateur) — placeholder {{PSI_BLOCK}} :
   c'est le moteur qui décide de la structure de la carte → une évolution centralisée
   (ajout du desktop, etc.) s'affiche partout sans retoucher les templates par site. */
if ($mob !== null || $desk !== null) {
    $psiBlock = '';
    if ($mob !== null)  $psiBlock .= '<p class="perf-num">' . (int)$mob['score'] . '<small> /100 sur mobile</small></p>';
    if ($desk !== null) $psiBlock .= '<p class="perf-num" style="font-size:1.9rem">' . (int)$desk['score'] . '<small> /100 sur ordinateur</small></p>';
    $lcpParts = [];
    if ($mob && $fmtLcp($mob['lcp']))   $lcpParts[] = 'mobile ' . $fmtLcp($mob['lcp']);
    if ($desk && $fmtLcp($desk['lcp'])) $lcpParts[] = 'ordinateur ' . $fmtLcp($desk['lcp']);
    $psiBlock .= '<p>Affichage du contenu principal : ' . ($lcpParts ? implode(' · ', $lcpParts) : 'mesure en cours')
              . '. Mesure Google du ' . ($psiDate ?: 'première mesure en cours') . '.</p>';
} else {
    $psiBlock = '<p class="perf-num">—<small> /100</small></p><p>Première mesure Google en cours.</p>';
}

/* ============ 5. Uptime (option UptimeRobot) ============ */
$uptimeHtml = '';
if (!empty($C['uptimerobot']['api_key'])) {
    $ch = curl_init('https://api.uptimerobot.com/v2/getMonitors');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query(['api_key' => $C['uptimerobot']['api_key'], 'custom_uptime_ratios' => '30-90', 'format' => 'json'])]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $j = $resp ? json_decode($resp, true) : null;
    $ratio = $j['monitors'][0]['custom_uptime_ratio'] ?? null;
    if ($ratio) {
        [$r30, $r90] = array_pad(explode('-', $ratio), 2, '');
        $r30 = str_replace('.', ',', (string)round((float)$r30, 2));
        $uptimeHtml = 'Disponibilité mesurée en continu : <strong>' . $r30 . ' %</strong> sur 30 jours.';
    }
}

/* ============ 6. Rendu ============ */
$tpl = (string)file_get_contents(__DIR__ . '/perf_template.html');
$repl = [
    '{{DATE_MAJ}}'      => date('d/m/Y à H\hi'),
    '{{AI_TOTAL}}'      => (string)$aiTotal30,
    '{{AI_PERIODE}}'    => $periodLabel,
    '{{AI_ROWS}}'       => $aiRows,
    '{{SEARCH_LINE}}'   => $searchLine,
    '{{POIDS_KO}}'      => (string)$weightKo,
    '{{POIDS_FOIS}}'    => (string)$weightFactor,
    '{{NB_REQ}}'        => (string)$reqCount,
    '{{SSL_DATE}}'      => $sslDate ?: 'renouvellement automatique',
    '{{PSI_BLOCK}}'        => $psiBlock,
    '{{PSI_SCORE}}'        => $psiScore !== null ? (string)$psiScore : '—',
    '{{PSI_LCP}}'          => $psiLcp !== '' ? $psiLcp : 'mesure en cours',
    '{{PSI_DATE}}'         => $psiDate ?: 'première mesure en cours',
    '{{PSI_SCORE_DESKTOP}}'=> $psiScoreD !== null ? (string)$psiScoreD : '—',
    '{{PSI_LCP_DESKTOP}}'  => $psiLcpD !== '' ? $psiLcpD : 'mesure en cours',
    '{{UPTIME_HTML}}'      => $uptimeHtml,
];
$html = strtr($tpl, $repl);

$out = rtrim($C['docroot'], '/') . '/' . ($C['output'] ?? 'performances.html');
$tmp = $out . '.tmp';
if (file_put_contents($tmp, $html) !== false) rename($tmp, $out);

/* ============ 7. État machine pour le panneau de contrôle Atequa ============
   Petit JSON non personnel publié dans /.well-known/, agrégé par panel.atequa-web.com. */
$engineVer = PERF_ENGINE_VERSION_STR;
$vf = __DIR__ . '/perf_version.json';
if (is_file($vf)) { $vv = json_decode((string)file_get_contents($vf), true); if (!empty($vv['version'])) $engineVer = $vv['version']; }
$boVer = null;
$bvf = __DIR__ . '/bo_version.json';
if (is_file($bvf)) { $bv = json_decode((string)file_get_contents($bvf), true); if (!empty($bv['version'])) $boVer = $bv['version']; }
$aiByBot = [];
foreach ($BOTS_IA as $name => $re) { $c = perf_count($state, $name, 30); if ($c > 0) $aiByBot[$name] = $c; }
$status = [
    'site'      => $C['domain'],
    'generated' => date('c'),
    'engine'    => $engineVer,
    'backoffice'=> $boVer,
    'ssl_until' => $sslDate,
    'ssl_ts'    => $sslTs,
    'psi'       => ['mobile' => $psiScore, 'desktop' => $psiScoreD, 'date' => $psiDate],
    'ai_30d'    => $aiTotal30,
    'ai_by_bot' => $aiByBot,
    'weight_ko' => $weightKo,
];
$wd = rtrim($C['docroot'], '/') . '/.well-known';
@mkdir($wd, 0755, true);
$sf = $wd . '/atequa-status.json'; $stmp = $sf . '.tmp';
if (file_put_contents($stmp, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) rename($stmp, $sf);

file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE));
echo "OK " . date('c') . " ai30={$aiTotal30} poids={$weightKo}Ko\n";
