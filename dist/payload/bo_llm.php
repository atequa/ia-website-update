<?php
/**
 * Back-office V3 — fournisseurs IA + adaptateurs (PARTIE MISE À JOUR À DISTANCE).
 * Adaptateurs : anthropic · openai (OpenAI-compatible : Groq, OpenRouter, z.ai, Qwen,
 * DeepSeek, MiniMax, Cerebras, OpenAI, Mistral…) · gemini.
 */
require_once __DIR__ . '/bo_config.php';

/* ---- Fournisseurs (liste de fiches, mise à jour à distance) ---- */
function bo_providers(): array {
    if (!is_file(BO_PROVIDERS_FILE)) return [];
    return json_decode((string)file_get_contents(BO_PROVIDERS_FILE), true) ?: [];
}
function bo_provider(string $id): ?array {
    foreach (bo_providers() as $p) if (($p['id'] ?? '') === $id) return $p;
    return null;
}

/* ---- Fournisseur sélectionné + clés par fournisseur ---- */
function bo_secret(): array {
    if (!is_file(BO_SECRET_FILE)) return ['provider'=>'', 'keys'=>[]];
    $d = json_decode((string)file_get_contents(BO_SECRET_FILE), true) ?: [];
    return ['provider'=>(string)($d['provider'] ?? ''), 'keys'=>(array)($d['keys'] ?? [])];
}
function bo_secret_save(array $s): bool {
    $ok = file_put_contents(BO_SECRET_FILE, json_encode($s)) !== false;
    if ($ok) @chmod(BO_SECRET_FILE, 0600);
    return $ok;
}
function bo_selected_id(): string {
    $s = bo_secret();
    if ($s['provider'] !== '') return $s['provider'];
    $ps = bo_providers();
    return $ps[0]['id'] ?? '';
}
function bo_get_key(string $pid): string { return (string)(bo_secret()['keys'][$pid] ?? ''); }
function bo_set_key(string $pid, string $key): bool { $s = bo_secret(); $s['keys'][$pid] = $key; if ($s['provider']==='') $s['provider']=$pid; return bo_secret_save($s); }
function bo_set_provider(string $pid): bool { $s = bo_secret(); $s['provider'] = $pid; return bo_secret_save($s); }
function bo_is_configured(): bool {
    $pid = bo_selected_id();
    return $pid !== '' && bo_get_key($pid) !== '';
}

/* ---- JSON robuste ---- */
function bo_extract_json(string $t): ?array {
    $d = json_decode($t, true);
    if (is_array($d)) return $d;
    $a = strpos($t, '{'); $b = strrpos($t, '}');
    if ($a !== false && $b !== false && $b > $a) {
        $d = json_decode(substr($t, $a, $b - $a + 1), true);
        if (is_array($d)) return $d;
    }
    return null;
}

/* ---- Schéma + prompt commun ---- */
function bo_json_instr(): string {
    return "Réponds UNIQUEMENT par un objet JSON valide, sans texte autour, de la forme : "
         . '{"summary": "résumé en français de ce que tu as changé, fichier par fichier", '
         . '"changes": [{"path": "nom_fichier.html", "new_content": "contenu COMPLET réécrit du fichier"}]}. '
         . "Ne renvoie QUE les fichiers réellement modifiés ; si rien/impossible, changes = [].";
}

/* ---- Adaptateur Anthropic ---- */
function bo_edit_anthropic(array $p, string $key, string $rules, string $corpus, string $req): array {
    $schema = ['type'=>'object','properties'=>[
        'summary'=>['type'=>'string'],
        'changes'=>['type'=>'array','items'=>['type'=>'object','properties'=>[
            'path'=>['type'=>'string'],'new_content'=>['type'=>'string']],
            'required'=>['path','new_content'],'additionalProperties'=>false]],
    ],'required'=>['summary','changes'],'additionalProperties'=>false];
    $body = ['model'=>$p['model'],'max_tokens'=>BO_MAX_TOKENS,
        'system'=>[
            ['type'=>'text','text'=>$rules,'cache_control'=>['type'=>'ephemeral']],
            ['type'=>'text','text'=>"CONTENU ACTUEL DES FICHIERS :\n".$corpus,'cache_control'=>['type'=>'ephemeral']],
        ],
        'messages'=>[['role'=>'user','content'=>"Demande :\n".$req]],
        'output_config'=>['format'=>['type'=>'json_schema','schema'=>$schema]]];
    [$http,$resp,$err] = bo_http(rtrim($p['base_url'],'/').'/v1/messages', $body,
        ['x-api-key: '.$key,'anthropic-version: 2023-06-01','content-type: application/json']);
    if ($resp===null) return ['ok'=>false,'error'=>"Connexion impossible : $err"];
    $d = json_decode($resp, true);
    if ($http!==200) return ['ok'=>false,'error'=>bo_api_err($http, $d['error']['message'] ?? "HTTP $http")];
    if (($d['stop_reason'] ?? '')==='refusal') return ['ok'=>false,'error'=>"Demande refusée par le modèle."];
    $text=''; foreach (($d['content'] ?? []) as $b) if (($b['type'] ?? '')==='text'){ $text=$b['text']; break; }
    $u=$d['usage'] ?? [];
    return ['ok'=>true,'parsed'=>bo_extract_json($text),'in'=>(int)($u['input_tokens']??0),'out'=>(int)($u['output_tokens']??0)];
}

