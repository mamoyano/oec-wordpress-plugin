<?php
class OEC_Shortcodes {
    private $twig;

    public function __construct() {
        $loader = new \Twig\Loader\ArrayLoader([]);
        $this->twig = new \Twig\Environment($loader, [
            'autoescape' => false,
            'debug' => true
        ]);

        // 1. FILTRO: format_date
        $this->twig->addFilter(new \Twig\TwigFilter('format_date', function ($date) {
            if (!$date) return '';

            if (is_numeric($date)) {
                $timestamp = (int) $date;
            } else {
                try {
                    $dt = new \DateTime($date);
                    $timestamp = $dt->getTimestamp();
                } catch (\Exception $e) {
                    $timestamp = strtotime($date);
                }
            }

            if (!$timestamp || $timestamp === -1) return '';

            $meses_en = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            $meses_es = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

            return str_replace($meses_en, $meses_es, date('d \d\e F \d\e Y', $timestamp));
        }));

        // 2. FILTRO: truncate
        $this->twig->addFilter(new \Twig\TwigFilter('truncate', function ($string, $limit = 150, $separator = '...') {
            $string = strip_tags($string);
            if (mb_strlen($string) <= $limit) return $string;

            $truncated  = mb_substr($string, 0, $limit);
            $last_space = mb_strrpos($truncated, ' ');

            if ($last_space !== false) {
                $truncated = mb_substr($truncated, 0, $last_space);
            }

            return $truncated . $separator;
        }));

        // 3. Registrar Shortcodes
        add_shortcode('oec-list', [$this, 'render_list']);
        add_shortcode('oec-content', [$this, 'render_content']);

        // 4. Asegurar que jQuery está disponible en el frontend.
        // WordPress ya incluye jQuery — esto lo activa si el tema no lo hizo.
        // Si el tema ya lo cargó, wp_enqueue_script lo detecta y no lo duplica.
        add_action('wp_enqueue_scripts', function() {
            wp_enqueue_script('jquery');
        });

        // Desactivar wptexturize para nuestros shortcodes.
        // wptexturize convierte " en " " y -- en – —, rompiendo la sintaxis Twig.
        add_filter('no_texturize_shortcodes', function($shortcodes) {
            $shortcodes[] = 'oec-list';
            $shortcodes[] = 'oec-content';
            return $shortcodes;
        });
    }

    public function render_list($atts, $content = null) {
        $atts = shortcode_atts([
            'pagination'        => 'no',
            'trainings-per-page' => 3
        ], $atts, 'oec-list');

        $endpoint     = "trainings?inc-reviews=1&relevance=0&pagination=" . ($atts['trainings-per-page']);
        $api_response = OEC_Api::call($endpoint);
        $brand_color  = get_option('oec_brand_color', '#a435f0');

        return $this->process_twig($content, [
            'data'       => $api_response,
            'show_pager' => ($atts['pagination'] === 'yes'),
            'extra'      => [
                'detail_url'  => get_site_url() . '/oec-formacion/',
                'brand_color' => $brand_color,
            ]
        ]);
    }

    public function render_content($atts, $content = null) {
        preg_match('/t-[a-zA-Z0-9]{14}/', $_SERVER['REQUEST_URI'], $matches);
        $id = !empty($matches[0]) ? $matches[0] : '';

        if (!$id) return "ID de formación no encontrado en la URL.";

        $data        = OEC_Api::call("trainings/" . $id);
        $full_url    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $current_url = strtok($full_url, '?');
        $brand_color = get_option('oec_brand_color', '#a435f0');

        $output = $this->process_twig($content, [
            'data'  => $data,
            'extra' => [
                'current_url'      => $current_url,
                'brand_color'      => $brand_color,
                'equipo_oec'       => get_option('oec_equipo_ventas', '1') === '1',
                'ventas_whatsapp'  => get_option('oec_ventas_whatsapp', ''),
                'ventas_email'     => get_option('oec_ventas_email', ''),
            ]
        ]);

        return $this->full_bleed_wrap($output);
    }

