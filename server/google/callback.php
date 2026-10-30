<?php
/**
 * callback.php — intermediario de "Conectar con Google" para el plugin de OEC.
 *
 * Va en onlineeducation.center/connections/google/, junto a google-config.php
 * (el cliente OAuth de la app "Online Education Center" en Google Cloud). Es el
 * único lugar que conoce el secreto de la app: los sitios de los socios nunca lo
 * ven. No envía emails ni guarda tokens: solo hace los pasos de OAuth que
 * necesitan el secreto. El envío lo hace el sitio del socio, directo con la API
 * de Gmail, con un token de acceso de 1 hora.
 *
 *   GET  ?action=start&return=<admin-post.php del socio>&state=<del socio>
 *        → redirige a Google (permiso gmail.send + email de la cuenta).
 *   GET  ?code=…&state=…  (vuelta de Google: es la URI autorizada en la consola)
 *        → canjea el código, guarda el resultado 5 min bajo un código de un solo
 *          uso y vuelve al sitio del socio con ese código (nunca el token en la URL).
 *   POST action=exchange&code=…        → {email, refresh_token} (una sola vez).
 *   POST action=refresh&refresh_token=… → {access_token, expires_in}.
 */

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

const DATA_DIR   = __DIR__ . '/data';
const SCOPES     = 'openid email https://www.googleapis.com/auth/gmail.send';
const STATE_TTL  = 900;  // 15 min para completar el permiso en Google
const CODE_TTL   = 300;  // 5 min para que el sitio retire el resultado

function json_reply($code, $body) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail_page($msg) {
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Online Education Center</title><p style="font:16px sans-serif;max-width:520px;margin:60px auto">' . htmlspecialchars($msg) . '</p>';
    exit;
}

function b64url($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_dec($s) { return base64_decode(strtr($s, '-_', '+/')); }

function post_form($url, array $fields) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $body, true)];
}

/**
 * Solo se vuelve a un admin-post.php de WordPress, por https (http solo para
 * sitios locales de desarrollo). Así nadie puede usar esto para mandar a la gente
 * a otra página.
 */
function valid_return($url) {
    $p = parse_url((string) $url);
    if (empty($p['host']) || empty($p['scheme']) || !preg_match('#/wp-admin/admin-post\.php$#', $p['path'] ?? '')) return false;
    if ($p['scheme'] === 'https') return true;
    return $p['scheme'] === 'http' && (preg_match('/\.(local|test|localhost)$/', $p['host']) || $p['host'] === 'localhost');
}

function back_to($return, array $args) {
    header('Location: ' . $return . (strpos($return, '?') === false ? '?' : '&') . http_build_query($args), true, 302);
    exit;
}

$config = @include __DIR__ . '/google-config.php';
if (!is_array($config) || empty($config['client_id']) || empty($config['client_secret'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') json_reply(500, ['error' => 'config']);
    fail_page('Falta configurar la conexión con Google en el servidor de Online Education Center.');
}
$redirect_uri = 'https://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');
if (!empty($config['redirect_uri'])) $redirect_uri = $config['redirect_uri'];

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
if (!file_exists(DATA_DIR . '/.htaccess')) file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");

$sign = function ($payload) use ($config) { return b64url(hash_hmac('sha256', $payload, $config['client_secret'], true)); };

// === POST: los sitios de los socios, de servidor a servidor ================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'exchange') {
        $code = (string) ($_POST['code'] ?? '');
        if (!preg_match('/^[a-f0-9]{48}$/', $code)) json_reply(400, ['error' => 'code']);
        $file = DATA_DIR . '/' . $code . '.json';
        if (!file_exists($file)) json_reply(404, ['error' => 'expired']);
        $data = json_decode((string) file_get_contents($file), true);
        unlink($file); // un solo uso
        if (!$data || ($data['t'] ?? 0) < time() - CODE_TTL) json_reply(404, ['error' => 'expired']);
        json_reply(200, ['email' => $data['email'], 'refresh_token' => $data['refresh_token']]);
    }

    if ($action === 'refresh') {
        $rt = (string) ($_POST['refresh_token'] ?? '');
        if ($rt === '' || strlen($rt) > 512) json_reply(400, ['error' => 'refresh_token']);
        [$http, $res] = post_form('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $rt,
            'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
        ]);
        if ($http === 200 && !empty($res['access_token'])) {
            json_reply(200, ['access_token' => $res['access_token'], 'expires_in' => (int) ($res['expires_in'] ?? 3600)]);
        }
        // invalid_grant: el socio revocó el permiso (o, con la app en prueba, venció a los 7 días).
        json_reply($http >= 400 && $http < 500 ? 400 : 502, ['error' => $res['error'] ?? 'google']);
    }

    json_reply(400, ['error' => 'action']);
}

