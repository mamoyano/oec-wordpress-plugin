<?php
/*
Plugin Name: Online Education Center for Wordpress
Description: Integración avanzada con OEC usando Twig.
Version: 1.4.3
Author: Online Education Center
*/

if (!defined('ABSPATH')) exit;

// ─────────────────────────────────────────────────────────────────
// ACTUALIZACIONES AUTOMÁTICAS VÍA GITHUB
// Requiere la librería Plugin Update Checker en vendor/plugin-update-checker/
// Repo: https://github.com/YahnisElsts/plugin-update-checker
// ─────────────────────────────────────────────────────────────────
$oec_puc_path = __DIR__ . '/vendor/plugin-update-checker/load-v5p6.php';
if (file_exists($oec_puc_path)) {
    require_once $oec_puc_path;
    $oec_updater = YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildUpdateChecker(
        'https://github.com/mamoyano/oec-wordpress-plugin/',  // ← repo de GitHub
        __FILE__,
        'oec-wordpress-plugin'
    );
    // Usar releases de GitHub (no el branch main)
    $oec_updater->getVcsApi()->enableReleaseAssets();
    unset($oec_puc_path, $oec_updater);
}

// ─────────────────────────────────────────────────────────────────
// ENTORNO: detectar si estamos en local o en producción
// En local, desactiva la caché para ver cambios al instante.
// ─────────────────────────────────────────────────────────────────
if (!defined('OEC_ENV')) {
    $oec_host = $_SERVER['HTTP_HOST'] ?? '';
    if (in_array($oec_host, ['localhost', '127.0.0.1'], true)
        || str_ends_with($oec_host, '.local')
        || str_contains($oec_host, 'dev.')
        || str_contains($oec_host, 'staging.')
    ) {
        define('OEC_ENV', 'local');
    } else {
        define('OEC_ENV', 'production');
    }
    unset($oec_host);
}

// En local: caché desactivada. En producción: activada.
define('OEC_CACHE_ENABLED', OEC_ENV === 'production');


// ─────────────────────────────────────────────────────────────────
// COMUNIDADES PROPIAS — sistema de créditos por descuentos
// Este plugin se instala tanto en nuestras propias comunidades como en
// sitios de socios que no administramos. El canje de créditos SOLO
// existe en las primeras: si el dominio actual no está en esta lista,
// todo el sistema queda inactivo (endpoints, UI y la API key de abajo
// nunca se usan), sin necesidad de configurar nada en wp-admin.
//
// Agregar acá cada comunidad nueva a medida que se activa.
// ─────────────────────────────────────────────────────────────────
if (!defined('OEC_CREDITS_ALLOWED_DOMAINS')) {
    define('OEC_CREDITS_ALLOWED_DOMAINS', [
        'g-se.com',
        'traumato.site',
        'fisio.one',
        'swimming.science',
        'is.fitness',
        'scalify.business',
        'nuevo.g-se.com', // staging en Cloudways
        'oec-test.local', // sitio de desarrollo local
    ]);
}

// Clave de la API de créditos. NO va en el código: el repo es público.
// Se resuelve en este orden (solo en los dominios de arriba):
//   1. OEC_CREDITS_API_KEY_DEFAULT en wp-config.php (nombre histórico).
//   2. La del tema OEC, si está activo: Ajustes OEC > Integraciones del
//      sitio de configuración de la red, o su constante OEC_CREDITS_API_KEY.
//   3. OEC_CREDITS_API_KEY en wp-config.php.
if (!function_exists('oec_credits_api_key_value')) {
    function oec_credits_api_key_value() {
        if (defined('OEC_CREDITS_API_KEY_DEFAULT')) {
            return OEC_CREDITS_API_KEY_DEFAULT;
        }
        if (function_exists('oec_credits_api_key')) {
            return oec_credits_api_key();
        }
        return defined('OEC_CREDITS_API_KEY') ? OEC_CREDITS_API_KEY : '';
    }
}

// Slug de la página de confirmación del canje (paso 2, [oec-confirm-redeem]).
// Se crea sola en las comunidades habilitadas — ver OEC_Admin::oec_create_pages().
if (!defined('OEC_REDEEM_CONFIRM_SLUG')) {
    define('OEC_REDEEM_CONFIRM_SLUG', 'confirmacion-de-canje-de-creditos');
}

if (!function_exists('oec_credits_system_enabled')) {
    function oec_credits_system_enabled() {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return in_array($host, OEC_CREDITS_ALLOWED_DOMAINS, true);
    }
}


// ─────────────────────────────────────────────────────────────────
// CHAT DIRECTO (BOTMAKER) — proyecto de OEC, hardcodeado
// El chat directo solo se ofrece cuando el contacto lo atiende el
// equipo comercial de OEC (toggle "¿Equipo de Ventas de OEC?" en
// wp-admin → OEC → Configuración, oec_equipo_ventas) — en ese caso es
// SIEMPRE este mismo proyecto de Botmaker, nunca uno distinto por
// sitio de socio, así que no tiene sentido pedirle a cada sitio que lo
// configure (a diferencia de oec_ventas_whatsapp/oec_ventas_email, que
// sí son propios de cada sitio y solo aplican cuando NO es el equipo
// de OEC el que atiende). Antes vivía en wp-admin como
// "Botmaker — ID de proyecto"; a pedido explícito de Mario se sacó de
// ahí y se hardcodeó acá.
// ─────────────────────────────────────────────────────────────────
// Endpoint en onlineeducation.center que da los números y contactos de Zoho por
// etapa (server/zoho/partner-contacts.php). Las claves de Zoho viven allá.
if (!defined('OEC_ZOHO_CONTACTS_ENDPOINT')) {
    define('OEC_ZOHO_CONTACTS_ENDPOINT', 'https://onlineeducation.center/connections/zoho/partner-contacts.php');
}
if (!defined('OEC_BOTMAKER_PROJECT_ID')) {
    define('OEC_BOTMAKER_PROJECT_ID', '6EJESDV1VQ');
}