/* ---- Adaptateur OpenAI-compatible ---- */
function bo_edit_openai(array $p, string $key, string $rules, string $corpus, string $req): array {
    $sys = $rules . "\n\n" . bo_json_instr();
    $usr = "CONTENU ACTUEL DES FICHIERS :\n".$corpus."\n\nDemande :\n".$req;
    // paramètres "extra" propres au fournisseur (ex. z.ai : {"thinking":{"type":"disabled"}} pour
    // couper la phase de réflexion de GLM-4.6, sinon réponses très lentes → dépassement de délai).
    $extra = (isset($p['extra']) && is_array($p['extra'])) ? $p['extra'] : [];
    $mk = fn($withFmt) => array_merge([
        'model'=>$p['model'],'max_tokens'=>BO_MAX_TOKENS,
        'messages'=>[['role'=>'system','content'=>$sys],['role'=>'user','content'=>$usr]],
    ], $extra, $withFmt ? ['response_format'=>['type'=>'json_object']] : []);
    $rp = explode('@', BO_MAIL_FROM); $rhost = strtolower(trim((string)end($rp)));
    $hdr = ['Authorization: Bearer '.$key,'content-type: application/json',
            'HTTP-Referer: https://'.($rhost !== '' ? $rhost : 'localhost'),'X-Title: '.BO_SITE_NAME];
    $url = rtrim($p['base_url'],'/').'/chat/completions';
    [$http,$resp,$err] = bo_http($url, $mk(true), $hdr);
    if ($http===400) { [$http,$resp,$err] = bo_http($url, $mk(false), $hdr); } // certains modèles refusent response_format
    if ($resp===null) return ['ok'=>false,'error'=>"Connexion impossible : $err"];
    $d = json_decode($resp, true);
    if ($http!==200) return ['ok'=>false,'error'=>bo_api_err($http, $d['error']['message'] ?? "HTTP $http")];
    $text = $d['choices'][0]['message']['content'] ?? '';
    $u=$d['usage'] ?? [];
    return ['ok'=>true,'parsed'=>bo_extract_json((string)$text),'in'=>(int)($u['prompt_tokens']??0),'out'=>(int)($u['completion_tokens']??0)];
}

/* ---- Adaptateur Google Gemini ---- */
function bo_edit_gemini(array $p, string $key, string $rules, string $corpus, string $req): array {
    $url = rtrim($p['base_url'],'/').'/v1beta/models/'.$p['model'].':generateContent?key='.urlencode($key);
    $body = [
        'system_instruction'=>['parts'=>[['text'=>$rules."\n\n".bo_json_instr()]]],
        'contents'=>[['role'=>'user','parts'=>[['text'=>"CONTENU ACTUEL DES FICHIERS :\n".$corpus."\n\nDemande :\n".$req]]]],
        // thinkingBudget=0 : désactive la phase de "réflexion" de Gemini 2.5 Flash → réponses bien plus rapides (suffisant pour de l'édition).
        'generationConfig'=>['response_mime_type'=>'application/json','maxOutputTokens'=>BO_MAX_TOKENS,'thinkingConfig'=>['thinkingBudget'=>0]],
    ];
    [$http,$resp,$err] = bo_http($url, $body, ['content-type: application/json']);
    if ($resp===null) return ['ok'=>false,'error'=>"Connexion impossible : $err"];
    $d = json_decode($resp, true);
    if ($http!==200) return ['ok'=>false,'error'=>bo_api_err($http, $d['error']['message'] ?? "HTTP $http")];
    $text = $d['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $u=$d['usageMetadata'] ?? [];
    return ['ok'=>true,'parsed'=>bo_extract_json((string)$text),'in'=>(int)($u['promptTokenCount']??0),'out'=>(int)($u['candidatesTokenCount']??0)];
}

/* ---- Dispatch ---- */
function bo_llm_edit(array $p, string $key, string $rules, string $corpus, string $req): array {
    switch ($p['api'] ?? '') {
        case 'anthropic': return bo_edit_anthropic($p,$key,$rules,$corpus,$req);
        case 'gemini':    return bo_edit_gemini($p,$key,$rules,$corpus,$req);
        case 'openai':    return bo_edit_openai($p,$key,$rules,$corpus,$req);
        default:          return ['ok'=>false,'error'=>"Type de fournisseur inconnu."];
    }
}

/* ---- HTTP + messages d'erreur ---- */
function bo_http(string $url, array $body, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>180, CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_HTTPHEADER=>$headers, CURLOPT_POSTFIELDS=>json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $resp = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return [$http, $resp===false ? null : $resp, $err];
}
function bo_api_err(int $http, string $msg): string {
    if ($http===401 || $http===403) return "Clé refusée. Vérifiez/mettez à jour votre clé pour ce fournisseur.";
    if ($http===429) return "Limite du fournisseur atteinte (quota/débit). Réessayez plus tard ou changez de fournisseur.";
    if ($http===400 && stripos($msg,'credit')!==false) return "Crédits épuisés chez ce fournisseur.";
    return "Erreur du fournisseur : ".$msg;
}