// === GET 1: el socio toca "Conectar con Google" ===========================
if (($_GET['action'] ?? '') === 'start') {
    $return = (string) ($_GET['return'] ?? '');
    $state  = (string) ($_GET['state'] ?? '');
    if (!valid_return($return) || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $state)) fail_page('Pedido inválido.');
    $payload = b64url(json_encode(['r' => $return, 's' => $state, 't' => time()]));
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'              => $config['client_id'],
        'redirect_uri'           => $redirect_uri,
        'response_type'          => 'code',
        'scope'                  => SCOPES,
        'access_type'            => 'offline',
        'prompt'                 => 'consent select_account', // siempre devuelve refresh_token y deja elegir la cuenta
        'include_granted_scopes' => 'true',
        'state'                  => $payload . '.' . $sign($payload),
    ]), true, 302);
    exit;
}

// === GET 2: vuelta de Google ==============================================
$parts = explode('.', (string) ($_GET['state'] ?? ''));
if (count($parts) !== 2 || !hash_equals($sign($parts[0]), $parts[1])) fail_page('Pedido inválido o vencido. Vuelve a tocar "Conectar con Google" en tu sitio.');
$st = json_decode((string) b64url_dec($parts[0]), true);
if (!$st || !valid_return($st['r'] ?? '') || ($st['t'] ?? 0) < time() - STATE_TTL) fail_page('Pasó demasiado tiempo. Vuelve a tocar "Conectar con Google" en tu sitio.');

if (!empty($_GET['error'])) back_to($st['r'], ['action' => 'oec_google_return', 'oec_google' => 'denied', 'state' => $st['s']]);

[$http, $tok] = post_form('https://oauth2.googleapis.com/token', [
    'grant_type' => 'authorization_code', 'code' => (string) ($_GET['code'] ?? ''), 'redirect_uri' => $redirect_uri,
    'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
]);
if ($http !== 200 || empty($tok['refresh_token'])) back_to($st['r'], ['action' => 'oec_google_return', 'oec_google' => 'error', 'state' => $st['s']]);

// Google deja destildar el permiso de enviar: sin él no sirve.
$scopes = explode(' ', (string) ($tok['scope'] ?? ''));
if (!in_array('https://www.googleapis.com/auth/gmail.send', $scopes, true)) back_to($st['r'], ['action' => 'oec_google_return', 'oec_google' => 'scope', 'state' => $st['s']]);

// El email de la cuenta, del id_token (vino directo de Google por TLS).
$idp   = explode('.', (string) ($tok['id_token'] ?? ''));
$claim = json_decode((string) b64url_dec($idp[1] ?? ''), true);
$email = (string) ($claim['email'] ?? '');

$code = bin2hex(random_bytes(24));
file_put_contents(DATA_DIR . '/' . $code . '.json', json_encode(['email' => $email, 'refresh_token' => $tok['refresh_token'], 't' => time()]), LOCK_EX);
chmod(DATA_DIR . '/' . $code . '.json', 0600);
// Limpieza de códigos que nadie retiró.
foreach (glob(DATA_DIR . '/*.json') ?: [] as $f) if (filemtime($f) < time() - CODE_TTL) @unlink($f);

back_to($st['r'], ['action' => 'oec_google_return', 'oec_google' => 'ok', 'code' => $code, 'state' => $st['s']]);