// ─────────────────────────────────────────────────────────────────
// DEBUG DE PERFORMANCE — mide cuánto tarda cada llamada a la API de
// OEC y el render de Twig, y lo muestra en consola + un panel chico
// en pantalla. Se activa agregando ?oec_debug=1 a la URL (en cualquier
// sitio, local o no) — ya no se prende solo en local, para no dejarlo
// de fondo mientras se prueban otras cosas. Costo cero cuando está
// desactivado: oec_debug_time() directamente ejecuta el callback sin
// medir nada.
// ─────────────────────────────────────────────────────────────────
$GLOBALS['oec_debug_timings'] = [];

if (!function_exists('oec_debug_enabled')) {
    function oec_debug_enabled() {
        return isset($_GET['oec_debug']);
    }
}

if (!function_exists('oec_debug_record')) {
    function oec_debug_record($label, $seconds, $detail = '') {
        if (!oec_debug_enabled()) return;
        $GLOBALS['oec_debug_timings'][] = [
            'label'  => $label,
            'ms'     => round($seconds * 1000, 1),
            'detail' => $detail,
        ];
    }
}

if (!function_exists('oec_debug_time')) {
    function oec_debug_time($label, callable $fn, $detail_fn = null) {
        if (!oec_debug_enabled()) return $fn();
        $start  = microtime(true);
        $result = $fn();
        $detail = $detail_fn ? $detail_fn($result) : '';
        oec_debug_record($label, microtime(true) - $start, $detail);
        return $result;
    }
}

add_action('wp_footer', function () {
    if (!oec_debug_enabled()) return;

    $rows = $GLOBALS['oec_debug_timings'];
    $total_php = array_sum(array_column($rows, 'ms'));

    ?>
    <style>@media (max-width: 960px) { #oec-debug-panel { display: none !important; } }</style>
    <div id="oec-debug-panel" style="position:fixed;left:12px;bottom:12px;z-index:999999;max-width:380px;background:rgba(17,24,39,.94);color:#e5e7eb;font:11px/1.5 ui-monospace,Menlo,Consolas,monospace;padding:10px 12px;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.3);">
        <div style="font-weight:700;color:#fff;margin-bottom:4px;">⏱ OEC Debug — PHP: <?php echo esc_html($total_php); ?>ms</div>
        <?php foreach ($rows as $r): ?>
            <div style="display:flex;justify-content:space-between;gap:10px;">
                <span><?php echo esc_html($r['label']); ?><?php if ($r['detail']): ?> <span style="color:#9ca3af;">(<?php echo esc_html($r['detail']); ?>)</span><?php endif; ?></span>
                <span style="color:#fbbf24;flex-shrink:0;"><?php echo esc_html($r['ms']); ?>ms</span>
            </div>
        <?php endforeach; ?>
        <div style="color:#6b7280;margin-top:4px;">Detalle de carga real del navegador: ver consola.</div>
    </div>
    <script>
    (function(){
        var phpTimings = <?php echo wp_json_encode($rows); ?>;
        console.group('%c⏱ OEC Debug Timing', 'font-weight:bold;color:#2271b1;');
        console.log('PHP (server-side) — cada llamada a la API de OEC y el render de Twig:');
        console.table(phpTimings.map(function(r){ return {label:r.label, ms:r.ms, detail:r.detail}; }));

        window.addEventListener('load', function(){
            setTimeout(function(){
                var nav = performance.getEntriesByType('navigation')[0];
                if (!nav) { console.groupEnd(); return; }
                var browserTimings = [
                    {etapa:'DNS + conexión',            ms: Math.round(nav.connectEnd - nav.startTime)},
                    {etapa:'TTFB (server total: WP + plugin)', ms: Math.round(nav.responseStart - nav.requestStart)},
                    {etapa:'Descarga del HTML',          ms: Math.round(nav.responseEnd - nav.responseStart)},
                    {etapa:'DOM interactivo',            ms: Math.round(nav.domInteractive - nav.startTime)},
                    {etapa:'DOMContentLoaded',            ms: Math.round(nav.domContentLoadedEventEnd - nav.startTime)},
                    {etapa:'Load completo (con imágenes)', ms: Math.round(nav.loadEventEnd - nav.startTime)},
                ];
                console.log('Navegador (lo que realmente esperó el usuario):');
                console.table(browserTimings);
                console.log('%cTip: "TTFB" es el bloque que corresponde al PHP de arriba (WP + este plugin) — si el total PHP es mucho menor al TTFB, el resto lo está gastando otro plugin/el theme.', 'color:#9ca3af;font-style:italic;');
                console.groupEnd();
            }, 0);
        });
    })();
    </script>
    <?php
}, 999);


// ─────────────────────────────────────────────────────────────────
// AUTOLOADER MANUAL PARA TWIG (Sin Composer)
// ─────────────────────────────────────────────────────────────────
spl_autoload_register(function ($class) {
    $prefix   = 'Twig\\';
    $base_dir = __DIR__ . '/vendor/twig/src/';
    $len      = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) require $file;
});

// Cargar clases del plugin
require_once plugin_dir_path(__FILE__) . 'includes/class-oec-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-oec-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-oec-stats.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-oec-shortcodes.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-oec-ajax.php';


// ─────────────────────────────────────────────────────────────────
// 1. REGLAS DE REESCRITURA (URLs Amigables)
// ─────────────────────────────────────────────────────────────────
add_action('init', function () {
    add_rewrite_rule(
        '^formacion/.*(t-[a-zA-Z0-9]{14})/?$',
        'index.php?pagename=formacion',
        'top'
    );
});


// ─────────────────────────────────────────────────────────────────
// 2. SEO DINÁMICO
// ─────────────────────────────────────────────────────────────────
add_action('wp_head', 'oec_seo_and_stars_metadata', 5);

/**
 * URL de la miniatura de portada que usamos para calcular el color
 * dominante — vía imgrsize.oe-img.center (sistema propio, cacheado) en vez
 * de pegarle directo al archivo original. w=1200&q=89 (no un tamaño chico
 * ad-hoc como antes) porque ese es un tamaño que ya se pide seguido en la
 * plataforma — labura con caché caliente del lado de ellos, más rápido que
 * pedir un tamaño exclusivo nuestro que fuerza un resize al vuelo.
 */
