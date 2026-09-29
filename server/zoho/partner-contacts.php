<?php
/**
 * partner-contacts.php — contactos de Zoho por etapa, para el socio educativo.
 *
 * Va en onlineeducation.center/connections/zoho/, junto a zoho-config.php.
 * Lo llama el plugin de WordPress desde el SERVIDOR del sitio del socio (nunca
 * el navegador), con el token de la API de OEC de ese socio:
 *
 *   GET partner-contacts.php?action=counts&training_uid=t-XXXXXXXXXXXXXX[&edition_uid=te-XXXXXXXXXXXXXX]
 *   GET partner-contacts.php?action=contacts&training_uid=t-XXXXXXXXXXXXXX[&edition_uid=…]&stage=Hot%20Prospects
 *
 * Sin edition_uid cuenta todas las ediciones de la formación; con él, solo esa
 * (el campo Edition_UID del Deal = edition_uid de la API de OEC).
 *   Header: X-API-TOKEN: <token del socio>
 *
 * Solo responde por formaciones que la API de OEC le devuelve a ESE token
 * (el token de un socio ve las de su organización; el maestro ve todas).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

const STAGES      = ['Cold Prospects', 'More Info Request', 'Hot Prospects', 'Orders Not Finished', 'Students - Won'];
const DATA_DIR    = __DIR__ . '/data';
const OAS_API     = 'https://oas-api.onlineeducation.center/api-oas/v1/';
const ZOHO_API    = 'https://www.zohoapis.com/crm/v8/';
const COUNTS_TTL  = 900;   // 15 min: los números se piden seguido desde wp-admin
const OWNERS_TTL  = 3600;  // formaciones de cada token
const MAX_PAGES   = 10;    // la búsqueda de Zoho corta en 2.000 registros (10 x 200)

function reply($code, $body) {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// === 1. ENTRADA ===========================================================
$config = @include __DIR__ . '/zoho-config.php';
if (!is_array($config) || empty($config['client_id']) || empty($config['client_secret']) || empty($config['refresh_token'])) {
    reply(500, ['error' => 'config']);
}

$api_token = trim($_SERVER['HTTP_X_API_TOKEN'] ?? '');
$action    = $_GET['action'] ?? '';
$uid       = $_GET['training_uid'] ?? '';
$stage     = $_GET['stage'] ?? '';
$edition   = $_GET['edition_uid'] ?? '';

if ($api_token === '' || strlen($api_token) > 200) reply(401, ['error' => 'token']);
// El uid se usa dentro de un filtro de Zoho: solo el formato exacto, nada más.
if (!preg_match('/^t-[A-Za-z0-9]{14}$/', $uid)) reply(400, ['error' => 'training_uid']);
if ($edition !== '' && !preg_match('/^te-[A-Za-z0-9]{14}$/', $edition)) reply(400, ['error' => 'edition_uid']);
if (!in_array($action, ['counts', 'contacts'], true)) reply(400, ['error' => 'action']);
if ($action === 'contacts' && !in_array($stage, STAGES, true)) reply(400, ['error' => 'stage']);

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
if (!file_exists(DATA_DIR . '/.htaccess')) file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");

// === 2. HTTP ==============================================================
function http_get_many(array $urls, array $headers) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $k => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1);
    } while ($running && $status === CURLM_OK);
    $out = [];
    foreach ($handles as $k => $ch) {
        $out[$k] = ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => json_decode(curl_multi_getcontent($ch), true)];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function http_get($url, array $headers) {
    return http_get_many(['x' => $url], $headers)['x'];
}

function cache_get($name, $ttl) {
    $file = DATA_DIR . '/' . $name . '.json';
    if (!file_exists($file) || filemtime($file) < time() - $ttl) return null;
    return json_decode(file_get_contents($file), true);
}

function cache_set($name, $data) {
    file_put_contents(DATA_DIR . '/' . $name . '.json', json_encode($data), LOCK_EX);
}

// === 3. ¿ESTA FORMACIÓN ES DE ESTE TOKEN? =================================
// Lista todas las formaciones que la API de OEC le da al token (en paralelo,
// cacheado 1 h por token). Un socio tiene ~12: un solo pedido.
function token_owns($api_token, $uid) {
    $key    = 'owners_' . hash('sha256', $api_token);
    $owners = cache_get($key, OWNERS_TTL);
    if (is_array($owners) && isset($owners[$uid])) return true;
    // Una formación que no está en una lista revisada hace menos de 5 min no se
    // vuelve a buscar: con el token maestro son ~74 pedidos y cualquiera podría
    // forzarlos en cada llamada con un uid inventado.
    if (is_array($owners) && cache_get($key, 300) !== null) return false;

    $headers = ['X-API-TOKEN: ' . $api_token, 'Accept: application/json'];
    $first   = http_get(OAS_API . 'trainings?relevance=0&pg=1', $headers);
    if ($first['code'] === 401 || $first['code'] === 403) reply(401, ['error' => 'token']);
    if ($first['code'] !== 200) reply(502, ['error' => 'oas']);

    $owners = [];
    $pages  = [$first];
    $total  = intval($first['body']['meta']['pagination']['total_pages'] ?? 1);
    for ($p = 2; $p <= $total; $p += 10) {
        $urls = [];
        for ($q = $p; $q < $p + 10 && $q <= $total; $q++) $urls[$q] = OAS_API . 'trainings?relevance=0&pg=' . $q;
        $pages = array_merge($pages, array_values(http_get_many($urls, $headers)));
    }
    foreach ($pages as $page) {
        foreach ($page['body']['data'] ?? [] as $t) {
            if (!empty($t['id'])) $owners[$t['id']] = 1;
        }
    }
    cache_set($key, $owners);
    return isset($owners[$uid]);
}

if (!token_owns($api_token, $uid)) reply(403, ['error' => 'forbidden']);

// === 4. ZOHO ==============================================================
function zoho_token($config) {
    $cached = cache_get('zoho_token_cache', 3300);
    if (!empty($cached['access_token']) && ($cached['expires_at'] ?? 0) > time() + 300) return $cached['access_token'];

    $ch = curl_init('https://accounts.zoho.com/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'refresh_token' => $config['refresh_token'],
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['access_token'])) reply(502, ['error' => 'zoho_auth']);

    // Mismo archivo y formato que list-deals.php: comparten el token de acceso.
    cache_set('zoho_token_cache', ['access_token' => $res['access_token'], 'expires_at' => time() + 3600]);
    return $res['access_token'];
}

$zh = ['Authorization: Zoho-oauthtoken ' . zoho_token($config)];

// Una edición de otra formación nunca coincide: el filtro exige las dos cosas.
function criteria($uid, $stage, $edition) {
    return '((Training_UID:equals:' . $uid . ')'
         . ($edition !== '' ? 'and(Edition_UID:equals:' . $edition . ')' : '')
         . 'and(Stage:equals:' . $stage . '))';
}

// --- Números por etapa (5 conteos en paralelo, ~1 s) ---
if ($action === 'counts') {
    $ckey   = 'counts_' . $uid . ($edition !== '' ? '_' . $edition : '');
    $cached = cache_get($ckey, COUNTS_TTL);
    if (is_array($cached)) reply(200, $cached);

    $urls = [];
    foreach (STAGES as $st) $urls[$st] = ZOHO_API . 'Deals/actions/count?criteria=' . rawurlencode(criteria($uid, $st, $edition));
    $res = http_get_many($urls, $zh);

    $counts = [];
    foreach (STAGES as $st) {
        if ($res[$st]['code'] !== 200) reply(502, ['error' => 'zoho']);
        $counts[$st] = intval($res[$st]['body']['count'] ?? 0);
    }
    $out = ['training_uid' => $uid, 'edition_uid' => $edition, 'stages' => $counts, 'fetched_at' => time()];
    cache_set($ckey, $out);
    reply(200, $out);
}

// --- Contactos de una etapa (no se guardan en disco) ---
$deals     = [];
$truncated = false;
for ($page = 1; $page <= MAX_PAGES; $page++) {
    $url = ZOHO_API . 'Deals/search?criteria=' . rawurlencode(criteria($uid, $stage, $edition))
         . '&fields=Contact_Name,Created_Time&per_page=200&page=' . $page;
    $r = http_get($url, $zh);
    if ($r['code'] === 204) break; // sin resultados
    if ($r['code'] !== 200) reply(502, ['error' => 'zoho']);
    $deals = array_merge($deals, $r['body']['data'] ?? []);
    if (empty($r['body']['info']['more_records'])) break;
    if ($page === MAX_PAGES) $truncated = true;
}

// Fecha del negocio por contacto (el más reciente si hay más de uno).
$created = [];
foreach ($deals as $d) {
    $cid = $d['Contact_Name']['id'] ?? null;
    if (!$cid) continue;
    $t = $d['Created_Time'] ?? '';
    if (!isset($created[$cid]) || $t > $created[$cid]) $created[$cid] = $t;
}

$contacts = [];
$urls     = [];
foreach (array_chunk(array_keys($created), 100) as $i => $ids) {
    $urls[$i] = ZOHO_API . 'Contacts?ids=' . implode(',', $ids) . '&fields=First_Name,Last_Name,Full_Name,Email,Mobile,Phone,Country_Code';
}
foreach ($urls ? http_get_many($urls, $zh) : [] as $r) {
    if ($r['code'] !== 200) reply(502, ['error' => 'zoho']);
    foreach ($r['body']['data'] ?? [] as $c) {
        $contacts[] = [
            'first_name' => (string) ($c['First_Name'] ?? ''),
            'last_name'  => (string) ($c['Last_Name'] ?? ''),
            'name'       => (string) ($c['Full_Name'] ?? ''),
            'email'      => (string) ($c['Email'] ?? ''),
            'phone'      => (string) (($c['Mobile'] ?? '') !== '' ? $c['Mobile'] : ($c['Phone'] ?? '')),
            'country'    => (string) ($c['Country_Code'] ?? ''),
            'created'    => (string) ($created[$c['id']] ?? ''),
        ];
    }
}
usort($contacts, fn($a, $b) => strcmp($b['created'], $a['created']));

reply(200, ['training_uid' => $uid, 'stage' => $stage, 'contacts' => $contacts, 'truncated' => $truncated]);
