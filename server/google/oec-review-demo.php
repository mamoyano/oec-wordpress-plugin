<?php
/**
 * Plugin Name: OEC — contactos de demostración (revisión de Google)
 * Description: SOLO para el sitio de prueba que usa el revisor de Google. Reemplaza las respuestas
 *              del endpoint de Zoho por contactos ficticios cuyas direcciones son casillas de OEC,
 *              así el revisor puede enviar emails de verdad sin escribirle a personas reales.
 *
 * Va en wp-content/mu-plugins/ del sitio de prueba. Solo actúa para el usuario del revisor
 * (OEC_REVIEW_USER, por defecto "google-review"): el resto de los administradores sigue viendo los
 * contactos reales de Zoho. En una red multisite se carga en todos los sitios, pero sin ese usuario
 * logueado no hace nada.
 *
 * Las direcciones salen de OEC_REVIEW_INBOX con "+alias" (Gmail y Google Workspace lo entregan en la
 * misma casilla): con "revision@onlineeducation.center" → "revision+ana@onlineeducation.center", etc.
 */
if (!defined('ABSPATH')) exit;

if (!defined('OEC_REVIEW_INBOX')) define('OEC_REVIEW_INBOX', 'mamoyano@onlineeducation.center');
if (!defined('OEC_REVIEW_USER')) define('OEC_REVIEW_USER', 'google-review');

function oec_review_demo_active() {
    return is_user_logged_in() && wp_get_current_user()->user_login === OEC_REVIEW_USER;
}

add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'connections/zoho/partner-contacts.php') === false || !oec_review_demo_active()) return $pre;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    [$user, $domain] = array_pad(explode('@', OEC_REVIEW_INBOX, 2), 2, 'example.com');
    $people = [
        ['Ana', 'García', 'AR', '5491155550101'], ['Bruno', 'López', 'MX', '5215555550102'],
        ['Carla', 'Martínez', 'CO', '573005550103'], ['Diego', 'Fernández', 'ES', '34655550104'],
        ['Elena', 'Rodríguez', 'CL', '56955550105'], ['Felipe', 'Gómez', 'UY', '59895550106'],
    ];
    // Cuántas personas hay en cada etapa (los números del embudo coinciden con la lista).
    $per_stage = ['Cold Prospects' => 6, 'More Info Request' => 4, 'Hot Prospects' => 3, 'Orders Not Finished' => 2, 'Students - Won' => 5, 'Lost' => 1];

    if (($q['action'] ?? '') === 'counts') {
        $body = ['training_uid' => $q['training_uid'] ?? '', 'edition_uid' => $q['edition_uid'] ?? '', 'stages' => $per_stage, 'fetched_at' => time()];
    } else {
        $n = $per_stage[$q['stage'] ?? ''] ?? 0;
        $contacts = [];
        foreach (array_slice($people, 0, $n) as $i => $p) {
            $contacts[] = [
                'first_name' => $p[0], 'last_name' => $p[1], 'name' => $p[0] . ' ' . $p[1],
                'email'      => $user . '+' . strtolower(remove_accents($p[0])) . '@' . $domain,
                'phone'      => $p[3], 'country' => $p[2],
                'created'    => gmdate('Y-m-d\TH:i:s+00:00', time() - ($i + 1) * DAY_IN_SECONDS),
            ];
        }
        $body = ['training_uid' => $q['training_uid'] ?? '', 'stage' => $q['stage'] ?? '', 'contacts' => $contacts, 'truncated' => false];
    }
    return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

// Los números del embudo se guardan 15 min en una opción compartida por todos los usuarios: para el
// revisor ni se leen ni se guardan, así no ve los reales ni deja los ficticios a los demás.
add_filter('pre_option', function ($pre, $option) {
    return (strpos($option, 'oec_stats_cache_z_') === 0 && oec_review_demo_active()) ? [] : $pre; // false no corta: sigue a la base
}, 10, 2);
add_filter('pre_update_option', function ($value, $option, $old) {
    return (strpos($option, 'oec_stats_cache_z_') === 0 && oec_review_demo_active()) ? $old : $value;
}, 10, 3);

// Aviso permanente en wp-admin para que nadie lo confunda con datos reales.
add_action('admin_notices', function () {
    if (!oec_review_demo_active()) return;
    echo '<div class="notice notice-info"><p><strong>Sitio de demostración:</strong> los contactos de Estadísticas son ficticios y sus emails llegan a casillas de prueba de Online Education Center. <em>Demo site: contacts are fictitious and their addresses are Online Education Center test inboxes.</em></p></div>';
});