if (!function_exists('oec_build_color_thumb_url')) {
    function oec_build_color_thumb_url($image) {
        $parts    = explode('/', $image);
        $filename = end($parts);
        return 'https://imgrsize.oe-img.center/campus/capacitacion/imagen/' . $filename . '?w=1200&q=89';
    }
}

/**
 * URL de portada para USO VISUAL (og:image/twitter:image, ver
 * oec_seo_and_stars_metadata() más abajo) — mismo criterio que
 * oec_build_color_thumb_url() (extraer el filename de la URL que manda la
 * API, que apunta directo a static1.onlineeducation.center, y pedirla vía
 * imgrsize.oe-img.center en su lugar) pero con &format=webp, igual que ya
 * hace el resto de la ficha (JSON-LD Course, hero, etc. en
 * page-formacion-testing.html) — no se reusa oec_build_color_thumb_url()
 * tal cual porque esa SÍ necesita el formato "de origen" para el análisis
 * de color de OEC_Api::compute_dominant_color_from_bytes().
 */
/**
 * ¿Este sitio es la comunidad dueña de la formación? Compara SOLO el dominio de
 * `data.community` (ej. "https://g-se.com") con el de este sitio (home_url()):
 * ignora protocolo, "www.", puerto y ruta, y acepta subdominios — así
 * "nuevo.g-se.com" o "www.g-se.com/es" cuentan como "g-se.com". Antes se buscaba
 * la URL de la comunidad como texto dentro de la URL de la página, y fallaba con
 * cualquier subdominio. Decide el link de inscripción (register_url vs register) y
 * lo que solo se muestra en la propia comunidad ("Organiza:", "¿Quién organiza…?").
 */
if (!function_exists('oec_is_community_site')) {
    function oec_is_community_site($community_url) {
        $host = function ($url) {
            $url = trim((string) $url);
            if ($url === '') return '';
            if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) $url = 'https://' . $url;
            $h = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            return preg_replace('/^www\./', '', rtrim($h, '.'));
        };
        $community = $host($community_url);
        $site      = $host(home_url());
        if ($community === '' || $site === '') return false;
        return $site === $community || substr($site, -strlen('.' . $community)) === '.' . $community;
    }
}

if (!function_exists('oec_build_display_image_url')) {
    function oec_build_display_image_url($image, $width = 1200, $quality = 89) {
        $parts    = explode('/', $image);
        $filename = end($parts);
        return 'https://imgrsize.oe-img.center/campus/capacitacion/imagen/' . $filename . '?w=' . $width . '&q=' . $quality . '&format=webp';
    }
}

/**
 * Trae TODO lo que necesita la página de una formación (datos, reviews,
 * resumen de opiniones, color dominante) en un solo lugar, memoizado por
 * request. wp_head (metadata SEO) es quien primero la llama — dispara el
 * batch en paralelo bien temprano — y el shortcode [oec-content], que
 * corre después, reutiliza este mismo resultado sin pedir nada de nuevo.
 *
 * El color dominante solo entra en el MISMO batch paralelo si la formación
 * ya estaba en caché (o sea, ya sabíamos el nombre de archivo de la imagen
 * antes de pedir nada) — si "training" también hay que pedirlo de cero, el
 * color se resuelve aparte, justo después, porque recién ahí conocemos la
 * URL de la imagen.
 */
