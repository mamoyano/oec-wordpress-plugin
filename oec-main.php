<?php
/*
Plugin Name: Online Education Center for Wordpress
Description: Integración avanzada con OEC usando Twig.
Version: 1.1
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
require_once plugin_dir_path(__FILE__) . 'includes/class-oec-shortcodes.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-oec-ajax.php';


// ─────────────────────────────────────────────────────────────────
// 1. REGLAS DE REESCRITURA (URLs Amigables)
// ─────────────────────────────────────────────────────────────────
add_action('init', function () {
    add_rewrite_rule(
        '^oec-formacion/.*(t-[a-zA-Z0-9]{14})/?$',
        'index.php?pagename=oec-formacion',
        'top'
    );
});


// ─────────────────────────────────────────────────────────────────
// 2. SEO DINÁMICO
// ─────────────────────────────────────────────────────────────────
add_action('wp_head', 'oec_seo_and_stars_metadata', 5);

function oec_seo_and_stars_metadata() {
    if (!is_page('oec-formacion')) return;

    preg_match('/t-[a-zA-Z0-9]{14}/', $_SERVER['REQUEST_URI'], $matches);
    $id = !empty($matches[0]) ? $matches[0] : '';

    if ($id) {
        $data = OEC_Api::call("trainings/" . $id);
        if (!$data) return;

        $img       = !empty($data['image']) ? $data['image'] : '';
        $raw_desc  = !empty($data['short_description']) ? $data['short_description'] : $data['title'];
        $clean_desc = wp_strip_all_tags(strip_shortcodes($raw_desc));
        $desc      = esc_attr(wp_trim_words($clean_desc, 25, '...'));
        $title     = esc_html($data['title']);

        echo "\n\n";
        echo "<meta name='description' content='{$desc}'>\n";
        echo "<meta property='og:title' content='{$title}'>\n";
        echo "<meta property='og:description' content='{$desc}'>\n";

        if ($img) {
            echo "<meta property='og:image' content='{$img}'>\n";
            echo "<meta property='og:image:secure_url' content='{$img}'>\n";
            echo "<meta name='twitter:image' content='{$img}'>\n";
        }
        echo "<meta name='twitter:card' content='summary_large_image'>\n";
        echo "\n";
    }
}


// ─────────────────────────────────────────────────────────────────
// 3. COMPATIBILIDAD CON TÍTULOS SEO
// ─────────────────────────────────────────────────────────────────
add_filter('pre_get_document_title', 'oec_fix_seo_title', 999);
add_filter('wpseo_title',            'oec_fix_seo_title', 999);
add_filter('rank_math/frontend/title', 'oec_fix_seo_title', 999);

function oec_fix_seo_title($title) {
    if (is_page('oec-formacion')) {
        preg_match('/t-[a-zA-Z0-9]{14}/', $_SERVER['REQUEST_URI'], $matches);
        $id = !empty($matches[0]) ? $matches[0] : '';
        if ($id) {
            $data = OEC_Api::call("trainings/" . $id);
            if ($data && isset($data['title'])) {
                return $data['title'] . " | " . get_bloginfo('name');
            }
        }
    }
    return $title;
}


// ─────────────────────────────────────────────────────────────────
// 4. INICIALIZACIÓN DE CLASES
// ─────────────────────────────────────────────────────────────────
if (is_admin()) {
    new OEC_Admin();
}

new OEC_Shortcodes();
new OEC_Ajax();