    /**
     * Envuelve el contenido en un div que ocupa el 100% del ancho de la ventana,
     * escapando cualquier contenedor limitado del tema activo.
     * Funciona con cualquier tema porque usa JavaScript para calcular el offset
     * real del elemento respecto al borde de la pantalla.
     */
    private function full_bleed_wrap($html) {
        $uid = 'oec-fb-' . substr(md5(microtime()), 0, 8);

        // Reemplazo de número de WhatsApp si el cliente usa su propio equipo de ventas
        $equipo_oec      = get_option('oec_equipo_ventas', '1');
        $ventas_whatsapp = get_option('oec_ventas_whatsapp', '');
        $wa_script = '';
        if ($equipo_oec === '0' && !empty($ventas_whatsapp)) {
            $wa_num = esc_js($ventas_whatsapp);
            $wa_script = <<<JS

<script>
(function() {
    var oec_wa = '5493512584960';
    var cte_wa = '{$wa_num}';

    function replaceWA(root) {
        // 1. href de links de WhatsApp
        (root.querySelectorAll ? root : document).querySelectorAll('a[href*="' + oec_wa + '"]').forEach(function(el) {
            el.href = el.href.replace(oec_wa, cte_wa);
        });
        // 2. Nodos de texto visibles
        var walker = document.createTreeWalker(
            root.querySelectorAll ? root : document.body,
            NodeFilter.SHOW_TEXT,
            null,
            false
        );
        var node;
        while ((node = walker.nextNode())) {
            if (node.nodeValue.indexOf(oec_wa) !== -1) {
                node.nodeValue = node.nodeValue.split(oec_wa).join(cte_wa);
            }
        }
    }

    // Ejecutar en el DOM inicial
    document.addEventListener('DOMContentLoaded', function() { replaceWA(document.body); });

    // Observar cambios dinámicos (lo que inyecta oec-trainings-v17.js después)
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) replaceWA(node);
            });
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // Detener el observer cuando la página ya terminó de cargar
    window.addEventListener('load', function() {
        // Último pase para asegurarse de no perder nada
        replaceWA(document.body);
        setTimeout(function() { observer.disconnect(); }, 3000);
    });
})();
</script>
JS;
        }

        return <<<HTML
<div id="{$uid}" style="position:relative;width:100%;overflow:visible;">
    <style>
        #oec-bleed-wrapper {
            position: relative;
            width: 100vw;
            left: 50%;
            transform: translateX(-50%);
            box-sizing: border-box;
            overflow: hidden;
        }
        /* Neutralizar overflow:hidden en body/html que algunos temas aplican */
        html, body { overflow-x: visible !important; }
        /* Ocultar el h1 del título de la página del tema */
        .wp-block-post-title { display: none !important; }
    </style>
    <div id="oec-bleed-wrapper">
        {$html}
    </div>
</div>{$wa_script}
HTML;
    }

    private function process_twig($template_string, $variables) {
        if (empty($template_string)) return '';

        try {
            // 1. Decodificar entidades HTML y caracteres especiales que mete WP
            $template_string = $this->full_html_decode($template_string);

            // 2. Renderizado Twig
            $template = $this->twig->createTemplate($template_string);
            return $template->render($variables);
        } catch (Exception $e) {
            return "Error en Twig: " . $e->getMessage();
        }
    }

    private function full_html_decode($string) {
        // wptexturize de WordPress convierte varios caracteres tipográficos.
        // Los revertimos ANTES de html_entity_decode para que strtr los encuentre.
        $fix = [
            // Smart quotes — entidades numéricas
            '&#8220;'  => '"',
            '&#8221;'  => '"',
            '&#8216;'  => "'",
            '&#8217;'  => "'",
            // Smart quotes — caracteres Unicode directos
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            // Guiones — wptexturize convierte -- y --- en estos
            '&#8211;'  => '-',   // en dash –
            '&#8212;'  => '-',   // em dash —
            "\u{2013}" => '-',   // – en dash Unicode
            "\u{2014}" => '-',   // — em dash Unicode
            // Puntos suspensivos
            '&#8230;'  => '...',
            "\u{2026}" => '...',
            // Otras entidades comunes
            '&#038;'   => '&',
            '&amp;'    => '&',
            '&nbsp;'   => ' ',
            "\xc2\xa0" => ' ',
        ];
        $string = strtr($string, $fix);

        // Decodificar el resto de entidades estándar (&gt; → > etc.)
        $string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');

        return $string;
    }
}