if (!function_exists('oec_get_page_bundle')) {
    function oec_get_page_bundle() {
        static $bundle  = null;
        static $checked = false;
        if ($checked) return $bundle;
        $checked = true;

        preg_match('/t-[a-zA-Z0-9]{14}/', $_SERVER['REQUEST_URI'], $matches);
        $id = !empty($matches[0]) ? $matches[0] : '';

        $bundle = ['id' => $id, 'data' => null, 'reviews' => null, 'summary' => null, 'dominant_color' => null, 'dominant_color_css' => 'rgb(248,250,252)'];
        if (!$id) return $bundle;

        $use_cache = defined('OEC_CACHE_ENABLED') ? OEC_CACHE_ENABLED : true;
        $token     = get_option('oec_token');
        $now       = time();

        $key_training = 'oec_cache_' . md5("trainings/{$id}");
        $key_reviews  = 'oec_cache_' . md5("https://api.g-se.com/v2/content/trainings/{$id}/reviews?page=1");
        $key_summary  = 'oec_cache_' . md5("https://oas-api.onlineeducation.center/api-oas/v1/trainings/{$id}/reviews/summary");
        $key_parents  = 'oec_cache_' . md5("trainings/{$id}/parents");

        $cached_training = $use_cache ? get_option($key_training) : false;
        $cached_reviews  = $use_cache ? get_option($key_reviews)  : false;
        $cached_summary  = $use_cache ? get_option($key_summary)  : false;
        $cached_parents  = $use_cache ? get_option($key_parents)  : false;

        $data            = ($cached_training && $cached_training['expires'] > $now) ? $cached_training['data'] : null;
        $reviews_data    = ($cached_reviews  && $cached_reviews['expires']  > $now) ? $cached_reviews['data']  : null;
        $reviews_summary = ($cached_summary  && $cached_summary['expires']  > $now) ? $cached_summary['data']  : null;
        // "parents" puede legítimamente ser null (formación sin padres) — no
        // alcanza con "if ($cached_parents)", necesitamos saber si ya lo
        // resolvimos alguna vez o si todavía hay que pedirlo.
        $parents_resolved = ($cached_parents && $cached_parents['expires'] > $now);
        $parents           = $parents_resolved ? $cached_parents['data'] : null;

        // Si ya tenemos "data" (de caché), ya podemos calcular la URL de la
        // imagen y sumar el pedido del color dominante al mismo batch.
        $dominant_color  = null;
        $color_thumb_url = null;
        if ($data && !empty($data['image'])) {
            $color_thumb_url = oec_build_color_thumb_url($data['image']);
            $dominant_color  = OEC_Api::get_cached_dominant_color($color_thumb_url);
        }

        $requests = [];
        if (!$data)                               $requests['training'] = ['url' => 'https://oas-api.onlineeducation.center/api-oas/v1/trainings/' . $id, 'headers' => ['X-API-TOKEN' => $token, 'Accept' => 'application/json']];
        if (!$reviews_data)                       $requests['reviews']  = ['url' => 'https://api.g-se.com/v2/content/trainings/' . $id . '/reviews?page=1', 'headers' => ['Accept' => 'application/json']];
        if (!$reviews_summary)                    $requests['summary']  = ['url' => 'https://oas-api.onlineeducation.center/api-oas/v1/trainings/' . $id . '/reviews/summary', 'headers' => ['Accept' => 'application/json']];
        if ($color_thumb_url && !$dominant_color) $requests['color']    = ['url' => $color_thumb_url, 'headers' => ['Accept' => 'image/*']];
        // Pedido APARTE para "parents" — probamos meterlo como ?include=parents
        // en el pedido principal, pero la API devuelve una respuesta recortada
        // con eso (sin prices, modules, teachers, organization...). Va solo,
        // pero en el mismo batch en paralelo, así no le agrega tiempo extra.
        if (!$parents_resolved)                   $requests['parents']  = ['url' => 'https://oas-api.onlineeducation.center/api-oas/v1/trainings/' . $id . '?include=parents', 'headers' => ['X-API-TOKEN' => $token, 'Accept' => 'application/json']];

        // A prueba de caídas de la API (ver OEC_Api::TIMEOUT/DOWN_TTL): lo que falló
        // hace menos de 5 min no se vuelve a pedir, y se usa el último dato bueno
        // guardado aunque esté vencido. El color no entra (no es de la API de OEC).
        $req_keys = ['training' => $key_training, 'reviews' => $key_reviews, 'summary' => $key_summary, 'parents' => $key_parents];
        foreach ($req_keys as $rk => $ck) {
            if (isset($requests[$rk]) && OEC_Api::is_down($ck)) unset($requests[$rk]);
        }

        if (!empty($requests)) {
            $responses = oec_debug_time(
                'Batch en paralelo (' . implode('+', array_keys($requests)) . ')',
                function () use ($requests) {
                    return \WpOrg\Requests\Requests::request_multiple(
                        array_map(fn($r) => ['url' => $r['url'], 'headers' => $r['headers']], $requests),
                        ['timeout' => OEC_Api::TIMEOUT, 'connect_timeout' => 4]
                    );
                }
            );

            foreach ($req_keys as $rk => $ck) {
                if (!isset($requests[$rk])) continue;
                $resp = $responses[$rk] ?? null;
                if (!($resp instanceof \WpOrg\Requests\Response) || OEC_Api::failed($resp)) OEC_Api::mark_down($ck);
            }

            foreach ($responses as $key => $response) {
                if (!($response instanceof \WpOrg\Requests\Response) || !$response->success) continue;
                switch ($key) {
                    case 'training':
                        $decoded = json_decode($response->body, true);
                        $data    = isset($decoded['data']) ? $decoded['data'] : $decoded;
                        if (!empty($data)) update_option($key_training, ['data' => $data, 'expires' => $now + 86400], false);
                        break;
                    case 'reviews':
                        $reviews_data = json_decode($response->body, true) ?? [];
                        if (!empty($reviews_data)) update_option($key_reviews, ['data' => $reviews_data, 'expires' => $now + 21600], false);
                        break;
                    case 'summary':
                        $reviews_summary = json_decode($response->body, true) ?? [];
                        if (!empty($reviews_summary)) update_option($key_summary, ['data' => $reviews_summary, 'expires' => $now + 21600], false);
                        break;
                    case 'color':
                        $dominant_color = OEC_Api::compute_dominant_color_from_bytes($color_thumb_url, $response->body);
                        break;
                    case 'parents':
                        $decoded = json_decode($response->body, true);
                        $parents_payload = isset($decoded['data']) ? $decoded['data'] : $decoded;
                        $parents = $parents_payload['parents'] ?? null;
                        update_option($key_parents, ['data' => $parents, 'expires' => $now + 86400], false);
                        break;
                }
            }
        }

        // Lo que no se pudo conseguir (API caída): último dato bueno, aunque esté vencido.
        if (!$data)            $data            = OEC_Api::stale($key_training);
        if (!$reviews_data)    $reviews_data    = OEC_Api::stale($key_reviews);
        if (!$reviews_summary) $reviews_summary = OEC_Api::stale($key_summary);
        if (!$parents_resolved && $parents === null) $parents = OEC_Api::stale($key_parents);

        // "training" no estaba en caché al armar el batch de arriba, así que el
        // color dominante no se pudo pedir en paralelo con nada — se resuelve
        // acá solo, ahora que ya sabemos la URL de la imagen. Caso raro: pasa
        // solo si training y el color están fríos los dos a la vez.
        if (!$dominant_color && !$color_thumb_url && $data && !empty($data['image'])) {
            $color_thumb_url = oec_build_color_thumb_url($data['image']);
            $dominant_color  = oec_debug_time('Color dominante (no se pudo paralelizar, training estaba frío)', function () use ($color_thumb_url) {
                return OEC_Api::get_dominant_color($color_thumb_url);
            });
        }

        // "parents" viaja adentro de $data para que el Twig siga usando
        // data.parents tal cual, sin enterarse de que viene de un pedido aparte.
        if (is_array($data)) {
            $data['parents'] = $parents;
        }

        $bundle = [
            'id'                 => $id,
            'data'               => $data,
            'reviews'            => $reviews_data,
            'summary'            => $reviews_summary,
            'dominant_color'     => $dominant_color,
            'dominant_color_css' => $dominant_color ? "rgb({$dominant_color['r']},{$dominant_color['g']},{$dominant_color['b']})" : 'rgb(248,250,252)',
        ];
        return $bundle;
    }
}

if (!function_exists('oec_get_current_training_data')) {
    function oec_get_current_training_data() {
        return oec_get_page_bundle()['data'];
    }
}

if (!function_exists('oec_seo_and_stars_metadata')) {
    function oec_seo_and_stars_metadata() {
        if (!is_page('formacion')) return;
        $data = oec_get_current_training_data();
        if (!$data) return;

        // Nunca la URL cruda que manda la API (static1.onlineeducation.center) —
        // pasa por imgrsize.oe-img.center como el resto de la ficha (ver
        // oec_build_display_image_url()), así og:image/twitter:image se sirven
        // optimizadas (webp, tamaño acotado) igual que cualquier otra imagen
        // de esta página, en vez del archivo original sin procesar.
        $img       = !empty($data['image']) ? oec_build_display_image_url($data['image']) : '';
        $raw_desc  = !empty($data['short_description']) ? $data['short_description'] : $data['title'];
        // El HTML de origen suele venir como "...</p><p>..." sin espacio entre
        // medio — strip_tags a lo bruto pega las dos palabras ("VIVOÚnete").
        // Insertamos un espacio en los cierres de bloque ANTES de limpiar tags.
        $spaced_desc = preg_replace('/<\/(p|div|li|h[1-6])\s*>|<br\s*\/?>/i', ' ', $raw_desc);
        $clean_desc  = wp_strip_all_tags(strip_shortcodes($spaced_desc));
        $clean_desc  = preg_replace('/\s+/', ' ', trim($clean_desc));
        $desc        = esc_attr(wp_trim_words($clean_desc, 25, '...'));
        $title       = esc_html($data['title']);
        $url         = esc_url(!empty($data['canonical']) ? $data['canonical'] : oec_get_current_training_canonical());

        echo "\n\n";
        echo "<meta name='description' content='{$desc}'>\n";
        echo "<meta property='og:type' content='website'>\n";
        echo "<meta property='og:locale' content='es_ES'>\n";
        echo "<meta property='og:site_name' content='" . esc_attr(get_bloginfo('name')) . "'>\n";
        echo "<meta property='og:url' content='{$url}'>\n";
        echo "<meta property='og:title' content='{$title}'>\n";
        echo "<meta property='og:description' content='{$desc}'>\n";
        if ($img) {
            echo "<meta property='og:image' content='{$img}'>\n";
            echo "<meta property='og:image:secure_url' content='{$img}'>\n";
            echo "<meta name='twitter:image' content='{$img}'>\n";
        }
        echo "<meta name='twitter:card' content='summary_large_image'>\n";
        echo "<meta name='twitter:title' content='{$title}'>\n";
        echo "<meta name='twitter:description' content='{$desc}'>\n\n";

        oec_breadcrumb_jsonld($data, $url);
        oec_course_jsonld($data, oec_get_page_bundle()['summary'] ?? null);
    }
}

/**
 * Ítems del breadcrumb de la ficha (Inicio → Formaciones → nombre de la formación). UNA sola fuente
 * para el BreadcrumbList JSON-LD (acá abajo) y para el breadcrumb VISIBLE del hero (el Twig lo recibe
 * como extra.breadcrumb): Google pide que lo marcado se vea en la página y coincida.
 * - "Formaciones" solo aparece si el sitio tiene esa página (slug "formaciones"), con su título real;
 *   antes se armaba igual una URL "/formaciones" que en un sitio sin esa página daba 404.
 * - El último ítem (la página actual) va sin URL: Google lo permite, y así no apunta al canonical,
 *   que puede ser OTRA comunidad.
 * Devuelve [['name' => ..., 'url' => ... | null], ...].
 */
if (!function_exists('oec_training_breadcrumb_items')) {
    function oec_training_breadcrumb_items($data) {
        $items = [['name' => 'Inicio', 'url' => home_url('/')]];
        $formaciones_page = get_page_by_path('formaciones', OBJECT, 'page');
        if ($formaciones_page && $formaciones_page->post_status === 'publish') {
            $items[] = ['name' => get_the_title($formaciones_page), 'url' => get_permalink($formaciones_page)];
        }
        $items[] = ['name' => $data['title'] ?? ($data['name'] ?? ''), 'url' => null];
        return $items;
    }
}

if (!function_exists('oec_breadcrumb_jsonld')) {
    function oec_breadcrumb_jsonld($data, $current_url) {
        $items = [];
        foreach (oec_training_breadcrumb_items($data) as $i => $it) {
            $li = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $it['name']];
            if ($it['url']) $li['item'] = $it['url'];
            $items[] = $li;
        }

        $jsonld = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];

        echo "<script type='application/ld+json'>" . wp_json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n\n";
    }
}

/**
 * Texto plano de un campo HTML de la API ("</p><p>" sin espacio en el medio → se agrega uno antes
 * de sacar las etiquetas, para no pegar palabras), cortado en una palabra a $max caracteres.
 */
if (!function_exists('oec_jsonld_text')) {
    function oec_jsonld_text($html, $max = 0) {
        $text = preg_replace('/<\/(p|div|li|h[1-6])\s*>|<br\s*\/?>/i', ' ', (string) $html);
        $text = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($max && mb_strlen($text) > $max) {
            $cut  = mb_substr($text, 0, $max - 1);
            $sp   = mb_strrpos($cut, ' ');
            $text = rtrim($sp ? mb_substr($cut, 0, $sp) : $cut, " ,.;:-") . '…';
        }
        return $text;
    }
}

/**
 * JSON-LD Course de la ficha (en el <head>, con wp_json_encode). Antes vivía en el Twig armado a
 * mano; [oec-content] saca ese bloque viejo de las plantillas ya pegadas (render_content), así que
 * alcanza con actualizar el plugin en cada sitio.
 * Campos que Google pide para "Course info": name, description, provider, offers con category, y
 * hasCourseInstance con courseMode + courseWorkload (o courseSchedule). El resto (certificado,
 * programa por módulo, requisitos, qué se aprende, inscriptos) es lo que usan los asistentes de IA
 * para responder sobre la formación. Todo sale de datos que la ficha muestra.
 */
if (!function_exists('oec_course_jsonld')) {
    function oec_course_jsonld($data, $summary = null) {
        $url   = oec_get_current_training_canonical();
        $today = current_time('Y-m-d');
        // Las fechas de la API son días de calendario: la parte AAAA-MM-DD es el día (ver normalize_dates()).
        $day   = fn($v) => $v ? substr((string) $v, 0, 10) : '';
        $open  = $day($data['enrollment_end'] ?? '') >= $today;
        $sync  = in_array($data['synchronicity'] ?? '', ['SYNC', 'MIXED'], true);
        $mod   = (string) ($data['modality'] ?? '');
        $mode  = $mod === 'ONLINE' ? 'online' : (strpos($mod, 'BLEND') !== false ? 'blended' : 'onsite');
        $hours = (int) ($data['lecture_hours'] ?? 0);

        $in_community = function_exists('oec_is_community_site') && oec_is_community_site($data['community'] ?? '');
        $enroll_url   = $in_community ? ($data['register_url'] ?? '') : ($data['register'] ?? '');
        $price        = (float) ($data['prices']['total'] ?? 0);

        $instructors = [];
        foreach ($data['teachers']['data'] ?? [] as $t) {
            $name = trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''));
            if ($name === '') continue;
            $instructors[] = array_filter([
                '@type'       => 'Person',
                'name'        => $name,
                'description' => oec_jsonld_text($t['origin'] ?? '', 200) ?: null,
            ]);
        }

        $credentials = [];
        foreach ($data['certificates']['data'] ?? [] as $c) {
            if (empty($c['name'])) continue;
            $credentials[] = array_filter([
                '@type'              => 'EducationalOccupationalCredential',
                'name'               => $c['name'],
                'credentialCategory' => 'certificate',
                'recognizedBy'       => !empty($c['organization_name']) ? ['@type' => 'Organization', 'name' => $c['organization_name']] : null,
            ]);
        }

        $syllabus = [];
        foreach ($data['modules']['data'] ?? [] as $i => $m) {
            $subjects = array_filter(array_map(fn($a) => trim((string) ($a['name'] ?? '')), $m['subjects']['data'] ?? []));
            $syllabus[] = array_filter([
                '@type'        => 'Syllabus',
                'name'         => 'Módulo ' . ($m['number'] ?? ($i + 1)),
                'description'  => $subjects ? oec_jsonld_text(implode('. ', $subjects), 500) : null,
                'timeRequired' => !empty($m['lecture_hours']) ? 'PT' . (int) $m['lecture_hours'] . 'H' : null,
            ]);
        }

        $offer = null;
        if (empty($data['force_contact'])) {
            $offer = array_filter([
                '@type'         => 'Offer',
                'category'      => $price > 0 ? 'Paid' : 'Free',
                'price'         => $price,
                'priceCurrency' => $data['prices']['currency'] ?? null,
                // Inscripción cerrada: OutOfStock en vez de omitir la oferta ("sin cupo por ahora").
                'availability'  => $open ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'validThrough'  => $open ? ($day($data['enrollment_end'] ?? '') ?: null) : null,
                'url'           => $open && $enroll_url ? $enroll_url : $url,
            ], fn($v) => $v !== null && $v !== '');
        }

        $count  = (int) ($summary['count'] ?? 0);
        $course = array_filter([
            '@context'                     => 'https://schema.org',
            '@type'                        => 'Course',
            '@id'                          => $url . '#course',
            'name'                         => $data['name'] ?? ($data['title'] ?? ''),
            'description'                  => oec_jsonld_text($data['short_description'] ?? '', 500) ?: ($data['title'] ?? null),
            'url'                          => $url,
            'image'                        => !empty($data['image']) ? oec_build_display_image_url($data['image']) : null,
            'inLanguage'                   => 'es',
            'provider'                     => array_filter([
                '@type' => 'Organization',
                'name'  => $data['organization']['data']['name'] ?? null,
                'url'   => $data['community'] ?? null,
            ]),
            'teaches'                      => oec_jsonld_text($data['objetives'] ?? '', 600) ?: null,
            'coursePrerequisites'          => oec_jsonld_text($data['requirements'] ?? '', 400) ?: null,
            'educationalCredentialAwarded' => $credentials ?: null,
            'totalHistoricalEnrollment'    => (int) ($data['total_students'] ?? 0) ?: null,
            'syllabusSections'             => $syllabus ?: null,
            'aggregateRating'              => $count > 0 ? [
                '@type'       => 'AggregateRating',
                'ratingValue' => round((float) ($summary['average'] ?? 0), 2),
                'reviewCount' => $count,
                'bestRating'  => 5,
                'worstRating' => 1,
            ] : null,
            'offers'                       => $offer ?: null,
            'hasCourseInstance'            => array_filter([
                '@type'          => 'CourseInstance',
                'courseMode'     => $mode,
                // "Horas cátedra" de la API, tal cual las muestra la ficha.
                'courseWorkload' => $hours ? 'PT' . $hours . 'H' : null,
                'startDate'      => $sync ? ($day($data['start'] ?? '') ?: null) : null,
                'endDate'        => $sync ? ($day($data['end'] ?? '') ?: null) : null,
                'instructor'     => $instructors ?: null,
            ]),
        ], fn($v) => $v !== null && $v !== '' && $v !== []);

        echo "<script type='application/ld+json'>" . wp_json_encode($course, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . "</script>\n\n";
    }
}

/**
 * URL canónica de la formación actual. Prioriza data.canonical (lo manda
 * la API con este nombre para justamente este uso — normalmente apunta a
 * la comunidad "dueña" de la formación) y si por lo que sea no viene, arma
 * una a partir del propio sitio como respaldo.
 */
if (!function_exists('oec_get_current_training_canonical')) {
    function oec_get_current_training_canonical() {
        $data = oec_get_current_training_data();
        if (!$data) return home_url('/formacion');
        if (!empty($data['canonical'])) return $data['canonical'];
        $slug = $data['slug'] ?? $data['id'] ?? '';
        return $slug ? home_url('/formacion/' . $slug . '/') : home_url('/formacion');
    }
}


// ─────────────────────────────────────────────────────────────────
// 3. COMPATIBILIDAD CON TÍTULOS SEO
// ─────────────────────────────────────────────────────────────────
add_filter('pre_get_document_title', 'oec_fix_seo_title', 999);
add_filter('wpseo_title',            'oec_fix_seo_title', 999);
add_filter('rank_math/frontend/title', 'oec_fix_seo_title', 999);

if (!function_exists('oec_fix_seo_title')) {
    function oec_fix_seo_title($title) {
        if (is_page('formacion')) {
            $data = oec_get_current_training_data();
            if ($data && isset($data['title'])) {
                return $data['title'] . " | " . get_bloginfo('name');
            }
        }
        return $title;
    }
}


// ─────────────────────────────────────────────────────────────────
// 3b. CANONICAL CORRECTO POR FORMACIÓN
// Sin esto, WordPress usa el permalink de la página "Formación" tal cual
// (siempre el mismo, sin el ?id=) como canonical de TODAS las formaciones
// — le dice a Google que son todas la misma página. Esto se lleva bien
// con Yoast/RankMath si están instalados (se filtran sus propios hooks).
// ─────────────────────────────────────────────────────────────────
add_filter('get_canonical_url',        'oec_fix_canonical_url', 999, 2);
add_filter('wpseo_canonical',          'oec_fix_canonical', 999);
add_filter('rank_math/frontend/canonical', 'oec_fix_canonical', 999);

if (!function_exists('oec_fix_canonical_url')) {
    function oec_fix_canonical_url($canonical_url, $post) {
        return oec_fix_canonical($canonical_url);
    }
}

if (!function_exists('oec_fix_canonical')) {
    function oec_fix_canonical($canonical) {
        if (is_page('formacion')) {
            $data = oec_get_current_training_data();
            if ($data) {
                return oec_get_current_training_canonical();
            }
        }
        return $canonical;
    }
}


// ─────────────────────────────────────────────────────────────────
// 3c. SCHEMA DE SITIO (Organization + WebSite) — en TODAS las páginas
// Da contexto de marca (nombre, logo, home) independiente de la formación
// puntual que se esté viendo. Usa solo datos nativos de WordPress
// (bloginfo, site icon) para que funcione igual en cualquier sitio donde
// se instale el plugin, sin configuración extra.
// ─────────────────────────────────────────────────────────────────
add_action('wp_head', 'oec_site_jsonld', 5);

if (!function_exists('oec_site_jsonld')) {
    function oec_site_jsonld() {
        if (is_admin()) return;

        $logo = get_site_icon_url();
        $org  = array_filter([
            '@type' => 'Organization',
            'name'  => get_bloginfo('name'),
            'url'   => home_url('/'),
            'logo'  => $logo ?: null,
        ]);

        $website = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                $org,
                [
                    '@type' => 'WebSite',
                    'name'  => get_bloginfo('name'),
                    'url'   => home_url('/'),
                ],
            ],
        ];

        echo "<script type='application/ld+json'>" . wp_json_encode($website, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n\n";
    }
}


// ─────────────────────────────────────────────────────────────────
// 3d. AEO/GEO — crawlers de IA y llms.txt
// Permitir explícitamente a los crawlers/agentes de IA más comunes
// (además de lo que ya permita el robots.txt por defecto de WordPress) y
// exponer un llms.txt básico. Vía filtro/rewrite, no archivos físicos —
// así funciona en cualquier sitio donde se instale el plugin, sin que
// haya que subir nada a mano en cada uno.
// ─────────────────────────────────────────────────────────────────
add_filter('robots_txt', 'oec_allow_ai_crawlers', 10, 2);

if (!function_exists('oec_allow_ai_crawlers')) {
    function oec_allow_ai_crawlers($output, $public) {
        if ((int) $public !== 1) return $output; // sitio marcado como no público: no tocamos nada

        $ai_agents = [
            'GPTBot', 'ChatGPT-User', 'OAI-SearchBot',
            'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai',
            'PerplexityBot', 'Perplexity-User',
            'Google-Extended', 'CCBot', 'Applebot-Extended',
        ];

        $output .= "\n# Crawlers/agentes de IA — permitidos explícitamente para citación (AEO)\n";
        foreach ($ai_agents as $agent) {
            $output .= "User-agent: {$agent}\nAllow: /\n\n";
        }
        $output .= "# llms.txt: " . home_url('/llms.txt') . "\n";

        return $output;
    }
}

add_action('init', function () {
    add_rewrite_rule('^llms\.txt$', 'index.php?oec_llms_txt=1', 'top');
    add_rewrite_tag('%oec_llms_txt%', '1');
});

add_action('template_redirect', function () {
    if (get_query_var('oec_llms_txt') != 1) return;

    $formaciones_page = get_page_by_path('formaciones', OBJECT, 'page');
    $formaciones_url  = $formaciones_page ? get_permalink($formaciones_page) : home_url('/formaciones');
    $tagline          = get_bloginfo('description');

    header('Content-Type: text/plain; charset=utf-8');
    echo "# " . get_bloginfo('name') . "\n\n";
    if ($tagline) echo "> " . $tagline . "\n\n";
    echo "Sitio de formación online. El listado completo de cursos disponibles está en:\n";
    echo "- [Formaciones](" . esc_url($formaciones_url) . "): listado de todos los cursos disponibles, con su descripción, docentes, fechas y precios.\n";

    // Landings de temática (ej. /especiales/nutricion-deportiva) — páginas
    // hijas de cualquier página con slug "especiales", si existe. Listado
    // dinámico (no hardcodeado) para que cada landing nueva que se arme
    // con este mismo patrón aparezca acá sola, sin tener que acordarse de
    // tocar este archivo cada vez.
    $especiales_page = get_page_by_path('especiales', OBJECT, 'page');
    if ($especiales_page) {
        $landings = get_pages(['parent' => $especiales_page->ID, 'sort_column' => 'post_title']);
        if (!empty($landings)) {
            echo "\nLandings por temática, con formaciones curadas, docentes destacados y opiniones reales:\n";
            foreach ($landings as $landing) {
                $excerpt = has_excerpt($landing) ? get_the_excerpt($landing) : '';
                echo "- [" . esc_html(get_the_title($landing)) . "](" . esc_url(get_permalink($landing)) . ")" . ($excerpt ? ": " . esc_html($excerpt) : "") . "\n";
            }
        }
    }
    exit;
});


// ─────────────────────────────────────────────────────────────────
// 3e. PROTECCIÓN CONTRA CORRUPCIÓN DE GUTENBERG
// El Twig de [oec-content] usa "<" ">" "{" "}" sueltos
// (ej. "{% if x > y %}") que no son HTML válido. El editor de bloques,
// al ABRIR una página para editar (no hace falta ni guardar), valida
// cada bloque "HTML personalizado" re-parseando su contenido guardado
// con el motor de HTML del propio navegador para compararlo contra lo
// que el bloque "debería" verse — y ese re-parseo interpreta "{% if
// opt.selected %}" como si fueran atributos sueltos de la etiqueta,
// destrozándolo. Pasa aunque el bloque ya sea texto plano (no hace
// falta ni tocar el contenido, ni usar un editor enriquecido) — el daño
// ocurre en la VALIDACIÓN del bloque, no al escribir. La única forma
// real de evitarlo es que Gutenberg nunca llegue a parsear esa página:
// para cualquier post que use alguno de nuestros shortcodes, forzamos
// el editor clásico (sigue siendo una caja de texto plano, misma
// experiencia de "pegar código") y le sacamos la pestaña "Visual" (con
// TinyMCE rompería todavía más). No requiere instalar ningún plugin
// aparte — "use_block_editor_for_post" es el filtro nativo de
// WordPress para esto.
//
// Además, como red de seguridad por si el contenido llega corrompido
// por otra vía (un editor externo, una importación, etc.), un segundo
// filtro detecta la firma típica de esta corrupción
// (`{%=""`/`%}=""`, que jamás aparece en Twig válido) y bloquea el
// guardado en vez de dejar pasar una página rota en silencio.
//
// Aparte, y sin relación con lo de arriba: WordPress también le aplica
// wpautop() al contenido al MOSTRARLO (no al guardarlo) — envuelve en
// <p> cada salto de línea en blanco y convierte los simples en <br>.
// Normalmente esto se evita marcando el contenido como bloque
// "core/html" (el comentario "<!-- wp:html -->"), que hace que
// do_blocks() saque wpautop solo — pero es un mecanismo frágil: alcanza
// con que alguien borre ese comentario (nos pasó a nosotros mismos) para
// que vuelva a romperse. Acá lo sacamos de forma explícita para
// cualquier página con nuestros shortcodes, sin depender de que ese
// comentario exista o no.
// ─────────────────────────────────────────────────────────────────
if (!function_exists('oec_post_uses_shortcodes')) {
    function oec_post_uses_shortcodes($content) {
        if (empty($content)) return false;
        // Solo la ficha: es el único shortcode con cuerpo Twig. [oec-list] arma su propio
        // HTML (una línea, sin cuerpo), así que no necesita editor clásico ni sacar wpautop
        // — y forzarlo le cambiaría el editor a cualquier página de un sitio de socio que
        // solo quiera mostrar unas pocas formaciones.
        return has_shortcode($content, 'oec-content');
    }
}

add_filter('use_block_editor_for_post', function ($use_block_editor, $post) {
    if ($post && oec_post_uses_shortcodes($post->post_content)) {
        return false;
    }
    return $use_block_editor;
}, 10, 2);

// Sin el editor de bloques, WordPress cae solo en la pantalla clásica de
// post.php — ahí "user_can_richedit" decide si se muestran las pestañas
// Visual/Texto (TinyMCE) o directo la caja de texto plano. La sacamos del
// todo para estos posts: no hay forma de que alguien la abra por error.
add_filter('user_can_richedit', function ($default) {
    if (!is_admin()) return $default;
    global $post;
    if ($post && oec_post_uses_shortcodes($post->post_content)) {
        return false;
    }
    return $default;
});

add_filter('wp_insert_post_data', function ($data, $postarr) {
    if (empty($data['post_content']) || !oec_post_uses_shortcodes($data['post_content'])) {
        return $data;
    }
    if (!preg_match('/\{%=""|%\}=""/', $data['post_content'])) {
        return $data;
    }
    // Firma de corrupción detectada — no lo dejamos pasar. Si es una
    // actualización, mantenemos la versión anterior (buena) en vez de
    // pisarla con la rota.
    if (!empty($postarr['ID'])) {
        $existing = get_post($postarr['ID']);
        if ($existing) {
            $data['post_content'] = $existing->post_content;
        }
    }
    set_transient('oec_corruption_warning_' . get_current_user_id(), true, 60);
    return $data;
}, 10, 2);

add_action('admin_notices', function () {
    $key = 'oec_corruption_warning_' . get_current_user_id();
    if (!get_transient($key)) return;
    delete_transient($key);
    echo '<div class="notice notice-error"><p><strong>OEC:</strong> se detectó contenido corrompido en el guardado (típico de haber editado el shortcode desde el editor de bloques de WordPress) y no se guardó — se mantuvo la versión anterior. Volvé a intentar desde el editor de texto plano.</p></div>';
});

// wpautop() envuelve en <p> cada línea en blanco del contenido al
// MOSTRARLO (no al guardarlo) — con 800 líneas de CSS/JS/Twig crudo,
// el resultado es una sopa de <p> intercalados que rompe todo
// visualmente. Normalmente WordPress evita esto solo cuando detecta un
// comentario de bloque "<!-- wp:... -->" en el contenido (do_blocks()
// saca wpautop del filtro the_content al vuelo) — pero es un mecanismo
// frágil: alcanza con que ese comentario se borre (nos pasó a nosotros
// mismos al limpiarlo del contenido) para que wpautop vuelva a actuar.
// Lo sacamos de forma explícita e incondicional para cualquier página
// con nuestros shortcodes, sin depender de ningún comentario mágico.
add_filter('the_content', function ($content) {
    global $post;
    if ($post && oec_post_uses_shortcodes($post->post_content)) {
        remove_filter('the_content', 'wpautop');
    }
    return $content;
}, 8); // antes de wpautop (prioridad 10 por default)


// ─────────────────────────────────────────────────────────────────
// 4. INICIALIZACIÓN DE CLASES
// ─────────────────────────────────────────────────────────────────
if (is_admin()) {
    new OEC_Admin();
    OEC_Stats::init();
}

new OEC_Shortcodes();
new OEC_Ajax();
